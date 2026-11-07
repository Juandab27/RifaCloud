#requires -Version 5.1
<#
.SYNOPSIS
    S10.3 admin dashboard end-to-end verification against rifacloud_test.

.DESCRIPTION
    Recreates the stack on the fully migrated test schema, inserts isolated
    fixtures with known totals and verifies through Nginx:

      1. Anonymous metrics -> 401.
      2. USER metrics -> 403.
      3. ADMIN metrics -> 200 with the exact published/paid/sold/incident counts.
      4. A stopped owner service returns a controlled JSON 503 for its metric
         while the other two metrics keep working.
      5. The normal stack is restored in `finally`.

    The main `rifacloud` database is never touched and no migration is run.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-admin-dashboard.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$StartTimeoutSeconds = 120
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Results = New-Object System.Collections.Generic.List[object]
$Compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
$Token = [guid]::NewGuid().ToString('N').Substring(0, 10)
$AdminEmail = "dashboard-e2e-a$Token@example.invalid"
$UserEmail = "dashboard-e2e-u$Token@example.invalid"
$AdminPassword = "Dashboard-e2e-admin-$Token!"
$UserPassword = "Dashboard-e2e-user-$Token!"
$AdminJar = Join-Path $env:TEMP "dashboard-admin-$Token.txt"
$UserJar = Join-Path $env:TEMP "dashboard-user-$Token.txt"
$Fixture = $null

function Add-Result {
    param([string]$Name, [string]$Expected, [string]$Actual, [bool]$Pass)

    $Results.Add([pscustomobject]@{
        Name     = $Name
        Expected = $Expected
        Actual   = $Actual
        Pass     = $Pass
    }) | Out-Null

    if ($Pass) {
        Write-Host "PASS  $Name" -ForegroundColor Green
    } else {
        Write-Host "FAIL  $Name (expected $Expected, got $Actual)" -ForegroundColor Red
    }
}

function Wait-ServiceHealthy {
    param([string]$Container, [int]$TimeoutSeconds)

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        $status = (& docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' $Container 2>$null | Out-String).Trim()

        if ($status -eq 'healthy' -or $status -eq 'running') {
            return $true
        }

        Start-Sleep -Milliseconds 500
    }

    return $false
}

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$Body = $null,
        [string]$Jar = '',
        [string]$XsrfToken = ''
    )

    $bodyOut = Join-Path $env:TEMP ("dashboard-body-$([guid]::NewGuid().ToString('N')).json")
    $curlArgs = @(
        '-s', '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-H', 'Accept: application/json'
    )

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
        $bodyFile = Join-Path $env:TEMP ("dashboard-payload-$([guid]::NewGuid().ToString('N')).json")
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

    return [pscustomobject]@{
        Status = $status
        Body   = $content
    }
}

function Get-CookieValue {
    param([string]$Jar, [string]$Name)

    if (-not (Test-Path $Jar)) {
        return ''
    }

    $line = Get-Content $Jar | Where-Object { $_ -match "`t$Name`t" } | Select-Object -Last 1

    if (-not $line) {
        return ''
    }

    return [uri]::UnescapeDataString((($line -split "`t")[-1]))
}

function Get-Json {
    param([string]$Body)

    if ([string]::IsNullOrWhiteSpace($Body)) {
        return $null
    }

    try {
        return $Body | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Invoke-Fixture {
    param([string]$Action, [string[]]$ExtraEnv = @())

    $dockerArgs = @(
        'compose'
    ) + $Compose + @(
        'run', '--rm', '-T', '--no-deps'
    )

    foreach ($entry in $ExtraEnv) {
        $dockerArgs += @('-e', $entry)
    }

    $dockerArgs += @(
        '-v', "${RepoRoot}/scripts:/scripts:ro",
        'app', 'php', '/scripts/admin-dashboard-e2e-fixture.php', $Action
    )

    return @(& docker @dockerArgs 2>$null)
}

function Login {
    param([string]$Email, [string]$Password, [string]$Jar)

    Remove-Item $Jar -Force -ErrorAction SilentlyContinue
    Invoke-Api -Method GET -Path '/sanctum/csrf-cookie' -Jar $Jar | Out-Null
    $xsrf = Get-CookieValue -Jar $Jar -Name 'XSRF-TOKEN'
    $body = @{ email = $Email; password = $Password } | ConvertTo-Json -Compress

    return Invoke-Api -Method POST -Path '/api/login' -Body $body -Jar $Jar -XsrfToken $xsrf
}

function Wait-ForStatus {
    param(
        [string]$Path,
        [int[]]$Expected,
        [int]$TimeoutSeconds,
        [string]$Jar = '',
        [string]$XsrfToken = ''
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $last = $null

    do {
        $last = Invoke-Api -Method GET -Path $Path -Jar $Jar -XsrfToken $XsrfToken

        if ($Expected -contains $last.Status) {
            return $last
        }

        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)

    return $last
}

function Compare-Metric {
    param(
        [string]$Name,
        [object]$Actual,
        [object]$Expected,
        [string]$Json
    )

    Add-Result "$Name status" '200' "$($Actual.Status)" ($Actual.Status -eq 200)
    $payload = Get-Json $Json
    $value = $null

    if ($null -ne $payload -and $null -ne $payload.data) {
        $value = $payload.data.$Name
    }

    Add-Result "$Name value" "$Expected" "$value" ($null -ne $value -and [int]$value -eq [int]$Expected)
}

try {
    Write-Host '== Building and starting the test stack ==' -ForegroundColor Cyan
    & docker compose @Compose build raffles-service orders-service payments-service nginx 2>&1 | Out-Null
    & docker compose @Compose up -d --force-recreate app auth-service raffles-service orders-service payments-service nginx 2>&1 | Out-Null
    & docker compose @Compose stop scheduler *> $null

    foreach ($container in @(
        'rifacloud-auth-service',
        'rifacloud-raffles-service',
        'rifacloud-orders-service',
        'rifacloud-payments-service'
    )) {
        if (-not (Wait-ServiceHealthy $container $StartTimeoutSeconds)) {
            throw "Container $container did not become healthy."
        }
    }

    Write-Host '== Creating isolated fixtures ==' -ForegroundColor Cyan
    $createOutput = Invoke-Fixture -Action 'create' -ExtraEnv @(
        "DASH_E2E_ADMIN_EMAIL=$AdminEmail",
        "DASH_E2E_USER_EMAIL=$UserEmail",
        "DASH_E2E_ADMIN_PASSWORD=$AdminPassword",
        "DASH_E2E_USER_PASSWORD=$UserPassword"
    )
    $fixtureLine = @($createOutput) | Where-Object { $_ -match '^\s*\{' } | Select-Object -Last 1

    if (-not $fixtureLine) {
        throw "Could not create dashboard fixtures. Output: $($createOutput -join ' ')"
    }

    # The fixture reads its arguments with --name=value; pass them explicitly.
    $Fixture = Get-Json $fixtureLine

    if ($null -eq $Fixture -or $null -eq $Fixture.expected) {
        throw "Invalid dashboard fixture output: $fixtureLine"
    }

    $adminLogin = Login -Email $AdminEmail -Password $AdminPassword -Jar $AdminJar
    $userLogin = Login -Email $UserEmail -Password $UserPassword -Jar $UserJar
    Add-Result 'ADMIN login' '200' "$($adminLogin.Status)" ($adminLogin.Status -eq 200)
    Add-Result 'USER login' '200' "$($userLogin.Status)" ($userLogin.Status -eq 200)

    $adminXsrf = Get-CookieValue -Jar $AdminJar -Name 'XSRF-TOKEN'

    Write-Host '== Authorization ==' -ForegroundColor Cyan
    $anonymous = Invoke-Api -Path '/api/admin/raffles/metrics'
    Add-Result 'Anonymous raffle metrics' '401' "$($anonymous.Status)" ($anonymous.Status -eq 401)

    $userCall = Invoke-Api -Path '/api/admin/raffles/metrics' -Jar $UserJar -XsrfToken (Get-CookieValue -Jar $UserJar -Name 'XSRF-TOKEN')
    Add-Result 'USER raffle metrics' '403' "$($userCall.Status)" ($userCall.Status -eq 403)

    Write-Host '== Exact metrics ==' -ForegroundColor Cyan
    $raffles = Invoke-Api -Path '/api/admin/raffles/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    $orders = Invoke-Api -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    $incidents = Invoke-Api -Path '/api/admin/payments/incidents/metrics' -Jar $AdminJar -XsrfToken $adminXsrf

    Compare-Metric 'published_raffles' $raffles $Fixture.expected.published_raffles $raffles.Body
    Add-Result 'orders metrics status' '200' "$($orders.Status)" ($orders.Status -eq 200)
    $ordersJson = Get-Json $orders.Body
    Add-Result 'paid_orders value' "$($Fixture.expected.paid_orders)" "$($ordersJson.data.paid_orders)" (
        $null -ne $ordersJson.data.paid_orders -and
        [int]$ordersJson.data.paid_orders -eq [int]$Fixture.expected.paid_orders
    )
    Add-Result 'sold_numbers value' "$($Fixture.expected.sold_numbers)" "$($ordersJson.data.sold_numbers)" (
        $null -ne $ordersJson.data.sold_numbers -and
        [int]$ordersJson.data.sold_numbers -eq [int]$Fixture.expected.sold_numbers
    )
    Compare-Metric 'pending_incidents' $incidents $Fixture.expected.pending_incidents $incidents.Body

    $wrongMethod = Invoke-Api -Method POST -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken $adminXsrf -Body '{}'
    Add-Result 'POST on metrics returns 405' '405' "$($wrongMethod.Status)" ($wrongMethod.Status -eq 405)

    Write-Host '== Controlled 503 when an owner service is down ==' -ForegroundColor Cyan

    & docker compose @Compose stop raffles-service *> $null
    $rafflesDown = Wait-ForStatus -Path '/api/admin/raffles/metrics' -Expected @(503) -TimeoutSeconds 15 -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'raffles metrics while raffles-service is down' '503' "$($rafflesDown.Status)" ($rafflesDown.Status -eq 503)
    $downJson = Get-Json $rafflesDown.Body
    Add-Result 'raffles 503 has a controlled message' 'true' "$([bool]($downJson.message))" ($null -ne $downJson -and $null -ne $downJson.message)
    $ordersStill = Invoke-Api -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'orders metrics stay available' '200' "$($ordersStill.Status)" ($ordersStill.Status -eq 200)
    & docker compose @Compose start raffles-service *> $null
    if (-not (Wait-ServiceHealthy 'rifacloud-raffles-service' $StartTimeoutSeconds)) { throw 'raffles-service did not restart.' }

    & docker compose @Compose stop orders-service *> $null
    $ordersDown = Wait-ForStatus -Path '/api/admin/orders/metrics' -Expected @(503) -TimeoutSeconds 15 -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'orders metrics while orders-service is down' '503' "$($ordersDown.Status)" ($ordersDown.Status -eq 503)
    $rafflesStill = Invoke-Api -Path '/api/admin/raffles/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'raffles metrics stay available' '200' "$($rafflesStill.Status)" ($rafflesStill.Status -eq 200)
    & docker compose @Compose start orders-service *> $null
    if (-not (Wait-ServiceHealthy 'rifacloud-orders-service' $StartTimeoutSeconds)) { throw 'orders-service did not restart.' }

    & docker compose @Compose stop payments-service *> $null
    $paymentsDown = Wait-ForStatus -Path '/api/admin/payments/incidents/metrics' -Expected @(503) -TimeoutSeconds 15 -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'incident metrics while payments-service is down' '503' "$($paymentsDown.Status)" ($paymentsDown.Status -eq 503)
    $ordersStill = Invoke-Api -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'orders metrics stay available with payments down' '200' "$($ordersStill.Status)" ($ordersStill.Status -eq 200)
    & docker compose @Compose start payments-service *> $null
    if (-not (Wait-ServiceHealthy 'rifacloud-payments-service' $StartTimeoutSeconds)) { throw 'payments-service did not restart.' }

    $finalRaffles = Invoke-Api -Path '/api/admin/raffles/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    $finalOrders = Invoke-Api -Path '/api/admin/orders/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    $finalIncidents = Invoke-Api -Path '/api/admin/payments/incidents/metrics' -Jar $AdminJar -XsrfToken $adminXsrf
    Add-Result 'all metrics recover after restarts' '200,200,200' "$($finalRaffles.Status),$($finalOrders.Status),$($finalIncidents.Status)" (
        $finalRaffles.Status -eq 200 -and $finalOrders.Status -eq 200 -and $finalIncidents.Status -eq 200
    )
} catch {
    Add-Result 'Dashboard E2E execution' 'no exception' $_.Exception.Message $false
} finally {
    if ($null -ne $Fixture) {
        try {
            $raffleIds = ($Fixture.raffle_ids -join ',')
            $userIds = "$($Fixture.admin_id),$($Fixture.user_id)"
            Invoke-Fixture -Action 'cleanup' -ExtraEnv @(
                "DASH_E2E_RAFFLES=$raffleIds",
                "DASH_E2E_USERS=$userIds"
            ) | Out-Null
        } catch {
            Write-Host "WARN  Could not clean dashboard fixtures: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    Remove-Item $AdminJar -Force -ErrorAction SilentlyContinue
    Remove-Item $UserJar -Force -ErrorAction SilentlyContinue

    Write-Host 'Restoring the normal stack...' -ForegroundColor Cyan
    & docker compose up -d --force-recreate app scheduler auth-service raffles-service orders-service payments-service nginx *> $null

    foreach ($container in @(
        'rifacloud-auth-service',
        'rifacloud-raffles-service',
        'rifacloud-orders-service',
        'rifacloud-payments-service',
        'rifacloud-scheduler'
    )) {
        Wait-ServiceHealthy $container $StartTimeoutSeconds | Out-Null
    }
}

$failed = @($Results | Where-Object { -not $_.Pass }).Count
Write-Host ''
Write-Host "Checks: $($Results.Count - $failed) passed, $failed failed" -ForegroundColor $(if ($failed -eq 0) { 'Green' } else { 'Red' })

if ($failed -gt 0) { exit 1 }
