#requires -Version 5.1
<#
.SYNOPSIS
    Non-destructive E2E verification for the raffles-service extraction.

.DESCRIPTION
    Checks the catalog, stops only raffles-service, verifies the controlled
    gateway failure and that auth/orders/webhooks still reach the monolith,
    then starts the service again.

    It never stops mysql, app, scheduler or nginx and never writes application
    data. It does briefly make the public catalog unavailable, as required by
    the stop/start acceptance test.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-raffles-service.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$StopTimeoutSeconds = 30,
    [int]$StartTimeoutSeconds = 90
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Failures = New-Object System.Collections.Generic.List[string]
$Warnings = New-Object System.Collections.Generic.List[string]

function Add-Failure {
    param([string]$Message)
    $Failures.Add($Message) | Out-Null
    Write-Host "FAIL  $Message" -ForegroundColor Red
}

function Add-Warning {
    param([string]$Message)
    $Warnings.Add($Message) | Out-Null
    Write-Host "WARN  $Message" -ForegroundColor Yellow
}

function Check {
    param([bool]$Condition, [string]$Message)
    if ($Condition) {
        Write-Host "PASS  $Message" -ForegroundColor Green
    } else {
        Add-Failure $Message
    }
}

function Invoke-Http {
    param(
        [string]$Method = 'GET',
        [string]$Uri,
        [string]$Body = $null,
        [hashtable]$Headers = @{}
    )

    $curlArgs = @('-s', '-w', "`n%{http_code}", '-X', $Method, $Uri)

    foreach ($name in $Headers.Keys) {
        $curlArgs += @('-H', "$name`: $($Headers[$name])")
    }

    if (-not [string]::IsNullOrEmpty($Body)) {
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', $Body)
    }

    $output = @(& curl.exe @curlArgs 2>$null)
    $status = 0
    $content = ''

    if ($output.Count -gt 0) {
        $statusText = [string]$output[-1]
        [void][int]::TryParse($statusText.Trim(), [ref]$status)

        if ($output.Count -gt 1) {
            $content = ($output[0..($output.Count - 2)] -join "`n")
        }
    }

    return [pscustomobject]@{
        Status = $status
        Body = $content
    }
}

function Wait-ForStatus {
    param(
        [string]$Uri,
        [int[]]$Expected,
        [int]$TimeoutSeconds,
        [string]$Method = 'GET',
        [string]$Body = $null
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $last = $null

    do {
        $last = Invoke-Http -Method $Method -Uri $Uri -Body $Body

        if ($Expected -contains $last.Status) {
            return $last
        }

        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)

    return $last
}

function Get-JsonBody {
    param([string]$Body)

    try {
        return $Body | ConvertFrom-Json
    } catch {
        return $null
    }
}

Push-Location $RepoRoot

try {
    Write-Host "`n== 1. Catalogo disponible ==" -ForegroundColor Cyan
    $catalogBefore = Wait-ForStatus -Uri "$BaseUrl/api/raffles" -Expected @(200) -TimeoutSeconds 30
    Check ($catalogBefore.Status -eq 200) 'GET /api/raffles responde 200 antes de detener el servicio'
    $catalogJsonBefore = Get-JsonBody $catalogBefore.Body
    Check ($null -ne $catalogJsonBefore -and $null -ne $catalogJsonBefore.data) 'El catalogo devuelve la envoltura JSON data/links/meta'

    $raffleId = $null
    $catalogItemsBefore = @()

    if ($null -ne $catalogJsonBefore -and $null -ne $catalogJsonBefore.data) {
        $catalogItemsBefore = @($catalogJsonBefore.data)
    }

    if ($catalogItemsBefore.Count -gt 0) {
        $raffleId = [int]$catalogItemsBefore[0].id
        $detailBefore = Invoke-Http -Method GET -Uri "$BaseUrl/api/raffles/$raffleId"
        Check ($detailBefore.Status -eq 200) "GET /api/raffles/$raffleId responde 200"
        $detailJsonBefore = Get-JsonBody $detailBefore.Body
        Check ($null -ne $detailJsonBefore -and $detailJsonBefore.data.id -eq $raffleId) 'El detalle conserva el contrato data.id'

        $numbersBefore = Invoke-Http -Method GET -Uri "$BaseUrl/api/raffles/$raffleId/numbers?page=1&per_page=100"
        Check ($numbersBefore.Status -eq 200) "GET /api/raffles/$raffleId/numbers responde 200"
        $numbersJsonBefore = Get-JsonBody $numbersBefore.Body
        Check ($null -ne $numbersJsonBefore -and $null -ne $numbersJsonBefore.meta.counts) 'La disponibilidad conserva meta.counts'
    } else {
        Add-Warning 'El catalogo esta vacio: se validan contratos pero no el detalle de una rifa concreta.'
    }

    $jsonHeaders = @{ Accept = 'application/json' }

    $ordersBefore = Invoke-Http -Method GET -Uri "$BaseUrl/api/orders/1" -Headers $jsonHeaders
    Check ($ordersBefore.Status -eq 401) 'GET /api/orders/1 sin sesion responde 401 (monolito)'
    $webhookBefore = Invoke-Http -Method POST -Uri "$BaseUrl/api/webhooks/mercadopago" -Body '{}' -Headers $jsonHeaders
    Check ($webhookBefore.Status -eq 400) 'POST /api/webhooks/mercadopago con payload vacio responde 400 (monolito)'

    Write-Host "`n== 2. Detener solo raffles-service ==" -ForegroundColor Cyan
    & docker compose stop raffles-service | Out-Null
    Check ($LASTEXITCODE -eq 0) 'docker compose stop raffles-service termino correctamente'

    $catalogDown = Wait-ForStatus -Uri "$BaseUrl/api/raffles" -Expected @(502, 503) -TimeoutSeconds $StopTimeoutSeconds
    Check (($catalogDown.Status -eq 502) -or ($catalogDown.Status -eq 503)) 'El catalogo no esta disponible con 502/503 controlado por el gateway'

    if ($catalogDown.Status -eq 502) {
        Add-Warning 'El gateway devolvio 502 en lugar del 503 controlado; revisar error_page de nginx.'
    }

    if ($catalogDown.Status -eq 503) {
        $downJson = Get-JsonBody $catalogDown.Body
        Check ($null -ne $downJson -and $null -ne $downJson.message) 'La caida devuelve un JSON controlado, no una pagina HTML'
    }

    $loginDown = Invoke-Http -Method POST -Uri "$BaseUrl/api/login" -Body '{"email":"nobody@example.invalid","password":"wrong"}' -Headers $jsonHeaders
    Check ($loginDown.Status -eq 422) 'POST /api/login sigue respondiendo 422 con el servicio de rifas detenido'

    $meDown = Invoke-Http -Method GET -Uri "$BaseUrl/api/me" -Headers $jsonHeaders
    Check ($meDown.Status -eq 401) 'GET /api/me sigue respondiendo 401 con el servicio de rifas detenido'

    $ordersDown = Invoke-Http -Method GET -Uri "$BaseUrl/api/orders/1" -Headers $jsonHeaders
    Check ($ordersDown.Status -eq 401) 'GET /api/orders/1 sigue respondiendo 401 con el servicio de rifas detenido'

    $webhookDown = Invoke-Http -Method POST -Uri "$BaseUrl/api/webhooks/mercadopago" -Body '{}' -Headers $jsonHeaders
    Check ($webhookDown.Status -eq 400) 'POST /api/webhooks/mercadopago sigue respondiendo 400 con el servicio de rifas detenido'

    Write-Host "`n== 3. Restablecer raffles-service ==" -ForegroundColor Cyan
    & docker compose start raffles-service | Out-Null
    Check ($LASTEXITCODE -eq 0) 'docker compose start raffles-service termino correctamente'

    $catalogAfter = Wait-ForStatus -Uri "$BaseUrl/api/raffles" -Expected @(200) -TimeoutSeconds $StartTimeoutSeconds
    Check ($catalogAfter.Status -eq 200) 'El catalogo vuelve a responder 200 tras reiniciar el servicio'
    $catalogJsonAfter = Get-JsonBody $catalogAfter.Body
    Check ($null -ne $catalogJsonAfter -and $null -ne $catalogJsonAfter.data) 'El catalogo recuperado conserva la envoltura JSON'

    if ($null -ne $raffleId) {
        $detailAfter = Invoke-Http -Method GET -Uri "$BaseUrl/api/raffles/$raffleId"
        Check ($detailAfter.Status -eq 200) "GET /api/raffles/$raffleId vuelve a responder 200"
        $numbersAfter = Invoke-Http -Method GET -Uri "$BaseUrl/api/raffles/$raffleId/numbers?page=1&per_page=100"
        Check ($numbersAfter.Status -eq 200) "GET /api/raffles/$raffleId/numbers vuelve a responder 200"

        $numbersJsonAfter = Get-JsonBody $numbersAfter.Body

        if ($null -ne $numbersJsonBefore -and $null -ne $numbersJsonAfter) {
            Check (
                ($numbersJsonBefore.meta.counts.available -eq $numbersJsonAfter.meta.counts.available) -and
                ($numbersJsonBefore.meta.counts.reserved -eq $numbersJsonAfter.meta.counts.reserved) -and
                ($numbersJsonBefore.meta.counts.sold -eq $numbersJsonAfter.meta.counts.sold)
            ) 'Los conteos de numeros no cambiaron durante la prueba (servicio de solo lectura)'
        }
    }

    $ordersAfter = Invoke-Http -Method GET -Uri "$BaseUrl/api/orders/1" -Headers $jsonHeaders
    Check ($ordersAfter.Status -eq 401) 'GET /api/orders/1 conserva su comportamiento tras el reinicio'
    $webhookAfter = Invoke-Http -Method POST -Uri "$BaseUrl/api/webhooks/mercadopago" -Body '{}' -Headers $jsonHeaders
    Check ($webhookAfter.Status -eq 400) 'POST /api/webhooks/mercadopago conserva su comportamiento tras el reinicio'
} finally {
    # Never leave the catalog stopped because a check failed.
    & docker compose start raffles-service | Out-Null
    Pop-Location
}

Write-Host "`n== Resumen ==" -ForegroundColor Cyan
Write-Host ("Fallos: {0}  Advertencias: {1}" -f $Failures.Count, $Warnings.Count)

if ($Failures.Count -gt 0) {
    foreach ($failure in $Failures) {
        Write-Host " - $failure" -ForegroundColor Red
    }

    exit 1
}

Write-Host 'Verificacion E2E de raffles-service: OK' -ForegroundColor Green
exit 0
