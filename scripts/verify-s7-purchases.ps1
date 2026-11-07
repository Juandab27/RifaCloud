#requires -Version 5.1
<#
.SYNOPSIS
    S7 end-to-end check: purchase confirmation and "Mis compras".

.DESCRIPTION
    Runs the stack on the migrated test schema with the simulated gateway, then
    verifies through Nginx:

      1. A verified approved payment is listed as PAID with its numbers SOLD.
      2. Pending, expired and review orders are listed with the real status and
         never show released/reserved numbers as purchased.
      3. Pagination, ownership isolation and the anonymous 401.
      4. Session survives a reload and an orders-service outage (503).
      5. Order detail exposes raffle, numbers, order id/number, status and dates.

    The real `rifacloud` database is never touched. The normal stack is restored
    in `finally`.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-s7-purchases.ps1
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
$Fixture = $null
$JarA = Join-Path $env:TEMP "s7-a-$([guid]::NewGuid().ToString('N')).txt"
$JarB = Join-Path $env:TEMP "s7-b-$([guid]::NewGuid().ToString('N')).txt"
$EmailA = "s7-e2e-a-$([guid]::NewGuid().ToString('N').Substring(0, 8))@example.invalid"
$EmailB = "s7-e2e-b-$([guid]::NewGuid().ToString('N').Substring(0, 8))@example.invalid"
$Password = 'S7-e2e-password1!'
$UserA = 0
$UserB = 0
$RaffleId = 0

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

function Get-XsrfToken([string]$Jar) {
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
        [string]$Jar = $JarA,
        [bool]$SaveCookies = $true
    )

    $token = [guid]::NewGuid().ToString('N')
    $bodyOut = Join-Path $env:TEMP "s7-body-$token.txt"
    $headerOut = Join-Path $env:TEMP "s7-headers-$token.txt"
    $curlArgs = @(
        '-s', '-D', $headerOut, '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-b', $Jar,
        '-H', "Origin: $BaseUrl",
        '-H', 'Accept: application/json'
    )

    if ($SaveCookies) {
        $curlArgs += @('-c', $Jar)
    }

    $xsrf = Get-XsrfToken $Jar
    if ($xsrf -ne '') {
        $curlArgs += @('-H', "X-XSRF-TOKEN: $xsrf")
    }

    if ($null -ne $Body) {
        $bodyFile = Join-Path $env:TEMP "s7-payload-$token.json"
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

function Register-User {
    param([string]$Jar, [string]$Email, [string]$Name)

    [IO.File]::WriteAllText($Jar, '')
    Invoke-Api -Path '/sanctum/csrf-cookie' -Jar $Jar | Out-Null

    $response = Invoke-Api -Method 'POST' -Path '/api/register' -Jar $Jar -Body @{
        name = $Name
        email = $Email
        phone = '3000000000'
        password = $Password
        password_confirmation = $Password
    }

    # The session id is regenerated on register; refresh the XSRF cookie.
    Invoke-Api -Path '/sanctum/csrf-cookie' -Jar $Jar | Out-Null

    return $response
}

function Find-ListedOrder {
    param([object]$Payload, [int]$OrderId)

    return @($Payload.data | Where-Object { [int]$_.id -eq $OrderId })[0]
}

function Number-Status {
    param([object]$Order, [int]$Number)

    $entry = @($Order.numbers | Where-Object { [int]$_.number -eq $Number })[0]

    if ($null -ne $entry) {
        return [string]$entry.status
    }

    return 'MISSING'
}

try {
    $env:S6_RESERVATION_MINUTES = '10'
    $env:PAYMENT_FAKE_OUTCOME = 'APPROVED'

    Write-Host 'Recreating the stack on the test schema (simulated gateway)...' -ForegroundColor Cyan
    & docker compose @Compose build auth-service raffles-service orders-service 2>&1 | Out-Null
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
    & docker compose @Compose stop scheduler *> $null

    Write-Host 'Creating temporary fixtures...' -ForegroundColor Cyan
    $Fixture = Invoke-Fixture @('create-raffle')
    $RaffleId = [int]$Fixture.raffle_id

    # ---- Two users, browser-like sessions ------------------------------
    $registerA = Register-User $JarA $EmailA 'S7 buyer A'
    $registerB = Register-User $JarB $EmailB 'S7 buyer B'
    Add-Result 'Buyer A registration returns 201' '201' "$($registerA.Status)" ($registerA.Status -eq 201)
    Add-Result 'Buyer B registration returns 201' '201' "$($registerB.Status)" ($registerB.Status -eq 201)

    $meA1 = Invoke-Api -Path '/api/me' -Jar $JarA
    $meA2 = Invoke-Api -Path '/api/me' -Jar $JarA
    $meABody = if ($meA1.Status -eq 200) { $meA1.Body | ConvertFrom-Json } else { $null }
    $UserA = if ($null -ne $meABody) { [int]$meABody.data.id } else { 0 }

    $meBBody = Invoke-Api -Path '/api/me' -Jar $JarB | ForEach-Object { if ($_.Status -eq 200) { $_.Body | ConvertFrom-Json } }
    $UserB = if ($null -ne $meBBody) { [int]$meBBody.data.id } else { 0 }

    Add-Result 'Buyer A session survives the first load' '200' "$($meA1.Status)" ($meA1.Status -eq 200)
    Add-Result 'Buyer A session survives a reload' '200' "$($meA2.Status)" ($meA2.Status -eq 200)

    # ---- Paid purchase (server-side verification) ---------------------
    $paidOrderResponse = Invoke-Api -Method 'POST' -Path '/api/orders' -Jar $JarA -Body @{ raffle_id = $RaffleId; numbers = @(1, 2) }
    $paidOrder = if ($paidOrderResponse.Status -eq 201) { $paidOrderResponse.Body | ConvertFrom-Json } else { $null }
    $paidOrderId = if ($null -ne $paidOrder) { [int]$paidOrder.data.id } else { 0 }

    $checkout = Invoke-Api -Method 'POST' -Path "/api/orders/$paidOrderId/payments" -Jar $JarA -Body @{}
    $checkoutBody = if ($checkout.Status -eq 201) { $checkout.Body | ConvertFrom-Json } else { $null }
    $paymentId = if ($null -ne $checkoutBody) { [int]$checkoutBody.data.payments[0].id } else { 0 }

    $verified = Invoke-Api -Method 'POST' -Path "/api/orders/$paidOrderId/payments/$paymentId/verify" -Jar $JarA -Body @{}
    $verifiedBody = if ($verified.Status -eq 200) { $verified.Body | ConvertFrom-Json } else { $null }

    Add-Result 'Checkout returns 201' '201' "$($checkout.Status)" ($checkout.Status -eq 201)
    Add-Result 'Verified purchase becomes PAID' 'PAID' "$($verifiedBody.data.status)" ($verified.Status -eq 200 -and $verifiedBody.data.status -eq 'PAID')

    # ---- Pending purchase (no payment) --------------------------------
    $pendingResponse = Invoke-Api -Method 'POST' -Path '/api/orders' -Jar $JarA -Body @{ raffle_id = $RaffleId; numbers = @(3) }
    $pendingOrder = if ($pendingResponse.Status -eq 201) { $pendingResponse.Body | ConvertFrom-Json } else { $null }
    $pendingOrderId = if ($null -ne $pendingOrder) { [int]$pendingOrder.data.id } else { 0 }

    # ---- Expired purchase (released numbers) --------------------------
    $expiredFixture = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserA", '--numbers=5', '--kind=no_payment', "--token=$($Fixture.token)")
    $expiredOrderId = [int]$expiredFixture.order_id
    & docker compose @Compose exec -T app php artisan orders:expire-reservations 2>&1 | Out-Null

    # ---- Payment under review (numbers stay reserved) -----------------
    $reviewFixture = Invoke-Fixture @('expired-order', "--raffle=$RaffleId", "--user=$UserA", '--numbers=6', '--kind=review', "--token=$($Fixture.token)")
    $reviewOrderId = [int]$reviewFixture.order_id

    # ---- List contract and statuses -----------------------------------
    $list = Invoke-Api -Path '/api/orders?per_page=10' -Jar $JarA
    $listBody = if ($list.Status -eq 200) { $list.Body | ConvertFrom-Json } else { $null }
    $listedPaid = if ($null -ne $listBody) { Find-ListedOrder $listBody $paidOrderId } else { $null }
    $listedPending = if ($null -ne $listBody) { Find-ListedOrder $listBody $pendingOrderId } else { $null }
    $listedExpired = if ($null -ne $listBody) { Find-ListedOrder $listBody $expiredOrderId } else { $null }
    $listedReview = if ($null -ne $listBody) { Find-ListedOrder $listBody $reviewOrderId } else { $null }

    Add-Result 'My purchases returns 200' '200' "$($list.Status)" ($list.Status -eq 200)
    Add-Result 'The list reports the owner total' '4' "$($listBody.meta.total)" ($listBody.meta.total -ge 4)
    Add-Result 'The list defaults to 10 per page' '10' "$($listBody.meta.per_page)" ($listBody.meta.per_page -eq 10)
    Add-Result 'All four fixture orders are listed' '4' "$(@($listBody.data).Count)" (@($listBody.data | Where-Object { [int]$_.id -in @($paidOrderId, $pendingOrderId, $expiredOrderId, $reviewOrderId) }).Count -eq 4)

    Add-Result 'The paid purchase is PAID' 'PAID' "$($listedPaid.status)" ($listedPaid.status -eq 'PAID')
    Add-Result 'The paid purchase shows the raffle title' 'title' "$($listedPaid.raffle.title)" ($listedPaid.raffle.title -like 'S6 e2e raffle*')
    Add-Result 'The paid purchase shows its order number' 'non-empty' "$($listedPaid.order_number)" ($listedPaid.order_number -ne '')
    Add-Result 'The paid purchase shows the purchase date' 'non-empty' "$($listedPaid.paid_at)" ($listedPaid.paid_at -ne $null)
    Add-Result 'The paid purchase numbers are SOLD' 'SOLD' "$(Number-Status $listedPaid 1)" ((Number-Status $listedPaid 1) -eq 'SOLD')
    Add-Result 'The second paid number is SOLD' 'SOLD' "$(Number-Status $listedPaid 2)" ((Number-Status $listedPaid 2) -eq 'SOLD')

    Add-Result 'The pending purchase is PENDING_PAYMENT' 'PENDING_PAYMENT' "$($listedPending.status)" ($listedPending.status -eq 'PENDING_PAYMENT')
    Add-Result 'The pending number is RESERVED' 'RESERVED' "$(Number-Status $listedPending 3)" ((Number-Status $listedPending 3) -eq 'RESERVED')
    Add-Result 'The pending purchase has no paid_at' 'null' "$($listedPending.paid_at)" ($null -eq $listedPending.paid_at)

    Add-Result 'The expired purchase is EXPIRED' 'EXPIRED' "$($listedExpired.status)" ($listedExpired.status -eq 'EXPIRED')
    Add-Result 'The expired number is AVAILABLE, not purchased' 'AVAILABLE' "$(Number-Status $listedExpired 5)" ((Number-Status $listedExpired 5) -eq 'AVAILABLE')
    Add-Result 'The expired purchase has no paid_at' 'null' "$($listedExpired.paid_at)" ($null -eq $listedExpired.paid_at)

    Add-Result 'The review purchase is PAYMENT_REVIEW' 'PAYMENT_REVIEW' "$($listedReview.status)" ($listedReview.status -eq 'PAYMENT_REVIEW')
    Add-Result 'The review number stays RESERVED' 'RESERVED' "$(Number-Status $listedReview 6)" ((Number-Status $listedReview 6) -eq 'RESERVED')

    $soldInList = @($listBody.data | ForEach-Object { @($_.numbers | Where-Object { $_.status -eq 'SOLD' }) }).Count
    Add-Result 'Only the paid purchase reports SOLD numbers' '2' "$soldInList" ($soldInList -eq 2)

    # ---- Detail contract ----------------------------------------------
    $detail = Invoke-Api -Path "/api/orders/$paidOrderId" -Jar $JarA
    $detailBody = if ($detail.Status -eq 200) { $detail.Body | ConvertFrom-Json } else { $null }
    Add-Result 'The purchase detail returns 200' '200' "$($detail.Status)" ($detail.Status -eq 200)
    Add-Result 'The detail shows the raffle' 'title' "$($detailBody.data.raffle.title)" ($detailBody.data.raffle.title -ne '')
    Add-Result 'The detail shows the order id' "$paidOrderId" "$($detailBody.data.id)" ([int]$detailBody.data.id -eq $paidOrderId)
    Add-Result 'The detail shows the order number' 'non-empty' "$($detailBody.data.order_number)" ($detailBody.data.order_number -ne '')
    Add-Result 'The detail shows PAID and paid_at' 'PAID' "$($detailBody.data.status)" ($detailBody.data.status -eq 'PAID' -and $detailBody.data.paid_at -ne $null)

    # ---- Pagination ----------------------------------------------------
    $pageOne = Invoke-Api -Path '/api/orders?page=1&per_page=2' -Jar $JarA
    $pageTwo = Invoke-Api -Path '/api/orders?page=2&per_page=2' -Jar $JarA
    $pageOneBody = $pageOne.Body | ConvertFrom-Json
    $pageTwoBody = $pageTwo.Body | ConvertFrom-Json
    $pageOneIds = @($pageOneBody.data | ForEach-Object { [int]$_.id })
    $pageTwoIds = @($pageTwoBody.data | ForEach-Object { [int]$_.id })

    Add-Result 'per_page=2 returns two orders' '2' "$($pageOneIds.Count)" ($pageOneIds.Count -eq 2)
    Add-Result 'The pagination meta reports multiple pages' '>=2' "$($pageOneBody.meta.last_page)" ($pageOneBody.meta.last_page -ge 2)
    Add-Result 'Pages do not repeat orders' 'disjoint' (($pageOneIds + $pageTwoIds) -join ',') (($pageOneIds | Where-Object { $pageTwoIds -contains $_ }).Count -eq 0)

    $clamped = (Invoke-Api -Path '/api/orders?per_page=100' -Jar $JarA).Body | ConvertFrom-Json
    Add-Result 'per_page is clamped to 50' '50' "$($clamped.meta.per_page)" ($clamped.meta.per_page -eq 50)

    # ---- Ownership -----------------------------------------------------
    $listB = Invoke-Api -Path '/api/orders' -Jar $JarB
    $listBBody = if ($listB.Status -eq 200) { $listB.Body | ConvertFrom-Json } else { $null }
    $detailB = Invoke-Api -Path "/api/orders/$paidOrderId" -Jar $JarB

    Add-Result 'Buyer B list returns 200' '200' "$($listB.Status)" ($listB.Status -eq 200)
    Add-Result 'Buyer B sees no orders from buyer A' '0' "$($listBBody.meta.total)" ($listBBody.meta.total -eq 0)
    Add-Result "Buyer B cannot read buyer A's detail" '404' "$($detailB.Status)" ($detailB.Status -eq 404)

    $emptyJar = Join-Path $env:TEMP 's7-empty-jar.txt'
    [IO.File]::WriteAllText($emptyJar, '')
    $anon = Invoke-Api -Path '/api/orders' -Jar $emptyJar
    Add-Result 'Anonymous list returns 401' '401' "$($anon.Status)" ($anon.Status -eq 401)

    # ---- Orders-service outage ----------------------------------------
    Write-Host 'Stopping orders-service only...' -ForegroundColor Cyan
    & docker compose @Compose stop orders-service *> $null
    Start-Sleep -Seconds 2

    $downList = Invoke-Api -Path '/api/orders' -Jar $JarA
    $downMe = Invoke-Api -Path '/api/me' -Jar $JarA
    $downCatalog = Invoke-Api -Path '/api/raffles' -Jar $JarA

    Add-Result 'List while orders is down returns 503' '503' "$($downList.Status)" ($downList.Status -eq 503)
    Add-Result 'List while down returns a controlled message' 'ordenes' "$($downList.Body)" ($downList.Body -match 'Servicio de ordenes temporalmente no disponible')
    Add-Result 'Auth still works while orders is down' '200' "$($downMe.Status)" ($downMe.Status -eq 200)
    Add-Result 'Catalog still works while orders is down' '200' "$($downCatalog.Status)" ($downCatalog.Status -eq 200)

    & docker compose @Compose start orders-service *> $null
    $healthy = Wait-ServiceHealthy 'rifacloud-orders-service' $StartTimeoutSeconds
    $recovered = $null
    for ($attempt = 0; $attempt -lt 12; $attempt++) {
        Start-Sleep -Seconds 2
        $recovered = Invoke-Api -Path '/api/orders' -Jar $JarA
        if ($recovered.Status -eq 200) {
            break
        }
    }
    Add-Result 'orders-service becomes healthy again' 'healthy' $(if ($healthy) { 'healthy' } else { 'timeout' }) $healthy
    Add-Result 'My purchases works again after restart' '200' "$($recovered.Status)" ($recovered.Status -eq 200)
} catch {
    Add-Result 'Unexpected error' 'none' $_.Exception.Message $false
} finally {
    if ($null -ne $Fixture) {
        try {
            Invoke-Fixture @(
                'cleanup',
                "--raffle=$RaffleId",
                "--admin=$($Fixture.admin_id)",
                "--email=$EmailA"
            ) | Out-Null
        } catch {
            Write-Host "WARN  Fixture cleanup failed: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    # Buyer B has no orders; remove the account and its sessions.
    $map = @{}
    Get-Content (Join-Path $RepoRoot '.env') | ForEach-Object {
        if ($_ -match '^([A-Z0-9_]+)=(.*)$') { $map[$matches[1]] = $matches[2] }
    }
    if ($UserB -gt 0 -and $map.ContainsKey('DB_PASSWORD')) {
        & docker compose @Compose exec -T -e MYSQL_PWD="$($map['DB_PASSWORD'])" mysql mysql -urifacloud -N -e "DELETE FROM rifacloud_test.sessions WHERE user_id=$UserB; DELETE FROM rifacloud_test.users WHERE id=$UserB;" 2>$null | Out-Null
    }

    foreach ($file in @($JarA, $JarB, (Join-Path $env:TEMP 's7-empty-jar.txt'))) {
        Remove-Item $file -Force -ErrorAction SilentlyContinue
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
