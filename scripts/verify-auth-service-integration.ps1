#requires -Version 5.1
<#
.SYNOPSIS
    End-to-end integration test for auth-service against rifacloud_test.

.DESCRIPTION
    Starts a disposable auth-service container against the test database,
    verifies CSRF, login, register, /me and logout contracts, then deletes the
    test user and its sessions. The real `rifacloud` database is never touched.

    Requires the image to exist:
        docker compose build auth-service
#>

# Docker writes progress to stderr; PowerShell 5.1 would treat that as a
# terminating error under 'Stop'. Assertions below verify the real outcomes.
$ErrorActionPreference = 'Continue'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$base = 'http://localhost:18080'
$containerName = 'rifacloud-auth-test'
$image = 'rifacloud-auth-service'
$jar = Join-Path $env:TEMP 'rifacloud-auth-int-cookies.txt'
$results = New-Object System.Collections.Generic.List[object]
$email = "svc-it-$([guid]::NewGuid().ToString('N').Substring(0, 10))@example.invalid"
$userId = $null

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

function Invoke-Api {
    param(
        [string]$Method = 'GET',
        [string]$Path = '/',
        [string]$BodyFile = '',
        [string]$Token = ''
    )

    $bodyOut = Join-Path $env:TEMP ("auth-int-body-$([guid]::NewGuid().ToString('N')).json")
    $curlArgs = @(
        '-s', '-o', $bodyOut, '-w', '%{http_code}',
        '-X', $Method,
        '-c', $jar,
        '-H', 'Origin: http://localhost:18080',
        '-H', 'Accept: application/json'
    )

    if (Test-Path $jar) {
        $curlArgs += @('-b', $jar)
    }

    if ($Token -ne '') {
        $curlArgs += @('-H', "X-XSRF-TOKEN: $Token")
    }

    if ($BodyFile -ne '') {
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$BodyFile")
    }

    $curlArgs += ($base + $Path)

    $rawStatus = & curl.exe @curlArgs
    $body = ''
    if (Test-Path $bodyOut) {
        $body = Get-Content $bodyOut -Raw
        Remove-Item $bodyOut -Force
    }

    return [pscustomobject]@{
        Status = [int]$rawStatus
        Body   = $body
    }
}

function Get-XsrfToken {
    $line = Get-Content $jar | Where-Object { $_ -match 'XSRF-TOKEN' } | Select-Object -First 1

    if (-not $line) {
        return ''
    }

    $raw = ($line -split "`t")[-1]
    return [uri]::UnescapeDataString($raw)
}

try {
    & docker image inspect $image *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "Docker image '$image' is missing. Build it with: docker compose build auth-service"
    }

    & docker rm -f $containerName *> $null

    $runArgs = @(
        'run', '-d', '--rm', '--name', $containerName,
        '--network', 'rifacloud_default',
        '-p', '18080:8080',
        '--env-file', 'src/.env',
        '-e', 'APP_ENV=production',
        '-e', 'APP_DEBUG=false',
        '-e', 'LOG_CHANNEL=stderr',
        '-e', 'SESSION_DRIVER=database',
        '-e', 'CACHE_STORE=array',
        '-e', 'QUEUE_CONNECTION=sync',
        '-e', 'DB_DATABASE=rifacloud_test',
        '-e', 'SANCTUM_STATEFUL_DOMAINS=localhost:18080,127.0.0.1:18080',
        $image,
        'php', '-S', '0.0.0.0:8080', '-t', 'public', 'public/router.php'
    )

    & docker @runArgs *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to start the auth-service test container.'
    }

    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Milliseconds 500

        try {
            $probe = Invoke-Api -Method GET -Path '/api/auth/health'
            if ($probe.Status -eq 200) {
                $ready = $true
                break
            }
        } catch {
            # Container still starting.
        }
    }

    if (-not $ready) {
        throw 'auth-service test container did not become ready.'
    }

    Remove-Item $jar -Force -ErrorAction SilentlyContinue

    $csrf = Invoke-Api -Method GET -Path '/sanctum/csrf-cookie'
    Add-Result 'CSRF cookie' '204' "$($csrf.Status)" ($csrf.Status -eq 204)

    $token = Get-XsrfToken
    Add-Result 'XSRF token extracted' 'non-empty' $(if ($token) { 'non-empty' } else { 'empty' }) ($token -ne '')

    $invalidBody = Join-Path $env:TEMP 'auth-int-invalid.json'
    Set-Content -NoNewline -Path $invalidBody -Value '{"email":"nobody@example.invalid","password":"wrong"}'
    $invalid = Invoke-Api -Method POST -Path '/api/login' -BodyFile $invalidBody -Token $token
    Add-Result 'Invalid login rejected' '422' "$($invalid.Status)" ($invalid.Status -eq 422)
    Remove-Item $invalidBody -Force

    $registerBody = Join-Path $env:TEMP 'auth-int-register.json'
    $payload = @{
        name                  = 'Auth Service Integration'
        email                 = $email
        phone                 = '3000000000'
        password              = 'integration-password'
        password_confirmation = 'integration-password'
        privacy_consent       = $true
        terms_consent         = $true
        marketing_consent     = $false
        consent_version       = if ($env:LEGAL_CONSENT_VERSION) { $env:LEGAL_CONSENT_VERSION } else { '2026-11-11' }
    } | ConvertTo-Json -Compress
    Set-Content -NoNewline -Path $registerBody -Value $payload
    $register = Invoke-Api -Method POST -Path '/api/register' -BodyFile $registerBody -Token $token
    Add-Result 'Register new user' '201' "$($register.Status)" ($register.Status -eq 201)
    Remove-Item $registerBody -Force

    if ($register.Status -eq 201) {
        $json = $register.Body | ConvertFrom-Json
        $userId = $json.data.id
        Add-Result 'Register returns user id' 'non-empty' "$userId" ($null -ne $userId)
        Add-Result 'Register returns USER role' 'USER' "$($json.data.role)" ($json.data.role -eq 'USER')

        $consentRaw = & docker compose run --rm --no-deps `
            -e DB_DATABASE=rifacloud_test `
            -e AUTH_TEST_EMAIL=$email `
            -v "${repoRoot}/scripts:/scripts:ro" `
            app php /scripts/verify-auth-consent-evidence.php 2>$null
        $consentText = (($consentRaw | Out-String).Trim())
        $consent = $null

        try {
            $consent = $consentText | ConvertFrom-Json
        } catch {
            $consent = $null
        }

        Add-Result 'Consent evidence query' 'json' $(if ($null -ne $consent) { 'json' } else { 'invalid' }) ($null -ne $consent)

        if ($null -ne $consent -and $consent.table -eq $true) {
            $types = @($consent.rows | ForEach-Object { [string]$_.type })
            Add-Result 'Privacy consent recorded' 'PRIVACY' ($types -join ',') ($types -contains 'PRIVACY')
            Add-Result 'Terms consent recorded' 'TERMS' ($types -join ',') ($types -contains 'TERMS')
            Add-Result 'Marketing decision recorded separately' 'MARKETING' ($types -join ',') ($types -contains 'MARKETING')

            $expectedVersion = if ($env:LEGAL_CONSENT_VERSION) { $env:LEGAL_CONSENT_VERSION } else { '2026-11-11' }
            $versions = @($consent.rows | ForEach-Object { [string]$_.document_version } | Select-Object -Unique)
            Add-Result 'Consent document version' $expectedVersion ($versions -join ',') ($versions.Count -eq 1 -and $versions[0] -eq $expectedVersion)

            $privacyRow = @($consent.rows | Where-Object { $_.type -eq 'PRIVACY' })[0]
            Add-Result 'Privacy consent accepted' 'True' "$($privacyRow.accepted)" ($privacyRow.accepted -eq $true)
            Add-Result 'Consent timestamp present' 'non-empty' "$($privacyRow.accepted_at)" (-not [string]::IsNullOrWhiteSpace([string]$privacyRow.accepted_at))
        }
    }

    $me = Invoke-Api -Method GET -Path '/api/me'
    Add-Result '/api/me authenticated' '200' "$($me.Status)" ($me.Status -eq 200)

    if ($me.Status -eq 200) {
        $meJson = $me.Body | ConvertFrom-Json
        Add-Result '/api/me returns the registered email' $email "$($meJson.data.email)" ($meJson.data.email -eq $email)
        $passwordExposed = $meJson.data.PSObject.Properties.Name -contains 'password'
        Add-Result '/api/me hides password' 'absent' $(if ($passwordExposed) { 'present' } else { 'absent' }) (-not $passwordExposed)
    }

    # Prove that the monolith accepts the session issued by auth-service.
    $sessionLine = Get-Content $jar | Where-Object { $_ -match 'rifacloud-session' } | Select-Object -First 1
    $sessionCookie = ($sessionLine -split "`t")[-1]
    $compatOutput = & docker compose run --rm --no-deps `
        -e DB_DATABASE=rifacloud_test `
        -e AUTH_SESSION_COOKIE=$sessionCookie `
        -v "${repoRoot}/scripts:/scripts:ro" `
        app php /scripts/verify-auth-session-compat.php 2>$null
    $compatStatus = 0
    foreach ($line in @($compatOutput)) {
        if ($line -match '^status=(\d+)$') {
            $compatStatus = [int]$Matches[1]
        }
    }
    Add-Result 'Monolith accepts auth-service session' '200' "$compatStatus" ($compatStatus -eq 200)

    # register/login regenerate the session and therefore the CSRF token.
    # The SPA rereads the XSRF-TOKEN cookie before each stateful write.
    $token = Get-XsrfToken

    $logout = Invoke-Api -Method POST -Path '/api/logout' -Token $token
    Add-Result 'Logout' '200' "$($logout.Status)" ($logout.Status -eq 200)

    $meAfter = Invoke-Api -Method GET -Path '/api/me'
    Add-Result '/api/me after logout' '401' "$($meAfter.Status)" ($meAfter.Status -eq 401)
}
finally {
    if ($email) {
        & docker compose run --rm --no-deps `
            -e DB_DATABASE=rifacloud_test `
            -e AUTH_TEST_EMAIL=$email `
            -v "${repoRoot}/scripts:/scripts:ro" `
            app php /scripts/cleanup-auth-test-user.php 2>$null
    }

    & docker rm -f $containerName *> $null
    Remove-Item $jar -Force -ErrorAction SilentlyContinue
}

$results | Format-Table -AutoSize
$failed = @($results | Where-Object { -not $_.Pass })

Write-Output ""
Write-Output ("PASS={0} FAIL={1}" -f ($results.Count - $failed.Count), $failed.Count)

if ($failed.Count -gt 0) {
    exit 1
}

exit 0
