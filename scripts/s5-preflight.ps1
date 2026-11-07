#requires -Version 5.1
<#
.SYNOPSIS
    Read-only S5 preflight for the main RifaCloud schema.

.DESCRIPTION
    Checks, without changing anything:
      - pending additive migrations and the exact DDL (via migrate --pretend)
      - duplicate/NULL payment order ids (the unique index precondition)
      - presence of payment_webhook_events
      - Mercado Pago webhook secret / URL presence (never prints values)
      - whether per-service MySQL users already exist

    It prints the safe backup + migration commands but never runs them.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts/s5-preflight.ps1
#>

[CmdletBinding()]
param(
    [string]$Database = 'rifacloud'
)

$ErrorActionPreference = 'Continue'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Failures = 0

Set-Location $RepoRoot

function Section([string]$Title) {
    Write-Host ''
    Write-Host "== $Title ==" -ForegroundColor Cyan
}

$dbPassword = (Select-String -Path .env -Pattern '^DB_PASSWORD=' | ForEach-Object { $_.Line -replace '^DB_PASSWORD=','' })

function DbScalar([string]$Sql) {
    $value = docker compose exec -T -e MYSQL_PWD="$dbPassword" mysql mysql -urifacloud -N -e "$Sql" 2>$null
    return (($value | Select-String -NotMatch 'Warning' | Out-String).Trim())
}

Write-Host 'RifaCloud S5 preflight (read-only)' -ForegroundColor Green

# --- Pending migrations -----------------------------------------------------
Section 'Migraciones pendientes'
$status = docker compose exec -T app php artisan migrate:status 2>&1
$pending = @($status | Select-String -Pattern 'Pending')
if ($pending.Count -eq 0) {
    Write-Host 'OK: no pending migrations.' -ForegroundColor Green
} else {
    $pending | ForEach-Object { Write-Host "PENDING: $($_.Line.Trim())" -ForegroundColor Yellow }
}

Section 'DDL que se aplicaria (pretend, no escribe)'
foreach ($migration in @(
    'database/migrations/2026_10_23_000007_add_unique_order_id_to_payments_table.php',
    'database/migrations/2026_10_28_000008_create_payment_webhook_events_table.php'
)) {
    docker compose exec -T app php artisan migrate --pretend --force --path=$migration 2>&1 |
        Select-String -Pattern 'alter table|create table|add unique|add index' |
        ForEach-Object { Write-Host "  $($_.Line.Trim())" }
}

# --- Unique index preconditions --------------------------------------------
Section 'Precondiciones del indice unico payments.order_id'
$duplicates = [int](DbScalar "SELECT COUNT(*) FROM (SELECT order_id FROM $Database.payments GROUP BY order_id HAVING COUNT(*) > 1) d;")
$nulls = [int](DbScalar "SELECT COUNT(*) FROM $Database.payments WHERE order_id IS NULL;")

if ($duplicates -eq 0) {
    Write-Host 'OK: no duplicate order_id rows.' -ForegroundColor Green
} else {
    Write-Host "BLOCKED: $duplicates duplicate order_id group(s). Resolve before migrating." -ForegroundColor Red
    $Failures++
}

if ($nulls -eq 0) {
    Write-Host 'OK: no NULL order_id rows.' -ForegroundColor Green
} else {
    Write-Host "BLOCKED: $nulls NULL order_id rows." -ForegroundColor Red
    $Failures++
}

$webhookTable = [int](DbScalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database' AND table_name='payment_webhook_events';")
if ($webhookTable -eq 0) {
    Write-Host 'OK: payment_webhook_events does not exist yet (additive migration pending).' -ForegroundColor Yellow
} else {
    Write-Host 'payment_webhook_events already exists; verify its schema before re-running.' -ForegroundColor Yellow
}

# --- Webhook configuration --------------------------------------------------
Section 'Configuracion del webhook de Mercado Pago (sin mostrar secretos)'
function EnvValue([string]$key) {
    $v = docker compose exec -T payments-service printenv $key 2>$null
    return (($v | Out-String).Trim())
}

$secret = EnvValue 'MERCADOPAGO_WEBHOOK_SECRET'
$url = EnvValue 'MERCADOPAGO_WEBHOOK_URL'
$appUrl = EnvValue 'APP_URL'

Write-Host ("MERCADOPAGO_WEBHOOK_SECRET=" + $(if ($secret -eq '') { 'MISSING' } else { 'PRESENT' }))
if ($secret -eq '') {
    Write-Host '  Configure it in src/.env and recreate payments-service (see report).' -ForegroundColor Yellow
}

Write-Host ("MERCADOPAGO_WEBHOOK_URL=" + $(if ($url -eq '') { "MISSING (fallback: $appUrl/api/webhooks/mercadopago)" } else { $url }))
if ($url -eq '' -and $appUrl -match 'localhost|127\.0\.0\.1') {
    Write-Host '  WARN: no public HTTPS webhook URL (P3). Local delivery needs a tunnel or the MP simulator.' -ForegroundColor Yellow
}

# --- Per-service users ------------------------------------------------------
Section 'Usuarios MySQL por servicio'
$rootPassword = (Select-String -Path .env -Pattern '^DB_ROOT_PASSWORD=' | ForEach-Object { $_.Line -replace '^DB_ROOT_PASSWORD=','' })
if ($rootPassword -ne '') {
    $users = docker compose exec -T -e MYSQL_PWD="$rootPassword" mysql mysql -uroot -N -e "SELECT user FROM mysql.user WHERE user LIKE 'rifacloud\_%' ORDER BY user;" 2>$null
    $existing = @($users | Select-String -Pattern '^rifacloud_' | ForEach-Object { $_.Line.Trim() })

    if ($existing.Count -eq 0) {
        Write-Host 'None yet (expected before S5). Apply scripts/mysql-s5-grants.sql after authorization.' -ForegroundColor Yellow
    } else {
        $existing | ForEach-Object { Write-Host "  $_" }
    }
} else {
    Write-Host 'Skipped: DB_ROOT_PASSWORD is not set in the root .env.' -ForegroundColor Yellow
}

# --- Safe commands ----------------------------------------------------------
Section 'Comandos seguros (NO ejecutados por este script)'
Write-Host @"
1) Respaldo (verificado restaurable en rifacloud_test/restore temporal):
   docker compose exec -T mysql sh -c 'mysqldump -uroot -p"`$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF rifacloud > /tmp/rifacloud-pre-s5.sql'
   docker cp rifacloud-mysql:/tmp/rifacloud-pre-s5.sql .\rifacloud-pre-s5.sql

2) Indice unico (primero; falla de forma segura si hay duplicados):
   docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_23_000007_add_unique_order_id_to_payments_table.php

3) Tabla de eventos de webhook (aditiva):
   docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_28_000008_create_payment_webhook_events_table.php

4) Verificacion posterior:
   docker compose exec -T app php artisan migrate:status
   docker compose exec -T app php artisan migrate --pretend --force --path=database/migrations/2026_10_23_000007_add_unique_order_id_to_payments_table.php

5) Permisos por servicio (solo tras autorizacion, con secretos reales):
   docker compose exec -T mysql sh -c 'mysql -uroot -p"`$MYSQL_ROOT_PASSWORD" < /scripts/mysql-s5-grants.sql'
"@ -ForegroundColor Gray

Write-Host ''
if ($Failures -gt 0) {
    Write-Host "Preflight found $Failures blocking issue(s). Migrations NOT safe yet." -ForegroundColor Red
    exit 2
}

Write-Host 'Preflight OK: migrations are additive and safe to apply with authorization.' -ForegroundColor Green
exit 0
