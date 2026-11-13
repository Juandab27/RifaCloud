#requires -Version 5.1
<#
.SYNOPSIS
    Verifica el entorno demo sin escribir datos.

.DESCRIPTION
    Lee docs/testing/.local/test-users.json, inicia sesión con los tres
    usuarios y comprueba por HTTP: roles, catálogo, estados, historial,
    aislamiento entre compradores y métricas del dashboard.

    No crea órdenes, no ejecuta migraciones y no modifica rifacloud.
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost'
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$CredentialsFile = Join-Path $RepoRoot 'docs/testing/.local/test-users.json'
$Results = New-Object System.Collections.Generic.List[object]
$Token = [guid]::NewGuid().ToString('N').Substring(0, 8)
$AdminJar = Join-Path $env:TEMP "demo-admin-$Token.txt"
$Buyer1Jar = Join-Path $env:TEMP "demo-buyer1-$Token.txt"
$Buyer2Jar = Join-Path $env:TEMP "demo-buyer2-$Token.txt"

function Add-Result {
    param([string]$Name, [string]$Expected, [string]$Actual, [bool]$Pass)

    $Results.Add([pscustomobject]@{ Name = $Name; Expected = $Expected; Actual = $Actual; Pass = $Pass }) | Out-Null

    if ($Pass) {
        Write-Host "PASS  $Name" -ForegroundColor Green
    } else {
        Write-Host "FAIL  $Name (expected $Expected, got $Actual)" -ForegroundColor Red
    }
}

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$Body = $null,
        [string]$Jar = '',
        [string]$XsrfToken = ''
    )

    $bodyOut = Join-Path $env:TEMP ("demo-body-$([guid]::NewGuid().ToString('N')).json")
    $curlArgs = @('-s', '-o', $bodyOut, '-w', '%{http_code}', '-X', $Method, '-H', 'Accept: application/json')

    if ($Jar -ne '' -or $XsrfToken -ne '') {
        $curlArgs += @('-H', "Origin: $BaseUrl")
    }

    if ($Jar -ne '') {
        $curlArgs += @('-c', $Jar, '-b', $Jar)
    }

    if ($XsrfToken -ne '') {
        $curlArgs += @('-H', "X-XSRF-TOKEN: $XsrfToken")
    }

    if ($null -ne $Body) {
        $bodyFile = Join-Path $env:TEMP ("demo-payload-$([guid]::NewGuid().ToString('N')).json")
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [IO.File]::WriteAllText($bodyFile, $Body, $utf8NoBom)
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
    }

    $curlArgs += ($BaseUrl + $Path)
    $rawStatus = & curl.exe @curlArgs 2>$null
    $content = ''

    if (Test-Path $bodyOut) {
        $content = Get-Content $bodyOut -Raw
        Remove-Item $bodyOut -Force -ErrorAction SilentlyContinue
    }

    if ($null -ne $Body -and (Test-Path $bodyFile)) {
        Remove-Item $bodyFile -Force -ErrorAction SilentlyContinue
    }

    $status = 0
    [void][int]::TryParse(([string]$rawStatus).Trim(), [ref]$status)

    return [pscustomobject]@{ Status = $status; Body = $content }
}

function Get-CookieValue {
    param([string]$Jar, [string]$Name)

    if (-not (Test-Path $Jar)) { return '' }

    $line = Get-Content $Jar | Where-Object { $_ -match "`t$Name`t" } | Select-Object -Last 1

    if (-not $line) { return '' }

    return [uri]::UnescapeDataString((($line -split "`t")[-1]))
}

function Get-Json {
    param([string]$Body)

    if ([string]::IsNullOrWhiteSpace($Body)) { return $null }

    try { return $Body | ConvertFrom-Json } catch { return $null }
}

function Login {
    param([string]$Email, [string]$Password, [string]$Jar)

    Remove-Item $Jar -Force -ErrorAction SilentlyContinue
    Invoke-Api -Method GET -Path '/sanctum/csrf-cookie' -Jar $Jar | Out-Null
    $xsrf = Get-CookieValue -Jar $Jar -Name 'XSRF-TOKEN'
    $body = @{ email = $Email; password = $Password } | ConvertTo-Json -Compress

    return Invoke-Api -Method POST -Path '/api/login' -Body $body -Jar $Jar -XsrfToken $xsrf
}

if (-not (Test-Path $CredentialsFile)) {
    Write-Host "No existe $CredentialsFile. Ejecuta primero scripts/prepare-demo-environment.ps1." -ForegroundColor Red
    exit 1
}

$credentials = Get-Content $CredentialsFile -Raw | ConvertFrom-Json
$admin = @($credentials.users) | Where-Object { $_.role -eq 'ADMIN' } | Select-Object -First 1
$buyer1 = @($credentials.users) | Where-Object { $_.email -eq 'demo-buyer1@rifacloud.test' } | Select-Object -First 1
$buyer2 = @($credentials.users) | Where-Object { $_.email -eq 'demo-buyer2@rifacloud.test' } | Select-Object -First 1

if ($null -eq $admin -or $null -eq $buyer1 -or $null -eq $buyer2) {
    Write-Host 'El archivo de credenciales está incompleto.' -ForegroundColor Red
    exit 1
}

$adminLogin = Login -Email $admin.email -Password $admin.password -Jar $AdminJar
$buyer1Login = Login -Email $buyer1.email -Password $buyer1.password -Jar $Buyer1Jar
$buyer2Login = Login -Email $buyer2.email -Password $buyer2.password -Jar $Buyer2Jar

Add-Result 'ADMIN login' '200' "$($adminLogin.Status)" ($adminLogin.Status -eq 200)
Add-Result 'Comprador 1 login' '200' "$($buyer1Login.Status)" ($buyer1Login.Status -eq 200)
Add-Result 'Comprador 2 login' '200' "$($buyer2Login.Status)" ($buyer2Login.Status -eq 200)

$me = Get-Json (Invoke-Api -Path '/api/me' -Jar $Buyer1Jar).Body
Add-Result 'Comprador 1 tiene rol USER' 'USER' "$($me.data.role)" ($me.data.role -eq 'USER')
$adminMe = Get-Json (Invoke-Api -Path '/api/me' -Jar $AdminJar).Body
Add-Result 'ADMIN tiene rol ADMIN' 'ADMIN' "$($adminMe.data.role)" ($adminMe.data.role -eq 'ADMIN')

$catalogResponse = Invoke-Api -Path '/api/raffles'
$catalog = Get-Json $catalogResponse.Body
$titles = @($catalog.data | ForEach-Object { $_.title })
Add-Result 'Catálogo responde 200' '200' "$($catalogResponse.Status)" ($catalogResponse.Status -eq 200)
Add-Result 'Catálogo muestra la rifa disponible' 'true' "$($titles -contains '[DEMO] Rifa disponible con números libres')" ($titles -contains '[DEMO] Rifa disponible con números libres')
Add-Result 'Catálogo muestra la rifa próximamente' 'true' "$($titles -contains '[DEMO] Rifa próximamente')" ($titles -contains '[DEMO] Rifa próximamente')
Add-Result 'Catálogo muestra la rifa con ventana terminada' 'true' "$($titles -contains '[DEMO] Rifa con ventana de venta terminada')" ($titles -contains '[DEMO] Rifa con ventana de venta terminada')
Add-Result 'Catálogo muestra la rifa finalizada' 'true' "$($titles -contains '[DEMO] Rifa finalizada con resultado y evidencia')" ($titles -contains '[DEMO] Rifa finalizada con resultado y evidencia')
Add-Result 'Catálogo no expone la rifa CLOSED' 'false' "$($titles -contains '[DEMO] Rifa cerrada con ventas para publicar resultado')" (-not ($titles -contains '[DEMO] Rifa cerrada con ventas para publicar resultado'))

$openRaffle = @($catalog.data) | Where-Object { $_.title -eq '[DEMO] Rifa disponible con números libres' } | Select-Object -First 1

if ($null -ne $openRaffle) {
    $numbersResponse = Invoke-Api -Path "/api/raffles/$($openRaffle.id)/numbers?per_page=100"
    $numbers = Get-Json $numbersResponse.Body
    Add-Result 'Números de la rifa disponible' '200' "$($numbersResponse.Status)" ($numbersResponse.Status -eq 200)
    $reserved = @($numbers.data | Where-Object { $_.status -eq 'RESERVED' }).Count
    $available = @($numbers.data | Where-Object { $_.status -eq 'AVAILABLE' }).Count
    Add-Result 'La rifa disponible conserva números libres' '>0' "$available" ($available -gt 0)
    Add-Result 'La rifa disponible tiene reservas demo' '>0' "$reserved" ($reserved -gt 0)
}

$buyer1OrdersResponse = Invoke-Api -Path '/api/orders?per_page=50' -Jar $Buyer1Jar
$buyer1Orders = Get-Json $buyer1OrdersResponse.Body
$buyer1Numbers = @($buyer1Orders.data | ForEach-Object { $_.order_number })
Add-Result 'Historial del comprador 1' '200' "$($buyer1OrdersResponse.Status)" ($buyer1OrdersResponse.Status -eq 200)
Add-Result 'Comprador 1 ve su compra aprobada' 'true' "$($buyer1Numbers -contains 'DEMO-PAID-CLOSED')" ($buyer1Numbers -contains 'DEMO-PAID-CLOSED')
Add-Result 'Comprador 1 ve su reserva pendiente' 'true' "$($buyer1Numbers -contains 'DEMO-PENDING-B1')" ($buyer1Numbers -contains 'DEMO-PENDING-B1')
Add-Result 'Comprador 1 ve su reserva expirada' 'true' "$($buyer1Numbers -contains 'DEMO-EXPIRED-B1')" ($buyer1Numbers -contains 'DEMO-EXPIRED-B1')
Add-Result 'Comprador 1 ve su pago rechazado' 'true' "$($buyer1Numbers -contains 'DEMO-REJECTED-B1')" ($buyer1Numbers -contains 'DEMO-REJECTED-B1')
Add-Result 'Comprador 1 ve su incidencia' 'true' "$($buyer1Numbers -contains 'DEMO-REVIEW-B1')" ($buyer1Numbers -contains 'DEMO-REVIEW-B1')

$buyer2OrdersResponse = Invoke-Api -Path '/api/orders?per_page=50' -Jar $Buyer2Jar
$buyer2Orders = Get-Json $buyer2OrdersResponse.Body
$buyer2Numbers = @($buyer2Orders.data | ForEach-Object { $_.order_number })
Add-Result 'Historial del comprador 2' '200' "$($buyer2OrdersResponse.Status)" ($buyer2OrdersResponse.Status -eq 200)
Add-Result 'Comprador 2 ve solo su reserva' 'true' "$($buyer2Numbers -contains 'DEMO-PENDING-B2')" ($buyer2Numbers -contains 'DEMO-PENDING-B2')
Add-Result 'Comprador 2 no ve órdenes del comprador 1' 'false' "$($buyer2Numbers -contains 'DEMO-PAID-CLOSED')" (-not ($buyer2Numbers -contains 'DEMO-PAID-CLOSED'))

$buyer1OrderId = @($buyer1Orders.data) | Where-Object { $_.order_number -eq 'DEMO-PAID-CLOSED' } | Select-Object -First 1
if ($null -ne $buyer1OrderId) {
    $foreignDetail = Invoke-Api -Path "/api/orders/$($buyer1OrderId.id)" -Jar $Buyer2Jar
    Add-Result 'Comprador 2 no puede leer el detalle ajeno' '404' "$($foreignDetail.Status)" ($foreignDetail.Status -eq 404)
}

$adminRaffles = Invoke-Api -Path '/api/admin/raffles/metrics' -Jar $AdminJar -XsrfToken (Get-CookieValue -Jar $AdminJar -Name 'XSRF-TOKEN')
$adminOrders = Invoke-Api -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken (Get-CookieValue -Jar $AdminJar -Name 'XSRF-TOKEN')
$adminIncidents = Invoke-Api -Path '/api/admin/payments/incidents/metrics' -Jar $AdminJar -XsrfToken (Get-CookieValue -Jar $AdminJar -Name 'XSRF-TOKEN')
$raffleMetrics = Get-Json $adminRaffles.Body
$orderMetrics = Get-Json $adminOrders.Body
$incidentMetrics = Get-Json $adminIncidents.Body

Add-Result 'Dashboard: rifas publicadas' '3' "$($raffleMetrics.data.published_raffles)" ([int]$raffleMetrics.data.published_raffles -eq 3)
Add-Result 'Dashboard: órdenes pagadas' '2' "$($orderMetrics.data.paid_orders)" ([int]$orderMetrics.data.paid_orders -eq 2)
Add-Result 'Dashboard: números vendidos' '5' "$($orderMetrics.data.sold_numbers)" ([int]$orderMetrics.data.sold_numbers -eq 5)
Add-Result 'Dashboard: incidencias pendientes' '1' "$($incidentMetrics.data.pending_incidents)" ([int]$incidentMetrics.data.pending_incidents -eq 1)

Remove-Item $AdminJar, $Buyer1Jar, $Buyer2Jar -Force -ErrorAction SilentlyContinue

$failed = @($Results | Where-Object { -not $_.Pass }).Count
Write-Host ''
Write-Host "Checks: $($Results.Count - $failed) passed, $failed failed" -ForegroundColor $(if ($failed -eq 0) { 'Green' } else { 'Red' })

if ($failed -gt 0) { exit 1 }
