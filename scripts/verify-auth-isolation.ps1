#requires -Version 5.1
<#
.SYNOPSIS
    Failure-isolation test for auth-service behind Nginx.

.DESCRIPTION
    Stops auth-service only, verifies that auth routes return a controlled JSON
    503 while the catalog and the monolith (orders/admin) keep working, then
    starts auth-service again and verifies that routing is restored.

    No data is written and no database/volume is removed.
#>

# Docker writes progress to stderr; PowerShell 5.1 would treat that as a
# terminating error under 'Stop'. Assertions below verify the real outcomes.
$ErrorActionPreference = 'Continue'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$base = 'http://localhost'
$results = New-Object System.Collections.Generic.List[object]

function Add-Result {
    param(
        [string]$Name,
        [string]$Expected,
        [string]$Actual,
        [bool]$Pass
    )

    $results.Add([pscustomobject]@{
        Name     = $Name
        Expected = $Expected
        Actual   = $Actual
        Pass     = $Pass
    })
}

function Get-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$Body = ''
    )

    $bodyOut = Join-Path $env:TEMP ("auth-iso-body-$([guid]::NewGuid().ToString('N')).json")
    $curlArgs = @(
        '-s', '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-H', 'Accept: application/json',
        '-H', "Origin: $base"
    )

    if ($Body -ne '') {
        $bodyFile = Join-Path $env:TEMP ("auth-iso-request-$([guid]::NewGuid().ToString('N')).json")
        Set-Content -NoNewline -Path $bodyFile -Value $Body
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
    }

    $curlArgs += ($base + $Path)

    $rawStatus = & curl.exe @curlArgs
    $responseBody = ''
    if (Test-Path $bodyOut) {
        $responseBody = Get-Content $bodyOut -Raw
        Remove-Item $bodyOut -Force
    }

    if ($Body -ne '' -and $bodyFile) {
        Remove-Item $bodyFile -Force -ErrorAction SilentlyContinue
    }

    return [pscustomobject]@{
        Status = [int]$rawStatus
        Body   = $responseBody
    }
}

function Wait-AuthHealthy {
    param([int]$Attempts = 60)

    for ($i = 0; $i -lt $Attempts; $i++) {
        Start-Sleep -Seconds 1
        $state = (& docker inspect --format '{{.State.Health.Status}}' rifacloud-auth-service 2>$null)

        if ($state -eq 'healthy') {
            return $true
        }
    }

    return $false
}

try {
    $health = Get-Api -Path '/api/auth/health'
    Add-Result 'auth healthy before stop' '200' "$($health.Status)" ($health.Status -eq 200)

    $catalog = Get-Api -Path '/api/raffles'
    Add-Result 'catalog before stop' '200' "$($catalog.Status)" ($catalog.Status -eq 200)

    & docker compose stop auth-service *> $null
    Start-Sleep -Seconds 4

    $healthDown = Get-Api -Path '/api/auth/health'
    Add-Result 'auth health while stopped' '503' "$($healthDown.Status)" ($healthDown.Status -eq 503)
    $jsonDown = $healthDown.Body -match 'autenticacion'
    Add-Result 'auth outage returns JSON message' 'contains autenticacion' $(if ($jsonDown) { 'contains autenticacion' } else { $healthDown.Body.Trim() }) $jsonDown

    $loginDown = Get-Api -Method POST -Path '/api/login' -Body '{"email":"nobody@example.invalid","password":"wrong"}'
    Add-Result 'login while stopped' '503' "$($loginDown.Status)" ($loginDown.Status -eq 503)

    $meDown = Get-Api -Path '/api/me'
    Add-Result '/api/me while stopped' '503' "$($meDown.Status)" ($meDown.Status -eq 503)

    $csrfDown = Get-Api -Path '/sanctum/csrf-cookie'
    Add-Result 'CSRF bootstrap while stopped' '503' "$($csrfDown.Status)" ($csrfDown.Status -eq 503)

    $catalogDuring = Get-Api -Path '/api/raffles'
    Add-Result 'catalog while auth stopped' '200' "$($catalogDuring.Status)" ($catalogDuring.Status -eq 200)

    $adminDuring = Get-Api -Path '/api/admin/raffles'
    Add-Result 'monolith admin route while auth stopped' '401' "$($adminDuring.Status)" ($adminDuring.Status -eq 401)

    $ordersDuring = Get-Api -Method POST -Path '/api/orders' -Body '{}'
    Add-Result 'monolith orders route while auth stopped' 'not 503' "$($ordersDuring.Status)" ($ordersDuring.Status -ne 503)
}
finally {
    & docker compose start auth-service *> $null
    $healthy = Wait-AuthHealthy
    Add-Result 'auth healthy after restart' 'healthy' $(if ($healthy) { 'healthy' } else { 'not healthy' }) $healthy
}

$healthUp = Get-Api -Path '/api/auth/health'
Add-Result 'auth health after restart' '200' "$($healthUp.Status)" ($healthUp.Status -eq 200)

$catalogUp = Get-Api -Path '/api/raffles'
Add-Result 'catalog after restart' '200' "$($catalogUp.Status)" ($catalogUp.Status -eq 200)

$meUp = Get-Api -Path '/api/me'
Add-Result '/api/me after restart' '401' "$($meUp.Status)" ($meUp.Status -eq 401)

$results | Format-Table -AutoSize
$failed = @($results | Where-Object { -not $_.Pass })

Write-Output ""
Write-Output ("PASS={0} FAIL={1}" -f ($results.Count - $failed.Count), $failed.Count)

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
