#requires -Version 5.1
<#
.SYNOPSIS
    S6 end-to-end check: session continuity, reservation expiry and Mercado Pago
    return, against rifacloud_test.

.DESCRIPTION
    Recreates the stack on the fully migrated test schema with the simulated
    gateway and a 1-minute reservation, then verifies through Nginx:

      1. Secure session cookies and repeated /api/me (page reload).
      2. The catalog and orders-service share RESERVATION_MINUTES.
      3. A checkout return performs a server-side verification (not a redirect
         approval) and the order reaches PAID with its numbers SOLD.
      4. A repeated verification returns 409 without losing the session.
      5. Expired reservations release numbers; UNKNOWN/review/approved/SOLD
         cases never release; the scheduler command is idempotent.
      6. 419 (missing CSRF), 503 (auth-service down) and 401 (invalidated
         session) keep the frontend state semantics.

    The real `rifacloud` database is never touched. The normal stack is restored
    in `finally`.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-s6-session-and-return.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$StartTimeoutSeconds = 90
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Results = New-Object System.Collections.Generic.List[object]
$Compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
$Jar = Join-Path $env:TEMP "s6-cookies-$([guid]::NewGuid().ToString('N')).txt"
$Fixture = $null
$Email = "s6-e2e-user-$([guid]::NewGuid().ToString('N').Substring(0, 10))@example.invalid"
$Password = 'S6-e2e-password1!'
$UserId = 0
$RaffleId = 0
$SchedulerWasRunning = $false

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
        $status = & docker inspect --format '{{.State.Health.Status}}' $Container 2>$null

        if (($status | Out-String).Trim() -eq 'healthy') {
            return $true
        }

        Start-Sleep -Milliseconds 500
    }

    return $false
}

function Get-XsrfToken {
    if (-not (Test-Path $Jar)) {
        return ''
    }

    $line = Get-Content $Jar | Where-Object { $_ -match 'XSRF-TOKEN' } | Select-Object -First 1

    if (-not $line) {
        return ''
    }

    return [uri]::UnescapeDataString((($line -split "`t")[-1]))
}

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [object]$Body = $null,
        [bool]$Csrf = $true,
        [string]$CookieJar = $Jar,
        [bool]$SaveCookies = $true
    )

    $token = [guid]::NewGuid().ToString('N')
    $bodyOut = Join-Path $env:TEMP "s6-body-$token.txt"
    $headerOut = Join-Path $env:TEMP "s6-headers-$token.txt"
    $curlArgs = @(
        '-s', '-D', $headerOut, '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-b', $CookieJar,
        '-H', "Origin: $BaseUrl",
        '-H', 'Accept: application/json'
    )

    if ($SaveCookies) {
        $curlArgs += @('-c', $CookieJar)
    }

    if ($Csrf) {
        $xsrf = Get-XsrfToken
        if ($xsrf -ne '') {
            $curlArgs += @('-H', "X-XSRF-TOKEN: $xsrf")
        }
    }

    if ($null -ne $Body) {
        $bodyFile = Join-Path $env:TEMP "s6-payload-$token.json"
        $json = if ($Body -is [string]) { $Body } else { $Body | ConvertTo-Json -Depth 6 -Compress }
        [IO.File]::WriteAllText($bodyFile, $json, (New-Object System.Text.UTF8Encoding($false)))
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
    }

    $curlArgs += ($BaseUrl + $Path)
    $statusText = & curl.exe @curlArgs 2>$null
    $status = 0
    [void][int]::TryParse(([string]$statusText).Trim(), [ref]$status)

    $content = ''
    $headers = ''

    if (Test-Path $bodyOut) {
        $content = Get-Content $bodyOut -Raw
        Remove-Item $bodyOut -Force -ErrorAction SilentlyContinue
    }

    if (Test-Path $headerOut) {
        $headers = Get-Content $headerOut -Raw
        Remove-Item $headerOut -Force -ErrorAction SilentlyContinue
    }

    return [pscustomobject]@{ Status = $status; Body = $content; Headers = $headers }
}

function Invoke-Fixture {
    param([string[]]$Arguments)

    $output = & docker compose @Compose run --rm --no-deps `
        -v "${RepoRoot}/scripts:/scripts:ro" `
        app php /scripts/s6-e2e-fixture.php @Arguments 2>$null

    if ($LASTEXITCODE -ne 0) {
        throw "Fixture command failed: $($Arguments -join ' ')"
    }

    return (($output | Out-String).Trim() | ConvertFrom-Json)
}

function Find-Order {
    param([object]$Status, [int]$OrderId)

    return @($Status.orders | Where-Object { [int]$_.id -eq $OrderId })[0]
}

function Run-Expiry {
    $output = & docker compose @Compose exec -T app php artisan orders:expire-reservations 2>&1
    return (($output | Out-String).Trim())
}

try {
    $env:S6_RESERVATION_MINUTES = '1'
    $env:PAYMENT_FAKE_OUTCOME = 'APPROVED'

    Write-Host 'Recreating the stack on the test schema (1-minute reservations)...' -ForegroundColor Cyan
    # The auth-service and raffles-service images copy their PHP source, so S6
    # fixes must be built before recreating the containers.
    & docker compose @Compose build auth-service raffles-service 2>&1 | Out-Null
    & docker compose @Compose up -d --force-recreate app scheduler auth-service raffles-service orders-service payments-service 2>&1 | Out-Null

    foreach ($container in @(
        'rifacloud-auth-service',
        'rifacloud-raffles-service',
        'rifacloud-orders-service',
        'rifacloud-payments-service'
    )) {
        if (-not (Wait-ServiceHealthy $container $StartTimeoutSeconds)) {
            throw "$container did not become healthy with the E2E overlay."
        }
    }

    Start-Sleep -Seconds 3

    # The automatic scheduler must not race the deterministic expiry assertions.
    & docker compose @Compose stop scheduler *> $null
    $SchedulerWasRunning = $true

    Write-Host 'Creating temporary fixtures...' -ForegroundColor Cyan
    $Fixture = Invoke-Fixture @('create-raffle')
    $RaffleId = [int]$Fixture.raffle_id

    # ---- Cookie attributes (configured domain and protocol) -------------
    $cookieHeaders = Join-Path $env:TEMP "s6-cookie-headers-$([guid]::NewGuid().ToString('N')).txt"
    & curl.exe -s -D $cookieHeaders -o NUL ($BaseUrl + '/sanctum/csrf-cookie') 2>$null

    $cookieText = if (Test-Path $cookieHeaders) { Get-Content $cookieHeaders -Raw } else { '' }
    Remove-Item $cookieHeaders -Force -ErrorAction SilentlyContinue

    $sessionLine = (($cookieText -split "`r?`n") | Where-Object { $_ -match '(?i)^Set-Cookie:\s*rifacloud-session=' } | Select-Object -First 1)
    $xsrfLine = (($cookieText -split "`r?`n") | Where-Object { $_ -match '(?i)^Set-Cookie:\s*XSRF-TOKEN=' } | Select-Object -First 1)

    Add-Result 'Session cookie is HttpOnly' 'httponly' $(if ($sessionLine -match '(?i)httponly') { 'httponly' } else { 'missing' }) ($sessionLine -match '(?i)httponly')
    Add-Result 'Session cookie is SameSite=Lax' 'samesite=lax' $(if ($sessionLine -match '(?i)samesite=lax') { 'samesite=lax' } else { 'other' }) ($sessionLine -match '(?i)samesite=lax')
    Add-Result 'Session cookie domain is localhost' 'domain=localhost' $(if ($sessionLine -match '(?i)domain=localhost') { 'domain=localhost' } else { 'other' }) ($sessionLine -match '(?i)domain=localhost')
    Add-Result 'HTTP local run does not force Secure' 'not secure' $(if ($sessionLine -match '(?i);\s*secure') { 'secure' } else { 'not secure' }) (-not ($sessionLine -match '(?i);\s*secure'))
    Add-Result 'XSRF cookie is readable by the SPA (no HttpOnly)' 'no httponly' $(if ($xsrfLine -match '(?i)httponly') { 'httponly' } else { 'no httponly' }) (-not ($xsrfLine -match '(?i)httponly'))

    # ---- Register, then simulate a page reload (two /me calls) ----------
    [IO.File]::WriteAllText($Jar, '')

    $csrf = Invoke-Api -Path '/sanctum/csrf-cookie'
    Add-Result 'CSRF bootstrap returns 204' '204' "$($csrf.Status)" ($csrf.Status -eq 204)

    $register = Invoke-Api -Method 'POST' -Path '/api/register' -Body @{
        name = 'S6 session user'
        email = $Email
        phone = '3000000000'
        password = $Password
        password_confirmation = $Password
        privacy_consent = $true
        terms_consent = $true
        marketing_consent = $false
        consent_version = if ($env:LEGAL_CONSENT_VERSION) { $env:LEGAL_CONSENT_VERSION } else { '2026-11-11' }
    }
    Add-Result 'Registration returns 201' '201' "$($register.Status)" ($register.Status -eq 201)

    # Refresh the XSRF token after the session id was regenerated.
    Invoke-Api -Path '/sanctum/csrf-cookie' | Out-Null

    $me1 = Invoke-Api -Path '/api/me'
    $me1Body = if ($me1.Status -eq 200) { $me1.Body | ConvertFrom-Json } else { $null }
    $me2 = Invoke-Api -Path '/api/me'
    $me2Body = if ($me2.Status -eq 200) { $me2.Body | ConvertFrom-Json } else { $null }

    $UserId = if ($null -ne $me1Body) { [int]$me1Body.data.id } else { 0 }

    Add-Result 'Session survives the first load' '200' "$($me1.Status)" ($me1.Status -eq 200)
    Add-Result 'Session survives a page reload' '200' "$($me2.Status)" ($me2.Status -eq 200)
    Add-Result 'The reload returns the same user' $Email "$($me2Body.data.email)" ($me2Body.data.email -eq $Email)

    # ---- Shared reservation duration ------------------------------------
    $raffle = Invoke-Api -Path "/api/raffles/$RaffleId"
    $raffleBody = if ($raffle.Status -eq 200) { $raffle.Body | ConvertFrom-Json } else { $null }
    Add-Result 'Catalog advertises the configured reservation duration' '1' "$($raffleBody.data.reservation_minutes)" ($raffleBody.data.reservation_minutes -eq 1)

    $order = Invoke-Api -Method 'POST' -Path '/api/orders' -Body @{ raffle_id = $RaffleId; numbers = @(1, 2) }
    $orderBody = if ($order.Status -eq 201) { $order.Body | ConvertFrom-Json } else { $null }
    $orderId = if ($null -ne $orderBody) { [int]$orderBody.data.id } else { 0 }
    Add-Result 'Order creation returns 201' '201' "$($order.Status)" ($order.Status -eq 201)

    $expiresMinutes = 0.0
    if ($null -ne $orderBody) {
        $created = [DateTimeOffset]::Parse($orderBody.data.created_at)
        $expires = [DateTimeOffset]::Parse($orderBody.data.expires_at)
        $expiresMinutes = ($expires - $created).TotalMinutes
    }

    Add-Result 'orders-service uses the same reservation duration' '1' ([math]::Round($expiresMinutes, 2)) ($expiresMinutes -ge 0.9 -and $expiresMinutes -le 1.1)
    Add-Result 'The backend provides expires_at' 'present' $(if ($null -ne $orderBody -and $orderBody.data.expires_at) { 'present' } else { 'missing' }) ($null -ne $orderBody -and $orderBody.data.expires_at -ne $null)

    # ---- Checkout return: server-side verification, never the URL -------
    $checkout = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments" -Body @{}
    $checkoutBody = if ($checkout.Status -eq 201) { $checkout.Body | ConvertFrom-Json } else { $null }
    $paymentId = if ($null -ne $checkoutBody) { [int]$checkoutBody.data.payments[0].id } else { 0 }
    Add-Result 'Checkout returns 201 and a PENDING payment' 'PENDING' "$($checkoutBody.data.payments[0].status)" ($checkout.Status -eq 201 -and $checkoutBody.data.payments[0].status -eq 'PENDING')

    $returned = Invoke-Api -Path "/api/orders/${orderId}?pago=retorno"
    Add-Result 'The return URL alone does not mark the order paid' 'PENDING_PAYMENT' "$((($returned.Body | ConvertFrom-Json).data.status))" ($returned.Status -eq 200 -and (($returned.Body | ConvertFrom-Json).data.status) -eq 'PENDING_PAYMENT')

    $verified = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments/$paymentId/verify" -Body @{}
    $verifiedBody = if ($verified.Status -eq 200) { $verified.Body | ConvertFrom-Json } else { $null }
    Add-Result 'The server-side verification confirms the payment' 'PAID' "$($verifiedBody.data.status)" ($verified.Status -eq 200 -and $verifiedBody.data.status -eq 'PAID')

    $afterVerify = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'The approved payment sells the numbers once' '2' "$($afterVerify.sold)" ($afterVerify.sold -eq 2)

    $secondVerify = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments/$paymentId/verify" -Body @{}
    Add-Result 'A repeated verification returns 409' '409' "$($secondVerify.Status)" ($secondVerify.Status -eq 409)

    $after409 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    $after409Status = (Find-Order $after409 $orderId).status
    Add-Result 'The 409 keeps the order PAID' 'PAID' "$after409Status" ($after409Status -eq 'PAID')

    $meAfter409 = Invoke-Api -Path '/api/me'
    Add-Result 'The 409 does not lose the session' '200' "$($meAfter409.Status)" ($meAfter409.Status -eq 200)

    # ---- Expiry: release, protected states and idempotency --------------
    # Flush any stale expired order left by a previous failed run.
    Run-Expiry | Out-Null

    $e1 = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserId", '--numbers=5', '--kind=no_payment', "--token=$($Fixture.token)")
    $e1Id = [int]$e1.order_id
    $run1 = Run-Expiry
    Add-Result 'An expired reservation is processed' 'Expired orders: 1' $run1 ($run1 -match 'Expired orders: 1')
    $s1 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'The expired order becomes EXPIRED' 'EXPIRED' "$((Find-Order $s1 $e1Id).status)" ((Find-Order $s1 $e1Id).status -eq 'EXPIRED')
    Add-Result 'The expired numbers return to AVAILABLE' 'AVAILABLE' "$(($s1.numbers | Where-Object { [int]$_.number -eq 5 }).status)" (($s1.numbers | Where-Object { [int]$_.number -eq 5 }).status -eq 'AVAILABLE')

    $run2 = Run-Expiry
    Add-Result 'Expiry is idempotent' 'Expired orders: 0' $run2 ($run2 -match 'Expired orders: 0')

    $sAfterRun2 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    $e1StatusAfterRun2 = (Find-Order $sAfterRun2 $e1Id).status
    Add-Result 'The idempotent run keeps the order EXPIRED' 'EXPIRED' "$e1StatusAfterRun2" ($e1StatusAfterRun2 -eq 'EXPIRED')

    $e2 = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserId", '--numbers=6', '--kind=unknown', "--token=$($Fixture.token)")
    $e2Id = [int]$e2.order_id
    Run-Expiry | Out-Null
    $s2 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'UNKNOWN keeps the reservation' 'PENDING_PAYMENT' "$((Find-Order $s2 $e2Id).status)" ((Find-Order $s2 $e2Id).status -eq 'PENDING_PAYMENT')
    Add-Result 'UNKNOWN keeps the number RESERVED' 'RESERVED' "$(($s2.numbers | Where-Object { [int]$_.number -eq 6 }).status)" (($s2.numbers | Where-Object { [int]$_.number -eq 6 }).status -eq 'RESERVED')

    $e3 = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserId", '--numbers=7', '--kind=review', "--token=$($Fixture.token)")
    $e3Id = [int]$e3.order_id
    Run-Expiry | Out-Null
    $s3 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'PAYMENT_REVIEW is never released' 'PAYMENT_REVIEW' "$((Find-Order $s3 $e3Id).status)" ((Find-Order $s3 $e3Id).status -eq 'PAYMENT_REVIEW')
    Add-Result 'PAYMENT_REVIEW keeps the number RESERVED' 'RESERVED' "$(($s3.numbers | Where-Object { [int]$_.number -eq 7 }).status)" (($s3.numbers | Where-Object { [int]$_.number -eq 7 }).status -eq 'RESERVED')

    $e4 = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserId", '--numbers=8', '--kind=approved', "--token=$($Fixture.token)")
    $e4Id = [int]$e4.order_id
    Run-Expiry | Out-Null
    $s4 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'An approved payment keeps the order PAID' 'PAID' "$((Find-Order $s4 $e4Id).status)" ((Find-Order $s4 $e4Id).status -eq 'PAID')
    Add-Result 'An approved payment keeps the number SOLD' 'SOLD' "$(($s4.numbers | Where-Object { [int]$_.number -eq 8 }).status)" (($s4.numbers | Where-Object { [int]$_.number -eq 8 }).status -eq 'SOLD')

    $e5 = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserId", '--numbers=9', '--kind=sold', "--token=$($Fixture.token)")
    $e5Id = [int]$e5.order_id
    Run-Expiry | Out-Null
    $s5 = Invoke-Fixture @('status', "--raffle=$RaffleId")
    Add-Result 'A SOLD number is never released' 'SOLD' "$(($s5.numbers | Where-Object { [int]$_.number -eq 9 }).status)" (($s5.numbers | Where-Object { [int]$_.number -eq 9 }).status -eq 'SOLD')
    Add-Result 'An order with a SOLD number stays PENDING_PAYMENT' 'PENDING_PAYMENT' "$((Find-Order $s5 $e5Id).status)" ((Find-Order $s5 $e5Id).status -eq 'PENDING_PAYMENT')

    # ---- 419: CSRF missing, then present -------------------------------
    $order2 = Invoke-Api -Method 'POST' -Path '/api/orders' -Body @{ raffle_id = $RaffleId; numbers = @(10, 11) }
    $order2Body = if ($order2.Status -eq 201) { $order2.Body | ConvertFrom-Json } else { $null }
    $order2Id = if ($null -ne $order2Body) { [int]$order2Body.data.id } else { 0 }

    $noCsrf = Invoke-Api -Method 'POST' -Path "/api/orders/$order2Id/payments" -Body @{} -Csrf $false
    Add-Result 'A stateful POST without CSRF returns 419' '419' "$($noCsrf.Status)" ($noCsrf.Status -eq 419)

    $withCsrf = Invoke-Api -Method 'POST' -Path "/api/orders/$order2Id/payments" -Body @{}
    Add-Result 'The same POST with CSRF succeeds' '201' "$($withCsrf.Status)" ($withCsrf.Status -eq 201)

    # ---- 503: auth-service down, session preserved ---------------------
    Write-Host 'Stopping auth-service only...' -ForegroundColor Cyan
    & docker compose @Compose stop auth-service *> $null
    Start-Sleep -Seconds 2

    $downMe = Invoke-Api -Path '/api/me'
    Add-Result 'A down auth-service returns 503' '503' "$($downMe.Status)" ($downMe.Status -eq 503)
    Add-Result 'The 503 keeps a controlled JSON message' 'autenticacion' "$($downMe.Body)" ($downMe.Body -match 'autenticacion temporalmente no disponible')

    & docker compose @Compose start auth-service *> $null
    $healthy = Wait-ServiceHealthy 'rifacloud-auth-service' $StartTimeoutSeconds
    Add-Result 'auth-service becomes healthy again' 'healthy' $(if ($healthy) { 'healthy' } else { 'timeout' }) $healthy

    $upMe = $null
    for ($attempt = 0; $attempt -lt 12; $attempt++) {
        Start-Sleep -Seconds 3
        $upMe = Invoke-Api -Path '/api/me'
        if ($upMe.Status -eq 200) {
            break
        }
    }
    Add-Result 'The session survives the auth-service restart' '200' "$($upMe.Status)" ($upMe.Status -eq 200)

    # ---- 401: invalidated session --------------------------------------
    $pw = (Select-String -Path "${RepoRoot}/.env" -Pattern '^DB_PASSWORD=' | ForEach-Object { $_.Line -replace '^DB_PASSWORD=','' })
    & docker compose @Compose exec -T -e MYSQL_PWD="$pw" mysql mysql -urifacloud -e "DELETE FROM rifacloud_test.sessions WHERE user_id = $UserId;" 2>$null | Out-Null

    $expiredMe = Invoke-Api -Path '/api/me'
    Add-Result 'An invalidated session returns 401' '401' "$($expiredMe.Status)" ($expiredMe.Status -eq 401)

    $expiredOrder = Invoke-Api -Path "/api/orders/$orderId"
    Add-Result 'The invalidated session cannot read the order' '401' "$($expiredOrder.Status)" ($expiredOrder.Status -eq 401)
} catch {
    Add-Result 'Unexpected error' 'none' $_.Exception.Message $false
    Write-Host '--- orders-service logs (last 30) ---' -ForegroundColor DarkGray
    & docker compose @Compose logs --tail 30 orders-service 2>&1 | Select-Object -Last 30 | ForEach-Object { Write-Host $_ -ForegroundColor DarkGray }
} finally {
    if ($null -ne $Fixture) {
        try {
            Invoke-Fixture @(
                'cleanup',
                "--raffle=$RaffleId",
                "--admin=$($Fixture.admin_id)",
                "--email=$Email"
            ) | Out-Null
        } catch {
            Write-Host "WARN  Fixture cleanup failed: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    if (Test-Path $Jar) {
        Remove-Item $Jar -Force -ErrorAction SilentlyContinue
    }

    if ($SchedulerWasRunning) {
        & docker compose @Compose start scheduler *> $null
    }

    Write-Host 'Restoring the normal stack...' -ForegroundColor Cyan
    Remove-Item Env:S6_RESERVATION_MINUTES -ErrorAction SilentlyContinue
    Remove-Item Env:PAYMENT_FAKE_OUTCOME -ErrorAction SilentlyContinue
    & docker compose up -d --force-recreate app scheduler auth-service raffles-service orders-service payments-service *> $null

    foreach ($container in @(
        'rifacloud-auth-service',
        'rifacloud-raffles-service',
        'rifacloud-orders-service',
        'rifacloud-payments-service'
    )) {
        Wait-ServiceHealthy $container $StartTimeoutSeconds | Out-Null
    }
}

$failed = @($Results | Where-Object { -not $_.Pass })
$passed = @($Results | Where-Object { $_.Pass })

Write-Host ''
Write-Host "Checks: $($passed.Count) passed, $($failed.Count) failed" -ForegroundColor Cyan

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
