#requires -Version 5.1
<#
.SYNOPSIS
    Prepara RifaCloud para pruebas manuales sobre rifacloud_test.

.DESCRIPTION
    - aplica las migraciones existentes SOLO a rifacloud_test;
    - recrea el stack con docker-compose.e2e.yml (gateway simulado);
    - genera credenciales ficticias locales en docs/testing/.local/;
    - ejecuta el seeder idempotente de datos de demostración;
    - deja el stack de pruebas activo para abrir el navegador.

    La base principal `rifacloud` no se toca. Para restaurar el stack normal:
        powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1
#>

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://localhost',
    [int]$ReservationMinutes = 60,
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
$LocalDir = Join-Path $RepoRoot 'docs/testing/.local'
$CredentialsFile = Join-Path $LocalDir 'test-users.json'
$SeedScript = '/scripts/testing/seed-demo-data.php'

# The demo keeps reservations alive long enough for manual testing. This is a
# test-environment setting only; the domain rule still comes from the server.
$env:RESERVATION_MINUTES = [string]$ReservationMinutes
$env:S6_RESERVATION_MINUTES = [string]$ReservationMinutes

function Wait-ServiceHealthy {
    param([string]$Container, [int]$TimeoutSeconds = 120)

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

function New-DemoPassword {
    return 'Demo-' + [guid]::NewGuid().ToString('N').Substring(0, 22) + '!9a'
}

function Get-OrCreateCredentials {
    if (Test-Path $CredentialsFile) {
        return Get-Content $CredentialsFile -Raw | ConvertFrom-Json
    }

    New-Item -ItemType Directory -Force -Path $LocalDir | Out-Null

    $credentials = [pscustomobject]@{
        environment = 'rifacloud_test'
        generated_at = (Get-Date).ToUniversalTime().ToString('o')
        users = @(
            [pscustomobject]@{ role = 'ADMIN'; name = 'Demo administrador'; email = 'demo-admin@rifacloud.test'; password = (New-DemoPassword) },
            [pscustomobject]@{ role = 'USER'; name = 'Demo comprador uno'; email = 'demo-buyer1@rifacloud.test'; password = (New-DemoPassword) },
            [pscustomobject]@{ role = 'USER'; name = 'Demo comprador dos'; email = 'demo-buyer2@rifacloud.test'; password = (New-DemoPassword) }
        )
    }

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($CredentialsFile, ($credentials | ConvertTo-Json -Depth 5), $utf8NoBom)

    return $credentials
}

function Get-CredentialPassword {
    param([object]$Credentials, [string]$Role)

    $entry = @($Credentials.users) | Where-Object { $_.role -eq $Role } | Select-Object -First 1

    if ($null -eq $entry -or [string]::IsNullOrWhiteSpace($entry.password)) {
        throw "No hay credencial de prueba para el rol $Role en $CredentialsFile."
    }

    return [string]$entry.password
}

try {
    Write-Host '== Aplicando migraciones existentes a rifacloud_test ==' -ForegroundColor Cyan
    & docker compose @Compose up -d mysql 2>&1 | Out-Null

    if (-not $SkipBuild) {
        Write-Host '== Construyendo imágenes del stack de pruebas ==' -ForegroundColor Cyan
        & docker compose @Compose build app auth-service raffles-service orders-service payments-service nginx 2>&1 | Out-Null
    }

    & docker compose @Compose run --rm --no-deps -T app php artisan migrate --force 2>&1 | Out-Null

    if ($LASTEXITCODE -ne 0) {
        throw "No se pudieron aplicar las migraciones existentes a rifacloud_test (exit $LASTEXITCODE)."
    }

    Write-Host '== Iniciando stack sobre rifacloud_test ==' -ForegroundColor Cyan
    & docker compose @Compose up -d --force-recreate app auth-service raffles-service orders-service payments-service nginx scheduler 2>&1 | Out-Null

    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo iniciar el stack de pruebas (exit $LASTEXITCODE)."
    }

    foreach ($container in @(
        'rifacloud-auth-service',
        'rifacloud-raffles-service',
        'rifacloud-orders-service',
        'rifacloud-payments-service',
        'rifacloud-scheduler'
    )) {
        if (-not (Wait-ServiceHealthy $container)) {
            throw "El contenedor $container no quedó healthy."
        }
    }

    $credentials = Get-OrCreateCredentials
    $adminPassword = Get-CredentialPassword $credentials 'ADMIN'

    # Buyer 1 and buyer 2 share the USER role; read both by email instead of role.
    $buyer1 = @($credentials.users) | Where-Object { $_.email -eq 'demo-buyer1@rifacloud.test' } | Select-Object -First 1
    $buyer2 = @($credentials.users) | Where-Object { $_.email -eq 'demo-buyer2@rifacloud.test' } | Select-Object -First 1

    if ($null -eq $buyer1 -or $null -eq $buyer2) {
        throw "El archivo de credenciales no contiene los dos compradores esperados."
    }

    Write-Host '== Sembrando datos de demostración idempotentes ==' -ForegroundColor Cyan
    $seedOutput = & docker compose @Compose run --rm --no-deps -T `
        -v "${RepoRoot}/scripts:/scripts:ro" `
        -e "DEMO_ADMIN_PASSWORD=$adminPassword" `
        -e "DEMO_BUYER1_PASSWORD=$($buyer1.password)" `
        -e "DEMO_BUYER2_PASSWORD=$($buyer2.password)" `
        app php $SeedScript seed 2>&1

    $seedLine = @($seedOutput) | Where-Object { $_ -match '^\s*\{' } | Select-Object -Last 1

    if (-not $seedLine) {
        $joinedOutput = $seedOutput -join ' '
        throw "El seeder no devolvió un resumen JSON. Salida: $joinedOutput"
    }

    $summary = $seedLine | ConvertFrom-Json
    $dataFile = Join-Path $LocalDir 'test-data.json'
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($dataFile, ($summary | ConvertTo-Json -Depth 8), $utf8NoBom)

    Write-Host ''
    Write-Host 'Entorno de pruebas listo.' -ForegroundColor Green
    Write-Host "Base de datos: $($summary.database) (la base rifacloud no se modificó)" -ForegroundColor Gray
    Write-Host "URL: $BaseUrl" -ForegroundColor Gray
    Write-Host "Credenciales locales (ignoradas por Git): $CredentialsFile" -ForegroundColor Yellow
    Write-Host "IDs actuales de rifas/órdenes: $dataFile" -ForegroundColor Gray
    Write-Host 'Abrelas con: Get-Content docs/testing/.local/test-users.json' -ForegroundColor Gray
    Write-Host ''
    Write-Host 'Indicadores del dashboard en este momento:' -ForegroundColor Cyan
    Write-Host "  Rifas publicadas: $($summary.counts.published_raffles)"
    Write-Host "  Ordenes pagadas: $($summary.counts.paid_orders)"
    Write-Host "  Números vendidos: $($summary.counts.sold_numbers)"
    Write-Host "  Incidencias pendientes: $($summary.counts.pending_incidents)"
    Write-Host ''
    Write-Host 'Para restaurar el stack normal:' -ForegroundColor Cyan
    Write-Host '  powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1'
} catch {
    $errorMessage = $_.Exception.Message
    Write-Host "ERROR: $errorMessage" -ForegroundColor Red
    Write-Host 'Restaurando el stack normal por seguridad...' -ForegroundColor Yellow
    & docker compose up -d --force-recreate app scheduler auth-service raffles-service orders-service payments-service nginx *> $null
    exit 1
}
