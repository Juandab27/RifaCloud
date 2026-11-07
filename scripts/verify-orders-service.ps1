#requires -Version 5.1
<#
.SYNOPSIS
    End-to-end verification of the S3 orders-service extraction.

.DESCRIPTION
    Exercises, through Nginx on the real stack:

      1. Shared-session authentication, CSRF and ownership (401/404/419).
      2. Order creation, JSON contract and atomic reservation.
      3. Real double-sale prevention with two parallel POSTs.
      4. Reservation expiry through the signed internal endpoint and the
         monolith scheduler command.
      5. Payment regression: UNKNOWN payments block release, payment routes
         and webhooks still reach the monolith.
      6. Stop/start isolation: only orders-service stops; catalog and auth keep
         working and order routes return a controlled JSON 503.

    Temporary USER/ADMIN/session/raffle/number fixtures are inserted in the
    development database (rifacloud) with an `orders-e2e-*@example.invalid`
    prefix and removed in `finally`. The script never runs migrations, never
    deletes volumes and never touches pre-existing rows.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-orders-service.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$StartTimeoutSeconds = 90
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Results = New-Object System.Collections.Generic.List[object]
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

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$Body = $null,
        [string]$SessionCookie = '',
        [string]$XsrfHeader = '',
        [hashtable]$Headers = @{}
    )

    $token = [guid]::NewGuid().ToString('N')
    $bodyOut = Join-Path $env:TEMP "orders-e2e-body-$token.txt"
    $headerOut = Join-Path $env:TEMP "orders-e2e-headers-$token.txt"
    $curlArgs = @('-s', '-D', $headerOut, '-o', $bodyOut, '-w', '%{http_code}', '-X', $Method)

    if ($SessionCookie -ne '') {
        $curlArgs += @('-H', "Cookie: rifacloud-session=$SessionCookie; XSRF-TOKEN=$XsrfHeader")
        $curlArgs += @('-H', "Origin: $BaseUrl")
        $curlArgs += @('-H', "X-XSRF-TOKEN: $XsrfHeader")
    }

    foreach ($name in $Headers.Keys) {
        $curlArgs += @('-H', "$name`: $($Headers[$name])")
    }

    if ($null -ne $Body) {
        $bodyFile = Join-Path $env:TEMP "orders-e2e-payload-$token.json"
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [IO.File]::WriteAllText($bodyFile, $Body, $utf8NoBom)
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
    }

    $curlArgs += ($BaseUrl + $Path)
    $statusText = & curl.exe @curlArgs 2>$null
    $status = 0
    [void][int]::TryParse(([string]$statusText).Trim(), [ref]$status)

    $content = ''
    $headerContent = ''

    if (Test-Path $bodyOut) {
        $content = Get-Content $bodyOut -Raw
        Remove-Item $bodyOut -Force -ErrorAction SilentlyContinue
    }

    if (Test-Path $headerOut) {
        $headerContent = Get-Content $headerOut -Raw
        Remove-Item $headerOut -Force -ErrorAction SilentlyContinue
    }

    if ($null -ne $Body -and (Test-Path $bodyFile)) {
        Remove-Item $bodyFile -Force -ErrorAction SilentlyContinue
    }

    return [pscustomobject]@{
        Status  = $status
        Body    = $content
        Headers = $headerContent
    }
}

function Invoke-Fixture {
    param([string[]]$Arguments)

    $output = & docker compose run --rm --no-deps `
        -v "${RepoRoot}/scripts:/scripts:ro" `
        app php /scripts/orders-e2e-fixture.php @Arguments 2>$null

    if ($LASTEXITCODE -ne 0) {
        throw "Fixture command failed: $($Arguments -join ' ')"
    }

    return (($output | Out-String).Trim() | ConvertFrom-Json)
}

function Test-OrdersServiceHeader {
    param([string]$Headers)

    return $Headers -match '(?im)^X-RifaCloud-Service:\s*orders-service'
}

function Wait-OrdersHealthy {
    param([int]$TimeoutSeconds)

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        $status = & docker inspect --format '{{.State.Health.Status}}' rifacloud-orders-service 2>$null

        if (($status | Out-String).Trim() -eq 'healthy') {
            return $true
        }

        Start-Sleep -Milliseconds 500
    }

    return $false
}

try {
    Write-Host 'Creating temporary fixtures...' -ForegroundColor Cyan
    $Fixture = Invoke-Fixture @('create')

    $sessionCookie = [string]$Fixture.session_cookie
    $xsrf = [string]$Fixture.xsrf_header
    $raffleId = [int]$Fixture.raffle_id

    $anon = Invoke-Api -Method 'GET' -Path "/api/orders/1"
    Add-Result 'Anonymous GET /api/orders/{id}' '401' "$($anon.Status)" ($anon.Status -eq 401)

    $noCsrf = Invoke-Api -Method 'POST' -Path '/api/orders' -Body '{"raffle_id":1,"numbers":[1]}' `
        -Headers @{ Origin = $BaseUrl; Cookie = "rifacloud-session=$sessionCookie; XSRF-TOKEN=$xsrf" }
    Add-Result 'Stateful POST without CSRF token' '419' "$($noCsrf.Status)" ($noCsrf.Status -eq 419)

    $invalid = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Empty numbers rejected' '422' "$($invalid.Status)" ($invalid.Status -eq 422)

    $createBody = "{`"raffle_id`":$raffleId,`"numbers`":[1,2]}"
    $created = Invoke-Api -Method 'POST' -Path '/api/orders' -Body $createBody -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Create order' '201' "$($created.Status)" ($created.Status -eq 201)
    Add-Result 'Order route reaches orders-service' 'orders-service' `
        $(if (Test-OrdersServiceHeader $created.Headers) { 'orders-service' } else { 'missing' }) `
        (Test-OrdersServiceHeader $created.Headers)

    $order = $null
    if ($created.Status -eq 201) {
        $order = $created.Body | ConvertFrom-Json
        Add-Result 'Order status is PENDING_PAYMENT' 'PENDING_PAYMENT' "$($order.data.status)" ($order.data.status -eq 'PENDING_PAYMENT')
        Add-Result 'Order total_amount is price * count' '3000' "$($order.data.total_amount)" ($order.data.total_amount -eq 3000)
        Add-Result 'Order returns 2 numbers' '2' "$($order.data.numbers.Count)" ($order.data.numbers.Count -eq 2)
    }

    $conflict = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[1,3]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Overlapping numbers rejected' '409' "$($conflict.Status)" ($conflict.Status -eq 409)
    if ($conflict.Status -eq 409) {
        $conflictJson = $conflict.Body | ConvertFrom-Json
        Add-Result 'Conflict code is NUMBERS_UNAVAILABLE' 'NUMBERS_UNAVAILABLE' "$($conflictJson.code)" ($conflictJson.code -eq 'NUMBERS_UNAVAILABLE')
    }

    $createdThird = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[3]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'A free number can still be reserved' '201' "$($createdThird.Status)" ($createdThird.Status -eq 201)

    if ($null -ne $order) {
        $show = Invoke-Api -Method 'GET' -Path "/api/orders/$($order.data.id)" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Owner can read the order' '200' "$($show.Status)" ($show.Status -eq 200)

        $other = Invoke-Api -Method 'GET' -Path '/api/orders/999999999' -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Unknown order returns 404' '404' "$($other.Status)" ($other.Status -eq 404)
    }

    # ---- Double-sale prevention: two parallel POSTs, same numbers --------
    $parallelBody = "{`"raffle_id`":$raffleId,`"numbers`":[10,11]}"
    $parallelFile = Join-Path $env:TEMP "orders-e2e-parallel-$([guid]::NewGuid().ToString('N')).json"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($parallelFile, $parallelBody, $utf8NoBom)

    $parallelArgs = @(
        '-s', '-o', 'NUL', '-w', '%{http_code}',
        '-X', 'POST',
        '-H', "Cookie: rifacloud-session=$sessionCookie; XSRF-TOKEN=$xsrf",
        '-H', "Origin: $BaseUrl",
        '-H', "X-XSRF-TOKEN: $xsrf",
        '-H', 'Content-Type: application/json',
        '--data-binary', "@$parallelFile",
        ($BaseUrl + '/api/orders')
    )

    # Both workers spin until the barrier file appears, so the two POSTs are
    # released at (almost) the same instant instead of relying on job startup
    # latency. Each Start-Job call needs its own parentheses inside @(): with a
    # bare comma PowerShell binds the second call as another argument of the
    # first Start-Job and fails with ParameterAlreadyBound.
    $barrierFile = Join-Path $env:TEMP "orders-e2e-barrier-$([guid]::NewGuid().ToString('N')).lock"

    $parallelWorker = {
        param($curlArgs, $barrier)

        while (-not (Test-Path $barrier)) {
            Start-Sleep -Milliseconds 20
        }

        (& curl.exe @curlArgs 2>$null)
    }

    $jobs = @(
        (Start-Job -ScriptBlock $parallelWorker -ArgumentList (,$parallelArgs), $barrierFile),
        (Start-Job -ScriptBlock $parallelWorker -ArgumentList (,$parallelArgs), $barrierFile)
    )

    # Give both runspaces time to reach the barrier before releasing them.
    Start-Sleep -Seconds 2
    [IO.File]::WriteAllText($barrierFile, 'go')

    Wait-Job -Job $jobs | Out-Null
    $parallelStatuses = @($jobs | Receive-Job | ForEach-Object { [int]([string]$_).Trim() })
    Remove-Job -Job $jobs -Force
    Remove-Item $parallelFile -Force -ErrorAction SilentlyContinue
    Remove-Item $barrierFile -Force -ErrorAction SilentlyContinue

    $parallelStatuses = @($parallelStatuses | Sort-Object)
    Add-Result 'Two parallel POSTs for the same numbers' '201 + 409' ($parallelStatuses -join '+') `
        (($parallelStatuses.Count -eq 2) -and ($parallelStatuses[0] -eq 201) -and ($parallelStatuses[1] -eq 409))

    # Orders so far: [1,2] (2) + [3] (1) + the single concurrency winner (2).
    $statusAfterConcurrency = Invoke-Fixture @('status', "--raffle=$raffleId")
    Add-Result 'Double-sale prevented (reserved count)' '5' "$($statusAfterConcurrency.reserved)" ($statusAfterConcurrency.reserved -eq 5)
    Add-Result 'No extra order was created for the losing request' '3' "$(@($statusAfterConcurrency.orders).Count)" (@($statusAfterConcurrency.orders).Count -eq 3)

    # ---- Expiry through the internal endpoint ---------------------------
    $expiryOrder = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[5]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $expiryOrderId = if ($expiryOrder.Status -eq 201) { (($expiryOrder.Body | ConvertFrom-Json).data.id) } else { 0 }
    Add-Result 'Create an order for expiry' '201' "$($expiryOrder.Status)" ($expiryOrder.Status -eq 201)

    if ($expiryOrderId -gt 0) {
        Invoke-Fixture @('expire', "--order=$expiryOrderId") | Out-Null
        $expiryOutput = & docker compose exec -T scheduler php artisan orders:expire-reservations 2>$null
        $expiryText = ($expiryOutput | Out-String)
        Add-Result 'Scheduler command reaches orders-service' 'Expired orders:' `
            $(if ($expiryText -match 'Expired orders:') { 'Expired orders:' } else { $expiryText.Trim() }) `
            ($expiryText -match 'Expired orders:')

        $expiredShow = Invoke-Api -Method 'GET' -Path "/api/orders/$expiryOrderId" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        $expiredStatus = if ($expiredShow.Status -eq 200) { (($expiredShow.Body | ConvertFrom-Json).data.status) } else { '' }
        Add-Result 'Expired order is released' 'EXPIRED' $expiredStatus ($expiredStatus -eq 'EXPIRED')
    }

    # ---- Payment UNKNOWN blocks release ---------------------------------
    $unknownOrder = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[6]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $unknownOrderId = if ($unknownOrder.Status -eq 201) { (($unknownOrder.Body | ConvertFrom-Json).data.id) } else { 0 }
    Add-Result 'Create an order with an UNKNOWN payment' '201' "$($unknownOrder.Status)" ($unknownOrder.Status -eq 201)

    if ($unknownOrderId -gt 0) {
        Invoke-Fixture @('unknown', "--order=$unknownOrderId") | Out-Null
        & docker compose exec -T scheduler php artisan orders:expire-reservations 2>$null | Out-Null

        $unknownShow = Invoke-Api -Method 'GET' -Path "/api/orders/$unknownOrderId" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        $unknownStatus = if ($unknownShow.Status -eq 200) { (($unknownShow.Body | ConvertFrom-Json).data.status) } else { '' }
        Add-Result 'UNKNOWN payment blocks expiry' 'PENDING_PAYMENT' $unknownStatus ($unknownStatus -eq 'PENDING_PAYMENT')

        $statusAfterUnknown = Invoke-Fixture @('status', "--raffle=$raffleId")
        # Numbers 1, 2, 3, 10, 11 and now 6 stay RESERVED; 5 was released by the
        # expired order, so the count is 6 RESERVED and 14 AVAILABLE of 20.
        Add-Result 'UNKNOWN payment keeps its number RESERVED' '6' "$($statusAfterUnknown.reserved)" ($statusAfterUnknown.reserved -eq 6)
        Add-Result 'Expired number was released' '14' "$($statusAfterUnknown.available)" ($statusAfterUnknown.available -eq 14)
    }

    # ---- Payment routes and webhooks stay in the monolith --------------
    if ($null -ne $order) {
        $paymentRoute = Invoke-Api -Method 'GET' -Path "/api/orders/$($order.data.id)/payments" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Payment sub-route stays in the monolith' 'monolith' `
            $(if (Test-OrdersServiceHeader $paymentRoute.Headers) { 'orders-service' } else { 'monolith' }) `
            (-not (Test-OrdersServiceHeader $paymentRoute.Headers))
        Add-Result 'Payment sub-route method is rejected by the monolith' '405' "$($paymentRoute.Status)" ($paymentRoute.Status -eq 405)
    }

    $webhook = Invoke-Api -Method 'POST' -Path '/api/payments/webhook' -Body '{}' -Headers @{ 'X-Request-Id' = 'e2e' }
    Add-Result 'Webhook is still handled by the monolith' 'monolith' `
        $(if (Test-OrdersServiceHeader $webhook.Headers) { 'orders-service' } else { 'monolith' }) `
        (-not (Test-OrdersServiceHeader $webhook.Headers))
    Add-Result 'Webhook is not an orders-service 503' 'not 503' "$($webhook.Status)" ($webhook.Status -ne 503)

    # ---- Stop/start isolation ------------------------------------------
    Write-Host 'Stopping orders-service only...' -ForegroundColor Cyan
    & docker compose stop orders-service *> $null
    Start-Sleep -Seconds 3

    if ($null -ne $order) {
        $down = Invoke-Api -Method 'GET' -Path "/api/orders/$($order.data.id)" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Orders stop returns controlled 503' '503' "$($down.Status)" ($down.Status -eq 503)
        Add-Result 'Orders stop keeps a JSON message' 'orders' "$($down.Body)" ($down.Body -match 'Servicio de ordenes temporalmente no disponible')

        $authDuringStop = Invoke-Api -Method 'GET' -Path '/api/me' -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Auth still works while orders is down' '200' "$($authDuringStop.Status)" ($authDuringStop.Status -eq 200)

        $catalogDuringStop = Invoke-Api -Method 'GET' -Path '/api/raffles'
        Add-Result 'Catalog still works while orders is down' '200' "$($catalogDuringStop.Status)" ($catalogDuringStop.Status -eq 200)
    }

    Write-Host 'Starting orders-service...' -ForegroundColor Cyan
    & docker compose start orders-service *> $null
    $healthyAgain = Wait-OrdersHealthy $StartTimeoutSeconds
    Add-Result 'orders-service becomes healthy again' 'healthy' $(if ($healthyAgain) { 'healthy' } else { 'timeout' }) $healthyAgain

    if ($null -ne $order) {
        $up = Invoke-Api -Method 'GET' -Path "/api/orders/$($order.data.id)" -SessionCookie $sessionCookie -XsrfHeader $xsrf
        Add-Result 'Orders works again after restart' '200' "$($up.Status)" ($up.Status -eq 200)
    }
} catch {
    Add-Result 'Unexpected error' 'none' $_.Exception.Message $false
} finally {
    if ($null -ne $Fixture) {
        try {
            Invoke-Fixture @(
                'cleanup',
                "--user=$($Fixture.user_id)",
                "--admin=$($Fixture.admin_id)",
                "--raffle=$($Fixture.raffle_id)"
            ) | Out-Null
        } catch {
            Write-Host "WARN  Fixture cleanup failed: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    # Never leave the service stopped after the test.
    & docker compose start orders-service *> $null
    Wait-OrdersHealthy $StartTimeoutSeconds | Out-Null
}

$failed = @($Results | Where-Object { -not $_.Pass })
$passed = @($Results | Where-Object { $_.Pass })

Write-Host ''
Write-Host "Checks: $($passed.Count) passed, $($failed.Count) failed" -ForegroundColor Cyan

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
