#requires -Version 5.1
<#
.SYNOPSIS
    End-to-end verification of the S4 payments-service extraction.

.DESCRIPTION
    Exercises, through Nginx on the real stack with the simulated gateway:

      1. Shared-session authentication, CSRF and ownership on payment routes.
      2. Checkout contract and idempotent resume.
      3. Real duplicate webhook concurrency (two parallel POSTs).
      4. Approved payment, SOLD exactly once and the order JSON contract.
      5. Ignored/unknown webhook payloads.
      6. Admin resolve authorization.
      7. Stop/start isolation: only payments-service stops; auth, catalog,
         orders and the payment rows remain intact, and the service recovers.

    The fake gateway is enabled through docker-compose.e2e.yml only. The script
    restores the normal stack in `finally`.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-payments-service.ps1
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
$Compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')

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
    $bodyOut = Join-Path $env:TEMP "payments-e2e-body-$token.txt"
    $headerOut = Join-Path $env:TEMP "payments-e2e-headers-$token.txt"
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
        $bodyFile = Join-Path $env:TEMP "payments-e2e-payload-$token.json"
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

    $output = & docker compose @Compose run --rm --no-deps `
        -v "${RepoRoot}/scripts:/scripts:ro" `
        app php /scripts/payments-e2e-fixture.php @Arguments 2>$null

    if ($LASTEXITCODE -ne 0) {
        throw "Fixture command failed: $($Arguments -join ' ')"
    }

    return (($output | Out-String).Trim() | ConvertFrom-Json)
}

function Test-ServiceHeader {
    param([string]$Headers, [string]$Service)

    return $Headers -match "(?im)^X-RifaCloud-Service:\s*$Service"
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

try {
    $env:PAYMENT_FAKE_OUTCOME = 'APPROVED'

    Write-Host 'Recreating the stack on the test schema with the simulated gateway...' -ForegroundColor Cyan
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

    # Give Nginx's resolver time to see the recreated containers.
    Start-Sleep -Seconds 3

    Write-Host 'Creating temporary fixtures...' -ForegroundColor Cyan
    $Fixture = Invoke-Fixture @('create')

    $sessionCookie = [string]$Fixture.session_cookie
    $xsrf = [string]$Fixture.xsrf_header
    $raffleId = [int]$Fixture.raffle_id

    # ---- Authentication and CSRF ---------------------------------------
    $anon = Invoke-Api -Method 'POST' -Path "/api/orders/1/payments"
    Add-Result 'Anonymous checkout' '401' "$($anon.Status)" ($anon.Status -eq 401)

    $noCsrf = Invoke-Api -Method 'POST' -Path '/api/orders/1/payments' `
        -Headers @{ Origin = $BaseUrl; Cookie = "rifacloud-session=$sessionCookie; XSRF-TOKEN=$xsrf" }
    Add-Result 'Stateful checkout without CSRF token' '419' "$($noCsrf.Status)" ($noCsrf.Status -eq 419)

    # ---- Order + checkout contract -------------------------------------
    $created = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[1,2]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $orderId = if ($created.Status -eq 201) { (($created.Body | ConvertFrom-Json).data.id) } else { 0 }
    Add-Result 'Create order for checkout' '201' "$($created.Status)" ($created.Status -eq 201)

    $checkout = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Checkout returns 201' '201' "$($checkout.Status)" ($checkout.Status -eq 201)
    Add-Result 'Checkout reaches payments-service' 'payments-service' `
        $(if (Test-ServiceHeader $checkout.Headers 'payments-service') { 'payments-service' } else { 'missing' }) `
        (Test-ServiceHeader $checkout.Headers 'payments-service')

    $checkoutJson = if ($checkout.Status -eq 201) { $checkout.Body | ConvertFrom-Json } else { $null }
    $paymentId = if ($null -ne $checkoutJson) { $checkoutJson.data.payments[0].id } else { 0 }
    Add-Result 'Checkout keeps the order PENDING_PAYMENT' 'PENDING_PAYMENT' "$($checkoutJson.data.status)" ($checkoutJson.data.status -eq 'PENDING_PAYMENT')
    Add-Result 'Checkout creates a PENDING payment' 'PENDING' "$($checkoutJson.data.payments[0].status)" ($checkoutJson.data.payments[0].status -eq 'PENDING')
    Add-Result 'Checkout total is in cents' '3000' "$($checkoutJson.data.total_amount)" ($checkoutJson.data.total_amount -eq 3000)
    Add-Result 'Checkout reports simulated payments' 'true' "$($checkoutJson.data.simulated_payments)" ($checkoutJson.data.simulated_payments -eq $true)

    $resume = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $resumeJson = if ($resume.Status -eq 200) { $resume.Body | ConvertFrom-Json } else { $null }
    Add-Result 'Resuming a checkout returns 200' '200' "$($resume.Status)" ($resume.Status -eq 200)
    Add-Result 'Resuming keeps the same payment' "$paymentId" "$($resumeJson.data.payments[0].id)" ($resumeJson.data.payments[0].id -eq $paymentId)

    # ---- Webhook: parallel duplicate, then approved sale ---------------
    $second = Invoke-Api -Method 'POST' -Path '/api/orders' `
        -Body ("{`"raffle_id`":$raffleId,`"numbers`":[3]}") -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $secondOrderId = if ($second.Status -eq 201) { (($second.Body | ConvertFrom-Json).data.id) } else { 0 }
    $secondCheckout = Invoke-Api -Method 'POST' -Path "/api/orders/$secondOrderId/payments" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Second checkout returns 201' '201' "$($secondCheckout.Status)" ($secondCheckout.Status -eq 201)

    $statusAfterCheckouts = Invoke-Fixture @('status', "--raffle=$raffleId")
    $secondPayment = @($statusAfterCheckouts.orders | Where-Object { $_.id -eq $secondOrderId })[0].payments[0]
    $externalId = [string]$secondPayment.external_id

    $webhookBody = "{`"type`":`"payment`",`"data`":{`"id`":`"$externalId`"},`"status`":`"APPROVED`"}"
    $webhookFile = Join-Path $env:TEMP "payments-e2e-webhook-$([guid]::NewGuid().ToString('N')).json"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($webhookFile, $webhookBody, $utf8NoBom)
    $barrierFile = Join-Path $env:TEMP "payments-e2e-barrier-$([guid]::NewGuid().ToString('N')).lock"

    $webhookArgs = @(
        '-s', '-o', 'NUL', '-w', '%{http_code}',
        '-X', 'POST',
        '-H', 'Content-Type: application/json',
        '--data-binary', "@$webhookFile",
        ($BaseUrl + '/api/webhooks/mercadopago')
    )

    $worker = {
        param($curlArgs, $barrier)

        while (-not (Test-Path $barrier)) {
            Start-Sleep -Milliseconds 20
        }

        (& curl.exe @curlArgs 2>$null)
    }

    $jobs = @(
        (Start-Job -ScriptBlock $worker -ArgumentList (,$webhookArgs), $barrierFile),
        (Start-Job -ScriptBlock $worker -ArgumentList (,$webhookArgs), $barrierFile)
    )

    Start-Sleep -Seconds 2
    [IO.File]::WriteAllText($barrierFile, 'go')

    Wait-Job -Job $jobs | Out-Null
    $webhookStatuses = @($jobs | Receive-Job | ForEach-Object { [int]([string]$_).Trim() })
    Remove-Job -Job $jobs -Force
    Remove-Item $webhookFile -Force -ErrorAction SilentlyContinue
    Remove-Item $barrierFile -Force -ErrorAction SilentlyContinue

    Add-Result 'Two parallel webhooks both return 200' '200+200' ($webhookStatuses -join '+') `
        (($webhookStatuses.Count -eq 2) -and ($webhookStatuses[0] -eq 200) -and ($webhookStatuses[1] -eq 200))

    $statusAfterWebhook = Invoke-Fixture @('status', "--raffle=$raffleId")
    Add-Result 'Parallel webhooks sell the number once' '1' "$($statusAfterWebhook.sold)" ($statusAfterWebhook.sold -eq 1)
    Add-Result 'Parallel webhooks create one event row' '1' "$($statusAfterWebhook.events)" ($statusAfterWebhook.events -eq 1)
    $webhookOrder = @($statusAfterWebhook.orders | Where-Object { $_.id -eq $secondOrderId })[0]
    Add-Result 'Parallel webhooks mark the order PAID' 'PAID' "$($webhookOrder.status)" ($webhookOrder.status -eq 'PAID')

    $duplicate = Invoke-Api -Method 'POST' -Path '/api/webhooks/mercadopago' -Body $webhookBody
    Add-Result 'A sequential duplicate webhook returns 200' '200' "$($duplicate.Status)" ($duplicate.Status -eq 200)
    $statusAfterDuplicate = Invoke-Fixture @('status', "--raffle=$raffleId")
    Add-Result 'A duplicate webhook does not sell again' '1' "$($statusAfterDuplicate.sold)" ($statusAfterDuplicate.sold -eq 1)
    Add-Result 'A duplicate webhook keeps one event row' '1' "$($statusAfterDuplicate.events)" ($statusAfterDuplicate.events -eq 1)

    # ---- Verify the first order and read the contract ------------------
    $verified = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments/$paymentId/verify" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $verifiedJson = if ($verified.Status -eq 200) { $verified.Body | ConvertFrom-Json } else { $null }
    Add-Result 'Verification returns 200' '200' "$($verified.Status)" ($verified.Status -eq 200)
    Add-Result 'Verification marks the order PAID' 'PAID' "$($verifiedJson.data.status)" ($verifiedJson.data.status -eq 'PAID')
    Add-Result 'Verification marks the payment APPROVED' 'APPROVED' "$($verifiedJson.data.payments[0].status)" ($verifiedJson.data.payments[0].status -eq 'APPROVED')

    $statusAfterVerify = Invoke-Fixture @('status', "--raffle=$raffleId")
    Add-Result 'Three numbers are SOLD after both approvals' '3' "$($statusAfterVerify.sold)" ($statusAfterVerify.sold -eq 3)
    Add-Result 'No number stays RESERVED' '0' "$($statusAfterVerify.reserved)" ($statusAfterVerify.reserved -eq 0)

    $detail = Invoke-Api -Method 'GET' -Path "/api/orders/$orderId" -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Order detail still comes from orders-service' 'orders-service' `
        $(if (Test-ServiceHeader $detail.Headers 'orders-service') { 'orders-service' } else { 'missing' }) `
        (Test-ServiceHeader $detail.Headers 'orders-service')

    # ---- Ignored and malformed webhooks --------------------------------
    $unknownBody = "{`"type`":`"payment`",`"data`":{`"id`":`"SIM-COP-9999-$($Fixture.token)`"},`"status`":`"APPROVED`"}"
    $unknown = Invoke-Api -Method 'POST' -Path '/api/webhooks/mercadopago' -Body $unknownBody
    Add-Result 'An unknown webhook reference returns 200' '200' "$($unknown.Status)" ($unknown.Status -eq 200)
    Add-Result 'An unknown webhook reference is ignored' 'ignored' "$(($unknown.Body | ConvertFrom-Json).status)" (($unknown.Body | ConvertFrom-Json).status -eq 'ignored')

    $malformed = Invoke-Api -Method 'POST' -Path '/api/webhooks/mercadopago' -Body '{invalid'
    Add-Result 'Malformed webhook JSON returns 400' '400' "$($malformed.Status)" ($malformed.Status -eq 400)

    # ---- Routing surface ------------------------------------------------
    $paymentRoute = Invoke-Api -Method 'GET' -Path "/api/orders/$orderId/payments" -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Payment sub-route is owned by payments-service' 'payments-service' `
        $(if (Test-ServiceHeader $paymentRoute.Headers 'payments-service') { 'payments-service' } else { 'missing' }) `
        (Test-ServiceHeader $paymentRoute.Headers 'payments-service')
    Add-Result 'GET on the checkout path returns 405' '405' "$($paymentRoute.Status)" ($paymentRoute.Status -eq 405)

    $resolve = Invoke-Api -Method 'POST' -Path "/api/admin/orders/$orderId/payments/$paymentId/resolve" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'A regular user cannot resolve a review' '403' "$($resolve.Status)" ($resolve.Status -eq 403)

    # ---- Stop/start isolation ------------------------------------------
    Write-Host 'Stopping payments-service only...' -ForegroundColor Cyan
    & docker compose stop payments-service *> $null
    Start-Sleep -Seconds 3

    $downCheckout = Invoke-Api -Method 'POST' -Path "/api/orders/$orderId/payments" `
        -Body '{}' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Checkout while payments is down returns 503' '503' "$($downCheckout.Status)" ($downCheckout.Status -eq 503)
    Add-Result 'Checkout while down returns a controlled message' 'pagos' "$($downCheckout.Body)" ($downCheckout.Body -match 'Servicio de pagos temporalmente no disponible')

    $downWebhook = Invoke-Api -Method 'POST' -Path '/api/webhooks/mercadopago' -Body '{invalid'
    Add-Result 'Webhook while payments is down returns 503 (retry)' '503' "$($downWebhook.Status)" ($downWebhook.Status -eq 503)

    $authDuringStop = Invoke-Api -Method 'GET' -Path '/api/me' -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Auth still works while payments is down' '200' "$($authDuringStop.Status)" ($authDuringStop.Status -eq 200)

    $catalogDuringStop = Invoke-Api -Method 'GET' -Path '/api/raffles'
    Add-Result 'Catalog still works while payments is down' '200' "$($catalogDuringStop.Status)" ($catalogDuringStop.Status -eq 200)

    $ordersDuringStop = Invoke-Api -Method 'GET' -Path "/api/orders/$orderId" -SessionCookie $sessionCookie -XsrfHeader $xsrf
    Add-Result 'Orders still work while payments is down' '200' "$($ordersDuringStop.Status)" ($ordersDuringStop.Status -eq 200)

    Write-Host 'Starting payments-service...' -ForegroundColor Cyan
    & docker compose start payments-service *> $null
    $healthyAgain = Wait-ServiceHealthy 'rifacloud-payments-service' $StartTimeoutSeconds
    Add-Result 'payments-service becomes healthy again' 'healthy' $(if ($healthyAgain) { 'healthy' } else { 'timeout' }) $healthyAgain

    $recovered = Invoke-Api -Method 'GET' -Path "/api/orders/$orderId" -SessionCookie $sessionCookie -XsrfHeader $xsrf
    $recoveredJson = if ($recovered.Status -eq 200) { $recovered.Body | ConvertFrom-Json } else { $null }
    Add-Result 'The order and its payment survive the restart' 'APPROVED' "$($recoveredJson.data.payments[0].status)" ($recoveredJson.data.payments[0].status -eq 'APPROVED')
} catch {
    Add-Result 'Unexpected error' 'none' $_.Exception.Message $false
} finally {
    if ($null -ne $Fixture) {
        try {
            Invoke-Fixture @(
                'cleanup',
                "--user=$($Fixture.user_id)",
                "--admin=$($Fixture.admin_id)",
                "--raffle=$($Fixture.raffle_id)",
                "--token=$($Fixture.token)"
            ) | Out-Null
        } catch {
            Write-Host "WARN  Fixture cleanup failed: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    # Restore the normal stack (development schema and src/.env gateway).
    Write-Host 'Restoring the normal stack...' -ForegroundColor Cyan
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
