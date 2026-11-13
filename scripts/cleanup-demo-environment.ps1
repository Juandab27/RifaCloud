#requires -Version 5.1
<#
.SYNOPSIS
    Limpia los fixtures de demostración y restaura el stack normal.

.DESCRIPTION
    Elimina únicamente las rifas `[DEMO]`, las órdenes `DEMO-` y las sesiones
    de los usuarios demo de `rifacloud_test`. Por defecto conserva los usuarios
    demo; usa -PurgeUsers para eliminarlos cuando no tengan órdenes restantes.

    Después restaura el stack normal sobre `rifacloud`, salvo -KeepTestStack.
#>

[CmdletBinding()]
param(
    [switch]$PurgeUsers,
    [switch]$KeepTestStack
)

$ErrorActionPreference = 'Continue'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$TestCompose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
$SeedScript = '/scripts/testing/seed-demo-data.php'
$DataFile = Join-Path $RepoRoot 'docs/testing/.local/test-data.json'

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

function Test-MainMoneyMigrationApplied {
    $envMap = @{}
    Get-Content (Join-Path $RepoRoot '.env') | Where-Object { $_ -match '^[A-Z0-9_]+=' } | ForEach-Object {
        $key, $value = $_ -split '=', 2
        $envMap[$key] = $value
    }

    if (-not $envMap.ContainsKey('DB_ROOT_PASSWORD')) {
        return $false
    }

    $result = & docker compose @TestCompose exec -T -e "MYSQL_PWD=$($envMap['DB_ROOT_PASSWORD'])" mysql mysql -uroot -N -e "SELECT COUNT(*) FROM rifacloud.migrations WHERE migration='2026_11_13_000012_convert_monetary_values_to_cop';" 2>$null

    return ((($result | Out-String).Trim()) -eq '1')
}

try {
    Write-Host '== Limpiando fixtures de rifacloud_test ==' -ForegroundColor Cyan
    & docker compose @TestCompose up -d mysql 2>&1 | Out-Null

    $arguments = @('compose') + $TestCompose + @(
        'run', '--rm', '--no-deps', '-T',
        '-v', "${RepoRoot}/scripts:/scripts:ro"
    )

    $seedArguments = @('app', 'php', $SeedScript, 'cleanup')

    if ($PurgeUsers) {
        $seedArguments += '--purge-users'
    }

    $arguments += $seedArguments

    $output = & docker @arguments 2>&1
    $line = @($output) | Where-Object { $_ -match '^\s*\{' } | Select-Object -Last 1

    if ($line) {
        $summary = $line | ConvertFrom-Json
        Write-Host "Rifas demo eliminadas: $($summary.removed.raffles)" -ForegroundColor Gray
        Write-Host "Ordenes demo eliminadas: $($summary.removed.orders)" -ForegroundColor Gray
        Write-Host "Usuarios demo purgados: $($summary.removed.purged_users)" -ForegroundColor Gray

        if ([int]$summary.removed.kept_users -gt 0) {
            Write-Host 'Se conservaron usuarios demo con órdenes manuales fuera de los fixtures.' -ForegroundColor Yellow
        }
    } else {
        Write-Host 'El seeder no devolvió un resumen JSON; revisa la salida manualmente.' -ForegroundColor Yellow
    }

    # The IDs in test-data.json are only valid while the fixtures exist.
    Remove-Item $DataFile -Force -ErrorAction SilentlyContinue
} catch {
    Write-Host "WARN  No se pudo limpiar el entorno de pruebas: $($_.Exception.Message)" -ForegroundColor Yellow
} finally {
    if (-not $KeepTestStack) {
        if (-not (Test-MainMoneyMigrationApplied)) {
            Write-Host 'No se restaura el stack normal: rifacloud sigue en centavos.' -ForegroundColor Yellow
            Write-Host 'Aplica la migración 2026_11_13_000012_convert_monetary_values_to_cop sobre rifacloud antes de iniciar el stack normal.' -ForegroundColor Yellow
            Write-Host 'El stack de pruebas permanece disponible sobre rifacloud_test.' -ForegroundColor Yellow
        } else {
            Write-Host '== Restaurando el stack normal sobre rifacloud ==' -ForegroundColor Cyan
            & docker compose up -d --force-recreate app scheduler auth-service raffles-service orders-service payments-service nginx *> $null

            foreach ($container in @(
                'rifacloud-auth-service',
                'rifacloud-raffles-service',
                'rifacloud-orders-service',
                'rifacloud-payments-service',
                'rifacloud-scheduler'
            )) {
                Wait-ServiceHealthy $container | Out-Null
            }

            Write-Host 'Stack normal restaurado.' -ForegroundColor Green
        }
    } else {
        Write-Host 'Stack de pruebas conservado activo (-KeepTestStack).' -ForegroundColor Yellow
    }
}
