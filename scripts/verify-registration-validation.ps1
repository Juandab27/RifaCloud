#requires -Version 5.1
<#
.SYNOPSIS
    Focused validation of registration rules and proposal integration.

.DESCRIPTION
    Runs against the local stack (nginx on http://localhost) and the test
    database. It checks malformed email/phone/weak password rejection, accepted
    phone normalization, login with the new account, the register -> login ->
    propose flow, and compatibility with an existing demo account.

    It creates one disposable user and one proposal, then removes both. It does
    not migrate, seed or reset any schema.
#>

$ErrorActionPreference = 'Continue'
$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$base = 'http://localhost'
$email = "s13-reg-$([guid]::NewGuid().ToString('N').Substring(0, 10))@example.invalid"
$password = 'ClaveSegura1'
$name = 'Registro Validado'
$proposalTitle = 'Propuesta de validacion ' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$results = New-Object System.Collections.Generic.List[object]
$registerJar = Join-Path $env:TEMP ("reg-$([guid]::NewGuid().ToString('N')).txt")
$loginJar = Join-Path $env:TEMP ("reg-login-$([guid]::NewGuid().ToString('N')).txt")
$demoJar = Join-Path $env:TEMP ("reg-demo-$([guid]::NewGuid().ToString('N')).txt")
$createdUserId = $null
$createdProposalId = $null

function Add-Result($Name, $Expected, $Actual, $Pass) {
    $results.Add([pscustomobject]@{ Name = $Name; Expected = $Expected; Actual = $Actual; Pass = $Pass }) | Out-Null
}

function Invoke-Api($Method = 'GET', $Path = '/', $Body = $null, $Jar = '', $Xsrf = '') {
    $bodyOut = Join-Path $env:TEMP ("reg-body-$([guid]::NewGuid().ToString('N')).json")
    $args = @('-s', '-o', $bodyOut, '-w', '%{http_code}', '-X', $Method, '-H', 'Accept: application/json')
    if ($Jar -ne '') { $args += @('-H', "Origin: $base", '-c', $Jar, '-b', $Jar) }
    if ($Xsrf -ne '') { $args += @('-H', "X-XSRF-TOKEN: $Xsrf") }
    if ($null -ne $Body) {
        $payloadFile = Join-Path $env:TEMP ("reg-payload-$([guid]::NewGuid().ToString('N')).json")
        [IO.File]::WriteAllText($payloadFile, $Body, (New-Object System.Text.UTF8Encoding($false)))
        $args += @('-H', 'Content-Type: application/json', '--data-binary', "@$payloadFile")
    }
    $args += ($base + $Path)
    $statusRaw = & curl.exe @args 2>$null
    $content = ''
    if (Test-Path $bodyOut) { $content = Get-Content $bodyOut -Raw; Remove-Item $bodyOut -Force -ErrorAction SilentlyContinue }
    if ($null -ne $Body -and (Test-Path $payloadFile)) { Remove-Item $payloadFile -Force -ErrorAction SilentlyContinue }
    $status = 0
    [void][int]::TryParse(([string]$statusRaw).Trim(), [ref]$status)
    return [pscustomobject]@{ Status = $status; Body = $content }
}

function Get-Cookie($Jar, $Name) {
    if (-not (Test-Path $Jar)) { return '' }
    $line = Get-Content $Jar | Where-Object { $_ -match "`t$Name`t" } | Select-Object -Last 1
    if (-not $line) { return '' }
    return [uri]::UnescapeDataString((($line -split "`t")[-1]))
}

function Start-Session($Jar) {
    Remove-Item $Jar -Force -ErrorAction SilentlyContinue
    Invoke-Api -Method GET -Path '/sanctum/csrf-cookie' -Jar $Jar | Out-Null
    return Get-Cookie -Jar $Jar -Name 'XSRF-TOKEN'
}

function Login($Email, $Password, $Jar) {
    $xsrf = Start-Session $Jar
    return Invoke-Api -Method POST -Path '/api/login' -Body (@{ email = $Email; password = $Password } | ConvertTo-Json -Compress) -Jar $Jar -Xsrf $xsrf
}

function Register-Body($Overrides) {
    $payload = @{
        name = $name
        email = $email
        phone = '3001234567'
        password = $password
        password_confirmation = $password
        privacy_consent = $true
        terms_consent = $true
        marketing_consent = $false
        consent_version = '2026-11-15'
    }

    foreach ($key in $Overrides.Keys) { $payload[$key] = $Overrides[$key] }
    return ($payload | ConvertTo-Json -Compress)
}

function Sql($Query) {
    $envMap = @{}
    Get-Content (Join-Path $repoRoot '.env') | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object {
        $k, $v = $_ -split '=', 2
        $envMap[$k] = $v
    }
    return & docker compose exec -T -e "MYSQL_PWD=$($envMap['DB_ROOT_PASSWORD'])" mysql mysql -uroot -N -e $Query 2>$null
}

try {
    $xsrf = Start-Session $registerJar

    # Three focused invalid requests keep the flow below the real 6/min throttle.
    $badEmail = Invoke-Api -Method POST -Path '/api/register' -Body (Register-Body @{ email = 'ana@example' }) -Jar $registerJar -Xsrf $xsrf
    Add-Result 'malformed email rejected' '422' "$($badEmail.Status)" ($badEmail.Status -eq 422)
    Add-Result 'malformed email mentions Spanish error' 'true' "$($badEmail.Body -match 'correo electr')" ($badEmail.Body -match 'correo electr')

    $badPhone = Invoke-Api -Method POST -Path '/api/register' -Body (Register-Body @{ phone = '4001234567' }) -Jar $registerJar -Xsrf $xsrf
    Add-Result 'invalid mobile rejected' '422' "$($badPhone.Status)" ($badPhone.Status -eq 422)
    Add-Result 'invalid mobile mentions Spanish error' 'true' "$($badPhone.Body -match 'celular')" ($badPhone.Body -match 'celular')

    $badPassword = Invoke-Api -Method POST -Path '/api/register' -Body (Register-Body @{ password = 'abcdefg1'; password_confirmation = 'abcdefg1' }) -Jar $registerJar -Xsrf $xsrf
    $badPasswordJson = $null
    try { $badPasswordJson = $badPassword.Body | ConvertFrom-Json } catch { $badPasswordJson = $null }
    $badPasswordMessage = if ($null -ne $badPasswordJson) { @($badPasswordJson.errors.password) -join ' ' } else { '' }
    Add-Result 'weak password rejected' '422' "$($badPassword.Status)" ($badPassword.Status -eq 422)
    Add-Result 'weak password mentions Spanish error' 'true' "$badPasswordMessage" ($badPasswordMessage -match 'may' -and $badPasswordMessage -match 'scula')

    $valid = Invoke-Api -Method POST -Path '/api/register' -Body (Register-Body @{ phone = '+57 300 123 4567' }) -Jar $registerJar -Xsrf $xsrf
    $validJson = $null
    try { $validJson = $valid.Body | ConvertFrom-Json } catch { $validJson = $null }
    Add-Result 'valid registration' '201' "$($valid.Status)" ($valid.Status -eq 201)

    if ($null -ne $validJson) {
        $createdUserId = $validJson.data.id
        Add-Result 'response keeps normalized user' "$email" "$($validJson.data.email)" ($validJson.data.email -eq $email)
    }

    if ($null -ne $createdUserId) {
        $stored = (Sql "SELECT phone FROM rifacloud_test.users WHERE id=$createdUserId;") -join ''
        Add-Result 'phone stored normalized' '3001234567' "$stored" ($stored -eq '3001234567')
    }

    $login = Login -Email $email -Password $password -Jar $loginJar
    Add-Result 'new account login' '200' "$($login.Status)" ($login.Status -eq 200)

    $loginXsrf = Get-Cookie -Jar $loginJar -Name 'XSRF-TOKEN'
    $proposalBody = @{
        title = $proposalTitle
        description = 'Propuesta creada por la verificacion de registro y validaciones.'
        privacy_accepted = $true
    } | ConvertTo-Json -Compress
    $proposal = Invoke-Api -Method POST -Path '/api/proposals' -Body $proposalBody -Jar $loginJar -Xsrf $loginXsrf
    $proposalJson = $null
    try { $proposalJson = $proposal.Body | ConvertFrom-Json } catch { $proposalJson = $null }
    Add-Result 'proposal after registration/login' '201' "$($proposal.Status)" ($proposal.Status -eq 201)

    if ($null -ne $proposalJson) {
        $createdProposalId = $proposalJson.data.id
    }

    if ($null -ne $createdProposalId) {
        $row = (Sql "SELECT contact_name, contact_email, contact_phone FROM rifacloud_test.raffle_proposals WHERE id=$createdProposalId;") -join ''
        $fields = $row -split "`t"
        Add-Result 'proposal contact name from account' $name "$($fields[0])" ($fields[0] -eq $name)
        Add-Result 'proposal contact email from account' $email "$($fields[1])" ($fields[1] -eq $email)
        Add-Result 'proposal contact phone normalized' '3001234567' "$($fields[2])" ($fields[2] -eq '3001234567')
    }

    # Existing demo account with a historical weak password must still log in.
    $creds = Get-Content (Join-Path $repoRoot 'docs/testing/.local/test-users.json') -Raw | ConvertFrom-Json
    $demo = @($creds.users) | Where-Object { $_.email -eq 'demo-buyer1@rifacloud.test' } | Select-Object -First 1
    $demoLogin = Login -Email $demo.email -Password $demo.password -Jar $demoJar
    Add-Result 'existing account login still works' '200' "$($demoLogin.Status)" ($demoLogin.Status -eq 200)
} catch {
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
} finally {
    if ($null -ne $createdProposalId) {
        Sql "DELETE FROM rifacloud_test.raffle_proposals WHERE id=$createdProposalId;" | Out-Null
    }
    if ($null -ne $createdUserId) {
        Sql "DELETE FROM rifacloud_test.user_consents WHERE user_id=$createdUserId; DELETE FROM rifacloud_test.sessions WHERE user_id=$createdUserId; DELETE FROM rifacloud_test.users WHERE id=$createdUserId;" | Out-Null
    }
    Remove-Item $registerJar, $loginJar, $demoJar -Force -ErrorAction SilentlyContinue
}

$failed = @($results | Where-Object { -not $_.Pass }).Count
$results | Format-Table -AutoSize
Write-Host ""
Write-Host ("PASS={0} FAIL={1}" -f ($results.Count - $failed), $failed)
exit $(if ($failed -eq 0) { 0 } else { 1 })
