#requires -Version 5.1
<#
.SYNOPSIS
    End-to-end verification of the S2 raffle administration extraction.

.DESCRIPTION
    Exercises, through Nginx on the real stack:

      1. Admin auth boundary (401 / 403 / 419) and CSRF parity.
      2. Full raffle CRUD, publish/close, validation and number integrity.
      3. Public catalog compatibility.
      4. Reservation regression in the monolith (orders still own numbers).
      5. Stop/start isolation: only raffles-service is stopped; auth, orders
         and webhooks must keep working, and admin/catalog must fail with a
         controlled JSON 503.

    Temporary ADMIN/USER fixtures are inserted directly in the main database
    because registration only creates USER accounts. They use a dedicated
    `raffles-e2e-*@example.invalid` prefix and are deleted in `finally`,
    together with every order, payment item/raffle created by them.

    It never stops mysql, app, scheduler or nginx, never runs migrations and
    never touches pre-existing rows. Payment creation is intentionally not
    exercised: payments remain outside S2 scope.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/verify-raffles-service-admin.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$StopTimeoutSeconds = 30,
    [int]$StartTimeoutSeconds = 90
)

# Docker writes progress to stderr; PowerShell 5.1 would treat that as a
# terminating error under 'Stop'. Assertions verify the real outcomes.
$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
$Results = New-Object System.Collections.Generic.List[object]
$Warnings = New-Object System.Collections.Generic.List[string]
$Token = [guid]::NewGuid().ToString('N').Substring(0, 8)
$AdminEmail = "raffles-e2e-a$Token@example.invalid"
$UserEmail = "raffles-e2e-u$Token@example.invalid"
$AdminPassword = "E2e-$Token-admin"
$UserPassword = "E2e-$Token-user"
$AdminJar = Join-Path $env:TEMP "rifacloud-raffles-admin-$Token.txt"
$UserJar = Join-Path $env:TEMP "rifacloud-raffles-user-$Token.txt"
$AnonymousJar = Join-Path $env:TEMP "rifacloud-raffles-anon-$Token.txt"
$AdminCreated = $false
$UserCreated = $false
$MainRaffleId = $null
$ReserveRaffleId = $null
$OrderId = $null

function Add-Result {
    param(
        [string]$Name,
        [string]$Expected,
        [string]$Actual,
        [bool]$Pass
    )

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

function Add-Warning {
    param([string]$Message)
    $Warnings.Add($Message) | Out-Null
    Write-Host "WARN  $Message" -ForegroundColor Yellow
}

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$Body = $null,
        [string]$Jar = '',
        [string]$XsrfToken = '',
        [hashtable]$Headers = @{}
    )

    $bodyOut = Join-Path $env:TEMP ("raffles-admin-body-$([guid]::NewGuid().ToString('N')).json")
    $curlArgs = @(
        '-s', '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-H', 'Accept: application/json'
    )

    # Only send Origin when a session is involved. Anonymous webhook calls must
    # stay stateless, exactly like a real provider callback.
    if ($Jar -ne '' -or $XsrfToken -ne '') {
        $curlArgs += @('-H', "Origin: $BaseUrl")
    }

    if ($Jar -ne '') {
        $curlArgs += @('-c', $Jar, '-b', $Jar)
    }

    if ($XsrfToken -ne '') {
        $curlArgs += @('-H', "X-XSRF-TOKEN: $XsrfToken")
    }

    foreach ($name in $Headers.Keys) {
        $curlArgs += @('-H', "$name`: $($Headers[$name])")
    }

    if ($null -ne $Body) {
        $bodyFile = Join-Path $env:TEMP ("raffles-admin-payload-$([guid]::NewGuid().ToString('N')).json")
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
    param(
        [string]$Jar,
        [string]$Name
    )

    if (-not (Test-Path $Jar)) {
        return ''
    }

    $line = Get-Content $Jar | Where-Object { $_ -match "`t$Name`t" } | Select-Object -Last 1

    if (-not $line) {
        return ''
    }

    $raw = ($line -split "`t")[-1]
    return [uri]::UnescapeDataString($raw)
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
    param([string[]]$ExtraEnv, [string]$Action)

    $dockerArgs = @('compose') + $Compose + @(
        'run', '--rm', '-T', '--no-deps',
        '-e', 'DB_DATABASE=rifacloud_test'
    )

    foreach ($entry in $ExtraEnv) {
        $dockerArgs += @('-e', $entry)
    }

    $dockerArgs += @(
        '-v', "${RepoRoot}/scripts:/scripts:ro",
        'app', 'php', '/scripts/raffles-admin-e2e-fixture.php', $Action
    )

    return @(& docker @dockerArgs 2>$null)
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

function Login {
    param(
        [string]$Email,
        [string]$Password,
        [string]$Jar
    )

    Remove-Item $Jar -Force -ErrorAction SilentlyContinue
    $csrf = Invoke-Api -Method GET -Path '/sanctum/csrf-cookie' -Jar $Jar
    $token = Get-CookieValue -Jar $Jar -Name 'XSRF-TOKEN'
    $body = @{ email = $Email; password = $Password } | ConvertTo-Json -Compress
    $login = Invoke-Api -Method POST -Path '/api/login' -Body $body -Jar $Jar -XsrfToken $token

    return [pscustomobject]@{
        CsrfStatus = $csrf.Status
        Response   = $login
        Token      = (Get-CookieValue -Jar $Jar -Name 'XSRF-TOKEN')
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

Push-Location $RepoRoot

try {
    Write-Host "`n== Recreating the stack on rifacloud_test ==" -ForegroundColor Cyan
    & docker compose @Compose build auth-service raffles-service orders-service payments-service 2>&1 | Out-Null
    & docker compose @Compose up -d --force-recreate app auth-service raffles-service orders-service payments-service nginx 2>&1 | Out-Null
    # The test must control expiry/reconcile timing and must not queue mail for fixtures.
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

    Write-Host "`n== 0. Preconditions ==" -ForegroundColor Cyan
    $catalog = Wait-ForStatus -Path '/api/raffles' -Expected @(200) -TimeoutSeconds 30
    Add-Result 'Catalog is available before the test' '200' "$($catalog.Status)" ($catalog.Status -eq 200)

    # ---- Temporary ADMIN -------------------------------------------------
    $createOutput = Invoke-Fixture -Action 'create' -ExtraEnv @(
        "RAFFLES_E2E_EMAIL=$AdminEmail",
        "RAFFLES_E2E_PASSWORD=$AdminPassword",
        'RAFFLES_E2E_ROLE=ADMIN'
    )
    $adminLine = @($createOutput) | Where-Object { $_ -match '^\s*\{' } | Select-Object -Last 1
    $adminFixture = Get-Json $adminLine

    if ($null -eq $adminFixture -or $null -eq $adminFixture.id) {
        throw "Could not create the temporary admin fixture. Output: $($createOutput -join ' ')"
    }

    $AdminCreated = $true
    Add-Result 'Temporary ADMIN fixture created' 'id>0' "$($adminFixture.id)" ([int]$adminFixture.id -gt 0)

    $adminLogin = Login -Email $AdminEmail -Password $AdminPassword -Jar $AdminJar
    Add-Result 'GET /sanctum/csrf-cookie' '204' "$($adminLogin.CsrfStatus)" ($adminLogin.CsrfStatus -eq 204)
    Add-Result 'ADMIN login through auth-service' '200' "$($adminLogin.Response.Status)" ($adminLogin.Response.Status -eq 200)
    $adminJson = Get-Json $adminLogin.Response.Body
    $adminToken = $adminLogin.Token

    if ($null -ne $adminJson) {
        Add-Result 'Login returns the ADMIN role' 'ADMIN' "$($adminJson.data.role)" ($adminJson.data.role -eq 'ADMIN')
    }

    # ---- Authorization boundary -----------------------------------------
    Write-Host "`n== 1. Admin authorization boundary ==" -ForegroundColor Cyan
    $anonymous = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $AnonymousJar
    Add-Result 'Anonymous admin list' '401' "$($anonymous.Status)" ($anonymous.Status -eq 401)

    $tamperedJar = Join-Path $env:TEMP "rifacloud-raffles-tampered-$Token.txt"
    $sessionCookie = Get-CookieValue -Jar $AdminJar -Name 'rifacloud-session'

    if ($sessionCookie.Length -gt 6) {
        $tamperedCookie = $sessionCookie.Substring(0, $sessionCookie.Length - 6)
        Set-Content -Path $tamperedJar -Value "# Netscape HTTP Cookie File"
        Add-Content -Path $tamperedJar -Value ("localhost`tFALSE`t/`tFALSE`t0`trifacloud-session`t$tamperedCookie")
        $tampered = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $tamperedJar
        Add-Result 'Tampered session cookie' '401' "$($tampered.Status)" ($tampered.Status -eq 401)
    } else {
        Add-Result 'Session cookie was issued' 'non-empty' 'empty' $false
    }

    Remove-Item $tamperedJar -Force -ErrorAction SilentlyContinue

    $noCsrf = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body '{"title":"x"}' -Jar $AdminJar
    Add-Result 'POST without CSRF token' '419' "$($noCsrf.Status)" ($noCsrf.Status -eq 419)

    # ---- Admin CRUD ------------------------------------------------------
    Write-Host "`n== 2. Admin CRUD and number integrity ==" -ForegroundColor Cyan
    $list = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $AdminJar -XsrfToken $adminToken
    $listJson = Get-Json $list.Body
    Add-Result 'Admin list' '200' "$($list.Status)" ($list.Status -eq 200)

    if ($null -ne $listJson) {
        Add-Result 'Admin list keeps data/links/meta' 'all present' 'checked' (
            $null -ne $listJson.data -and $null -ne $listJson.links -and $null -ne $listJson.meta
        )
        Add-Result 'Admin list keeps per_page=15' '15' "$($listJson.meta.per_page)" ([int]$listJson.meta.per_page -eq 15)
    }

    $start = (Get-Date).AddHours(-1).ToUniversalTime().ToString('yyyy-MM-dd HH:mm:ss')
    $end = (Get-Date).AddHours(2).ToUniversalTime().ToString('yyyy-MM-dd HH:mm:ss')
    $mainPayload = @{
        title            = "Raffles E2E main $Token"
        description      = 'Temporary raffle for the S2 E2E test.'
        price_per_number = 5000
        min_number       = 1
        max_number       = 20
        starts_at        = $start
        ends_at          = $end
    } | ConvertTo-Json -Compress
    $created = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body $mainPayload -Jar $AdminJar -XsrfToken $adminToken
    $createdJson = Get-Json $created.Body
    Add-Result 'POST /api/admin/raffles' '201' "$($created.Status)" ($created.Status -eq 201)

    if ($null -ne $createdJson -and $null -ne $createdJson.data) {
        $MainRaffleId = [int]$createdJson.data.id
        Add-Result 'New raffle starts DRAFT' 'DRAFT' "$($createdJson.data.status)" ($createdJson.data.status -eq 'DRAFT')
        Add-Result 'total_numbers is inclusive' '20' "$($createdJson.data.total_numbers)" ([int]$createdJson.data.total_numbers -eq 20)
        Add-Result 'numbers_count reflects generation' '20' "$($createdJson.data.numbers_count)" ([int]$createdJson.data.numbers_count -eq 20)
    }

    $shown = Invoke-Api -Method GET -Path "/api/admin/raffles/$MainRaffleId" -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'GET admin raffle detail' '200' "$($shown.Status)" ($shown.Status -eq 200)

    $updatePayload = @{
        title            = "Raffles E2E updated $Token"
        description      = 'Updated by the S2 E2E test.'
        price_per_number = 7500
        min_number       = 1
        max_number       = 20
        starts_at        = $start
        ends_at          = $end
    } | ConvertTo-Json -Compress
    $updated = Invoke-Api -Method PUT -Path "/api/admin/raffles/$MainRaffleId" -Body $updatePayload -Jar $AdminJar -XsrfToken $adminToken
    $updatedJson = Get-Json $updated.Body
    Add-Result 'PUT drafts updates the raffle' '200' "$($updated.Status)" ($updated.Status -eq 200)

    if ($null -ne $updatedJson) {
        Add-Result 'Price update persists in COP' '7500' "$($updatedJson.data.price_per_number)" ([int]$updatedJson.data.price_per_number -eq 7500)
    }

    $badRange = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body (@{
        title = 'bad-range'; price_per_number = 100; min_number = 50; max_number = 10
    } | ConvertTo-Json -Compress) -Jar $AdminJar -XsrfToken $adminToken
    $badRangeJson = Get-Json $badRange.Body
    Add-Result 'min > max rejected' '422 on max_number' "$($badRange.Status)" (
        $badRange.Status -eq 422 -and $null -ne $badRangeJson -and $null -ne $badRangeJson.errors.max_number
    )

    $tooLarge = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body (@{
        title = 'too-large'; price_per_number = 100; min_number = 1; max_number = 10001
    } | ConvertTo-Json -Compress) -Jar $AdminJar -XsrfToken $adminToken
    $tooLargeJson = Get-Json $tooLarge.Body
    Add-Result 'Number range limit enforced' '422 on max_number' "$($tooLarge.Status)" (
        $tooLarge.Status -eq 422 -and $null -ne $tooLargeJson -and $null -ne $tooLargeJson.errors.max_number
    )

    $badSchedule = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body (@{
        title = 'bad-schedule'; price_per_number = 100; min_number = 1; max_number = 10
        starts_at = $start; ends_at = $start
    } | ConvertTo-Json -Compress) -Jar $AdminJar -XsrfToken $adminToken
    $badScheduleJson = Get-Json $badSchedule.Body
    Add-Result 'Equal start/end rejected' '422 on ends_at' "$($badSchedule.Status)" (
        $badSchedule.Status -eq 422 -and $null -ne $badScheduleJson -and $null -ne $badScheduleJson.errors.ends_at
    )

    $missingTitle = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body (@{
        price_per_number = 100; min_number = 1; max_number = 10
    } | ConvertTo-Json -Compress) -Jar $AdminJar -XsrfToken $adminToken
    $missingTitleJson = Get-Json $missingTitle.Body
    Add-Result 'Missing title rejected' '422 on title' "$($missingTitle.Status)" (
        $missingTitle.Status -eq 422 -and $null -ne $missingTitleJson -and $null -ne $missingTitleJson.errors.title
    )

    $malformed = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body '{' -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Malformed JSON' '400' "$($malformed.Status)" ($malformed.Status -eq 400)

    $wrongMethod = Invoke-Api -Method PATCH -Path '/api/admin/raffles' -Body '{}' -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Unsupported method' '405' "$($wrongMethod.Status)" ($wrongMethod.Status -eq 405)

    $missing = Invoke-Api -Method GET -Path '/api/admin/raffles/999999999' -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Unknown raffle' '404' "$($missing.Status)" ($missing.Status -eq 404)

    # Publish / close / guards.
    $published = Invoke-Api -Method POST -Path "/api/admin/raffles/$MainRaffleId/publish" -Jar $AdminJar -XsrfToken $adminToken
    $publishedJson = Get-Json $published.Body
    Add-Result 'Publish a DRAFT raffle' '200 PUBLISHED' "$($published.Status)" (
        $published.Status -eq 200 -and $null -ne $publishedJson -and $publishedJson.data.status -eq 'PUBLISHED'
    )

    $updatePublished = Invoke-Api -Method PUT -Path "/api/admin/raffles/$MainRaffleId" -Body $updatePayload -Jar $AdminJar -XsrfToken $adminToken
    $updatePublishedJson = Get-Json $updatePublished.Body
    Add-Result 'PUBLISHED cannot be reconfigured' '422 on status' "$($updatePublished.Status)" (
        $updatePublished.Status -eq 422 -and $null -ne $updatePublishedJson -and $null -ne $updatePublishedJson.errors.status
    )

    $deletePublished = Invoke-Api -Method DELETE -Path "/api/admin/raffles/$MainRaffleId" -Jar $AdminJar -XsrfToken $adminToken
    $deletePublishedJson = Get-Json $deletePublished.Body
    Add-Result 'PUBLISHED cannot be deleted' '422 on status' "$($deletePublished.Status)" (
        $deletePublished.Status -eq 422 -and $null -ne $deletePublishedJson -and $null -ne $deletePublishedJson.errors.status
    )

    $closed = Invoke-Api -Method POST -Path "/api/admin/raffles/$MainRaffleId/close" -Jar $AdminJar -XsrfToken $adminToken
    $closedJson = Get-Json $closed.Body
    Add-Result 'Close a PUBLISHED raffle' '200 CLOSED' "$($closed.Status)" (
        $closed.Status -eq 200 -and $null -ne $closedJson -and $closedJson.data.status -eq 'CLOSED'
    )

    $doomed = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body (@{
        title = "Raffles E2E doomed $Token"; description = 'Temporary'; price_per_number = 1000
        min_number = 1; max_number = 10
    } | ConvertTo-Json -Compress) -Jar $AdminJar -XsrfToken $adminToken
    $doomedJson = Get-Json $doomed.Body
    $doomedId = if ($null -ne $doomedJson) { [int]$doomedJson.data.id } else { 0 }
    Add-Result 'Create a deletable DRAFT raffle' '201' "$($doomed.Status)" ($doomed.Status -eq 201)

    $deleted = Invoke-Api -Method DELETE -Path "/api/admin/raffles/$doomedId" -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Delete a DRAFT raffle' '204' "$($deleted.Status)" ($deleted.Status -eq 204)

    $gone = Invoke-Api -Method GET -Path "/api/admin/raffles/$doomedId" -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Deleted raffle is gone' '404' "$($gone.Status)" ($gone.Status -eq 404)

    # ---- Non-admin denied ------------------------------------------------
    Write-Host "`n== 3. Non-admin is denied ==" -ForegroundColor Cyan
    $register = Invoke-Api -Method GET -Path '/sanctum/csrf-cookie' -Jar $UserJar
    $userToken = Get-CookieValue -Jar $UserJar -Name 'XSRF-TOKEN'
    $registerPayload = @{
        name                  = 'Raffles E2E USER'
        email                 = $UserEmail
        phone                 = '0000000000'
        password              = $UserPassword
        password_confirmation = $UserPassword
        privacy_consent       = $true
        terms_consent         = $true
        marketing_consent     = $false
        consent_version       = if ($env:LEGAL_CONSENT_VERSION) { $env:LEGAL_CONSENT_VERSION } else { '2026-11-11' }
    } | ConvertTo-Json -Compress
    $registered = Invoke-Api -Method POST -Path '/api/register' -Body $registerPayload -Jar $UserJar -XsrfToken $userToken
    $registeredJson = Get-Json $registered.Body
    Add-Result 'Register a temporary USER' '201' "$($registered.Status)" ($registered.Status -eq 201)

    if ($registered.Status -eq 201) {
        $UserCreated = $true
        Add-Result 'Registered user has USER role' 'USER' "$($registeredJson.data.role)" ($registeredJson.data.role -eq 'USER')
    }

    $userToken = Get-CookieValue -Jar $UserJar -Name 'XSRF-TOKEN'
    $userList = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $UserJar -XsrfToken $userToken
    Add-Result 'USER cannot list admin raffles' '403' "$($userList.Status)" ($userList.Status -eq 403)

    $userWrite = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body $mainPayload -Jar $UserJar -XsrfToken $userToken
    Add-Result 'USER cannot create raffles' '403' "$($userWrite.Status)" ($userWrite.Status -eq 403)

    # ---- Reservation regression (orders domain) --------------------------
    Write-Host "`n== 4. Reservations stay in the orders domain ==" -ForegroundColor Cyan
    $reservePayload = @{
        title            = "Raffles E2E reserve $Token"
        description      = 'Temporary raffle for the reservation regression.'
        price_per_number = 4000
        min_number       = 1
        max_number       = 10
        starts_at        = $start
        ends_at          = $end
    } | ConvertTo-Json -Compress
    $reserveRaffle = Invoke-Api -Method POST -Path '/api/admin/raffles' -Body $reservePayload -Jar $AdminJar -XsrfToken $adminToken
    $reserveRaffleJson = Get-Json $reserveRaffle.Body

    if ($null -ne $reserveRaffleJson -and $null -ne $reserveRaffleJson.data) {
        $ReserveRaffleId = [int]$reserveRaffleJson.data.id
    }

    Add-Result 'Create a raffle for reservation' '201' "$($reserveRaffle.Status)" ($reserveRaffle.Status -eq 201)
    $reservePublish = Invoke-Api -Method POST -Path "/api/admin/raffles/$ReserveRaffleId/publish" -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Publish the reservation raffle' '200' "$($reservePublish.Status)" ($reservePublish.Status -eq 200)

    $userToken = Get-CookieValue -Jar $UserJar -Name 'XSRF-TOKEN'
    $orderPayload = @{ raffle_id = $ReserveRaffleId; numbers = @(1, 2) } | ConvertTo-Json -Compress
    $order = Invoke-Api -Method POST -Path '/api/orders' -Body $orderPayload -Jar $UserJar -XsrfToken $userToken
    $orderJson = Get-Json $order.Body
    Add-Result 'POST /api/orders reserves numbers' '201' "$($order.Status)" ($order.Status -eq 201)

    if ($null -ne $orderJson -and $null -ne $orderJson.data) {
        $OrderId = [int]$orderJson.data.id
        Add-Result 'New order is PENDING_PAYMENT' 'PENDING_PAYMENT' "$($orderJson.data.status)" ($orderJson.data.status -eq 'PENDING_PAYMENT')
        Add-Result 'Order total is price x numbers' '8000' "$($orderJson.data.total_amount)" ([int]$orderJson.data.total_amount -eq 8000)
        Add-Result 'Order contains both numbers' '2' "$(@($orderJson.data.numbers).Count)" (@($orderJson.data.numbers).Count -eq 2)
    }

    $numbers = Invoke-Api -Method GET -Path "/api/raffles/$ReserveRaffleId/numbers?page=1&per_page=100"
    $numbersJson = Get-Json $numbers.Body
    Add-Result 'Catalog numbers endpoint still works' '200' "$($numbers.Status)" ($numbers.Status -eq 200)

    if ($null -ne $numbersJson) {
        Add-Result 'Reserved count comes from raffle_numbers' '2' "$($numbersJson.meta.counts.reserved)" ([int]$numbersJson.meta.counts.reserved -eq 2)
        $reserved = @($numbersJson.data | Where-Object { $_.status -eq 'RESERVED' } | ForEach-Object { [int]$_.number } | Sort-Object)
        Add-Result 'Numbers 1 and 2 are RESERVED' '1,2' ($reserved -join ',') (($reserved -join ',') -eq '1,2')
    }

    $doubleOrder = Invoke-Api -Method POST -Path '/api/orders' -Body $orderPayload -Jar $UserJar -XsrfToken $userToken
    $doubleOrderJson = Get-Json $doubleOrder.Body
    Add-Result 'Double reservation is rejected' '409 NUMBERS_UNAVAILABLE' "$($doubleOrder.Status)" (
        $doubleOrder.Status -eq 409 -and $null -ne $doubleOrderJson -and $doubleOrderJson.code -eq 'NUMBERS_UNAVAILABLE'
    )

    $ownerOrder = Invoke-Api -Method GET -Path "/api/orders/$OrderId" -Jar $UserJar
    $ownerOrderJson = Get-Json $ownerOrder.Body
    Add-Result 'Owner reads the order' '200' "$($ownerOrder.Status)" ($ownerOrder.Status -eq 200)
    if ($null -ne $ownerOrderJson) {
        Add-Result 'Order detail keeps its id' "$OrderId" "$($ownerOrderJson.data.id)" ([int]$ownerOrderJson.data.id -eq $OrderId)
    }

    $anonymousOrder = Invoke-Api -Method GET -Path "/api/orders/$OrderId" -Jar $AnonymousJar
    Add-Result 'Anonymous cannot read the order' '401' "$($anonymousOrder.Status)" ($anonymousOrder.Status -eq 401)

    $otherOrder = Invoke-Api -Method GET -Path "/api/orders/$OrderId" -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Another user cannot read the order' '404' "$($otherOrder.Status)" ($otherOrder.Status -eq 404)

    $webhook = Invoke-Api -Method POST -Path '/api/webhooks/mercadopago' -Body '{}'
    Add-Result 'Webhook rejects an empty payload' '400' "$($webhook.Status)" ($webhook.Status -eq 400)

    # ---- Stop/start isolation -------------------------------------------
    Write-Host "`n== 5. Stop/start isolation ==" -ForegroundColor Cyan
    & docker compose @Compose stop raffles-service | Out-Null
    Add-Result 'docker compose stop raffles-service' 'exit 0' "$LASTEXITCODE" ($LASTEXITCODE -eq 0)

    $catalogDown = Wait-ForStatus -Path '/api/raffles' -Expected @(502, 503) -TimeoutSeconds $StopTimeoutSeconds
    Add-Result 'Catalog fails closed while the service is down' '502/503' "$($catalogDown.Status)" ($catalogDown.Status -in @(502, 503))
    if ($catalogDown.Status -eq 502) {
        Add-Warning 'Nginx returned 502 instead of the controlled 503 while raffles-service was stopped.'
    }
    if ($catalogDown.Status -eq 503) {
        $catalogDownJson = Get-Json $catalogDown.Body
        Add-Result 'Catalog outage returns controlled JSON' 'message present' 'checked' (
            $null -ne $catalogDownJson -and $null -ne $catalogDownJson.message
        )
    }

    $adminDown = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Admin fails closed while the service is down' '502/503' "$($adminDown.Status)" ($adminDown.Status -in @(502, 503))

    $authDown = Invoke-Api -Method POST -Path '/api/login' -Body '{"email":"nobody@example.invalid","password":"wrong"}'
    Add-Result 'Auth login still works while raffles is down' '422' "$($authDown.Status)" ($authDown.Status -eq 422)

    $meDown = Invoke-Api -Method GET -Path '/api/me' -Jar $AdminJar
    Add-Result 'Auth /me still works while raffles is down' '200' "$($meDown.Status)" ($meDown.Status -eq 200)

    $orderDown = Invoke-Api -Method GET -Path "/api/orders/$OrderId" -Jar $UserJar
    Add-Result 'Orders still work while raffles is down' '200' "$($orderDown.Status)" ($orderDown.Status -eq 200)

    $webhookDown = Invoke-Api -Method POST -Path '/api/webhooks/mercadopago' -Body '{}'
    Add-Result 'Webhook still works while raffles is down' '400' "$($webhookDown.Status)" ($webhookDown.Status -eq 400)

    & docker compose @Compose start raffles-service | Out-Null
    Add-Result 'docker compose start raffles-service' 'exit 0' "$LASTEXITCODE" ($LASTEXITCODE -eq 0)

    $catalogUp = Wait-ForStatus -Path '/api/raffles' -Expected @(200) -TimeoutSeconds $StartTimeoutSeconds
    Add-Result 'Catalog recovers after restart' '200' "$($catalogUp.Status)" ($catalogUp.Status -eq 200)

    $adminUp = Invoke-Api -Method GET -Path '/api/admin/raffles' -Jar $AdminJar -XsrfToken $adminToken
    Add-Result 'Admin recovers after restart' '200' "$($adminUp.Status)" ($adminUp.Status -eq 200)

    $orderUp = Invoke-Api -Method GET -Path "/api/orders/$OrderId" -Jar $UserJar
    Add-Result 'Order survives the restart' '200' "$($orderUp.Status)" ($orderUp.Status -eq 200)

    $numbersUp = Invoke-Api -Method GET -Path "/api/raffles/$ReserveRaffleId/numbers?page=1&per_page=100"
    $numbersUpJson = Get-Json $numbersUp.Body
    Add-Result 'Reservation survives the restart' 'reserved=2' "$($numbersUp.Status)" (
        $numbersUp.Status -eq 200 -and $null -ne $numbersUpJson -and [int]$numbersUpJson.meta.counts.reserved -eq 2
    )
} catch {
    Add-Result 'Unexpected error' 'no exception' $_.Exception.Message $false
} finally {
    # Never leave the catalog stopped because a check failed.
    & docker compose @Compose start raffles-service | Out-Null

    Write-Host "`n== Cleanup ==" -ForegroundColor Cyan
    $emails = @($AdminEmail, $UserEmail) -join ','
    $cleanupOutput = Invoke-Fixture -Action 'cleanup' -ExtraEnv @("RAFFLES_E2E_EMAILS=$emails")
    foreach ($line in @($cleanupOutput)) {
        if (-not [string]::IsNullOrWhiteSpace($line)) {
            Write-Host "  $line"
        }
    }

    Remove-Item $AdminJar, $UserJar, $AnonymousJar -Force -ErrorAction SilentlyContinue

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

    Pop-Location
}

$failed = @($Results | Where-Object { -not $_.Pass })

Write-Host "`n== Summary ==" -ForegroundColor Cyan
Write-Host ("PASS={0} FAIL={1} WARN={2}" -f ($Results.Count - $failed.Count), $failed.Count, $Warnings.Count)

if ($failed.Count -gt 0) {
    $failed | Format-Table -AutoSize
    exit 1
}

Write-Host 'S2 raffle administration E2E: OK' -ForegroundColor Green
exit 0
