# RifaCloud

Plataforma web para gestionar rifas: catálogo público, selección de números y
panel de administración. Proyecto universitario de Cloud Computing.


## Stack

| Componente | Versión |
|---|---|
| Laravel | 13.38 (PHP 8.4) |
| Sanctum | 4.3 (SPA, cookies de sesión) |
| MySQL | 8.4 |
| React + TypeScript | React 19, Vite 6 |
| Nginx | 1.27 |
| Docker Compose | v2 |

## Requisitos

- Docker Desktop con el daemon en ejecución.
- Puertos 80 libres en el host.
- No se requiere PHP, Composer ni Node en el host.

## Puesta en marcha

```sh
# 1. Variables de Compose y de la aplicación
cp .env.example .env
cp src/.env.example src/.env
# En PowerShell: Copy-Item .env.example .env; Copy-Item src/.env.example src/.env

# 2. Dependencias PHP (el código se monta en el contenedor)
docker compose run --rm app composer install

# 3. Clave de aplicación
docker compose run --rm app php artisan key:generate

# La clave queda en src/.env. Copia el MISMO valor de APP_KEY a la raíz .env:
# los cuatro servicios y el monolito comparten la cookie de sesión.
# (Si usas key:generate --show, pega el valor en ambos archivos.)

# 4. Usuarios MySQL de servicio (solo en un volumen nuevo; ver nota abajo)
# Reemplaza los placeholders de scripts/mysql-s5-grants.sql por los valores
# AUTH_DB_PASSWORD, RAFFLES_DB_PASSWORD, ORDERS_DB_PASSWORD y
# PAYMENTS_DB_PASSWORD de .env, y aplica el archivo como root.
docker compose up -d mysql
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' \
    < scripts/mysql-s5-grants.sql
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' \
    < scripts/mysql-s91-grants.sql
# PowerShell no admite "<": usa
# Get-Content scripts/mysql-s5-grants.sql -Raw |
#   docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'

# 5. Construir y levantar servicios
docker compose build
docker compose up -d

# 6. Esquema y datos iniciales
docker compose exec app php artisan migrate --seed
```

La aplicación queda en <http://localhost>. Si el volumen `mysql_data` ya
existía antes de S5/S9.1, los usuarios de servicio y los grants ya deben estar
creados; en un volumen nuevo el paso 4 es obligatorio o los servicios
arrancarán con errores de acceso a MySQL. No uses el usuario compartido
`rifacloud` para el código de los servicios.

## Dashboard administrativo

`/admin/dashboard` está disponible solo para usuarios ADMIN y muestra cuatro
indicadores calculados por consultas agregadas en sus servicios propietarios:

| Indicador | Definición | Servicio |
|---|---|---|
| Rifas publicadas | `raffles.status = 'PUBLISHED'` | `raffles-service` |
| Órdenes pagadas | `orders.status = 'PAID'` | `orders-service` |
| Números vendidos | `raffle_numbers.status = 'SOLD'` | `orders-service` |
| Incidencias pendientes | `payments.status IN ('UNKNOWN','REFUND_REQUIRED')` | `payments-service` |

Cada endpoint (`/api/admin/{raffles,orders,payments...}/metrics`) valida la
sesión ADMIN en el servicio; Nginx devuelve un 503 JSON controlado si el
servicio está caído. La interfaz muestra «No disponible» en ese caso y nunca
inventa un cero. No se cargan órdenes ni números completos en React.

## Pruebas manuales

El entorno de pruebas funcionales vive en `rifacloud_test` y no toca la base
principal. Incluye usuarios demo, rifas en todos los estados, órdenes pagadas,
pendientes, expiradas, rechazadas y en revisión.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1
powershell -ExecutionPolicy Bypass -File scripts/verify-demo-environment.ps1
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1
```

Las credenciales ficticias se generan en `docs/testing/.local/test-users.json`
(ignorado por Git). La guía completa y el procedimiento de Mercado Pago
sandbox están en [`docs/testing/`](docs/testing/README.md).

## Despliegue en AWS EC2

La preparación reproducible (Compose de producción, HTTPS con ALB/ACM o Nginx
TLS, bootstrap de MySQL con 13 migraciones, grants mínimos, backups cifrados y
procedimiento de restauración) está en
[`deploy/aws/README.md`](deploy/aws/README.md).

No se crean recursos AWS automáticamente. El despliegue requiere dominio/DNS,
certificado, credenciales sandbox de Mercado Pago, SMTP (opcional) y un bucket
S3 para respaldos. El webhook real no se considera verificado hasta probar una
notificación firmada del proveedor.

Los documentos SDD y `AGENTS.md` están desbloqueados en `.gitignore` para que
puedan versionarse; deben commitearse y pushearse para recuperarlos desde
GitHub.

### Administrador inicial

`AdminUserSeeder` crea (o actualiza) el administrador leyendo
`ADMIN_EMAIL` y `ADMIN_PASSWORD` de `src/.env`. Si están vacíos, el seeder se
omite y no existe ningún administrador por defecto.

## Pruebas

### Preparación (una sola vez)

1. Copia la plantilla de entorno de pruebas:

```sh
cp src/.env.testing.example src/.env.testing
# En PowerShell: Copy-Item src/.env.testing.example src/.env.testing
```

2. Ajusta `DB_PASSWORD` en `src/.env.testing` para que coincida con la contraseña
   de MySQL que usaste en `src/.env`.

3. La plantilla ya incluye una `APP_KEY` **exclusiva para tests** (no es una
   clave de producción y no debe reutilizarse fuera del entorno local). Si
   prefieres generar una propia:

```sh
docker compose exec app php artisan key:generate --env=testing
```

   El comando escribe la nueva clave en `src/.env.testing` (archivo no versionado).
   `src/phpunit.xml` incluye la misma clave de test como respaldo para la suite.

4. El esquema `rifacloud_test` lo crea `docker/mysql/init/01-test-database.sql`
   la primera vez que se inicializa el volumen. Si tu volumen `mysql_data` ya
   existía antes de añadir ese script, crea el esquema una vez:

```sh
docker compose exec mysql mysql -u root -p
```

```sql
CREATE DATABASE IF NOT EXISTS rifacloud_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON rifacloud_test.* TO 'rifacloud'@'%';
FLUSH PRIVILEGES;
```

   Si no necesitas conservar los datos locales, `docker compose down -v` recrea
   el volumen y el script de init vuelve a ejecutarse (**borra** los datos).

5. Crea la cuenta **exclusiva de fixtures** usada por las pruebas de integración
   de `orders-service` y `payments-service`. Solo tiene permisos sobre
   `rifacloud_test`; no usa el usuario compartido del monolito ni recibe
   permisos en `rifacloud`:

```sh
# Define TEST_FIXTURE_DB_PASSWORD en src/.env.testing (archivo no versionado)
# y reemplaza el placeholder de scripts/mysql-test-fixture-grants.sql.
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' \
    < scripts/mysql-test-fixture-grants.sql
```

   Las pruebas de integración reciben `TEST_FIXTURE_DB_PASSWORD` por entorno;
   si falta, se niegan a ejecutarse en lugar de reutilizar credenciales de
   producción.

### Ejecución

```sh
docker compose exec app php artisan test
```

Los tests usan exclusivamente el esquema `rifacloud_test` configurado en
`src/.env.testing` (no versionado). La suite no modifica la base de datos de
desarrollo `rifacloud`; `RefreshDatabase` deja el esquema de test limpio.

### Estados de rifa y pruebas de navegador

El frontend deriva el estado visible de la rifa a partir del estado del backend
y de `starts_at`/`ends_at` (próximamente, disponible, cerrada o finalizada).
Fuera de la ventana de venta no se ofrecen reservas; el servidor conserva todas
sus validaciones.

```sh
cd frontend
npm run test:unit    # 16 comprobaciones puras del estado temporal
npx playwright test  # 20 pruebas de navegador con Chrome instalado
```

Las pruebas de navegador interceptan `/api/**` en el propio navegador: usan
datos aislados, un gateway simulado y no escriben en MySQL ni procesan pagos.
La primera ejecución requiere `npm install` en `frontend/`; no descarga
navegadores porque usa el Chrome/Edge instalado (`channel: 'chrome'`).

### Dashboard administrativo (E2E)

```sh
powershell -ExecutionPolicy Bypass -File scripts/verify-admin-dashboard.ps1
```

Recrea el stack sobre `rifacloud_test` con fixtures aislados y verifica por
Nginx: 401 anónimo, 403 USER, 200 ADMIN con los cuatro valores exactos y 503
controlado al detener cada servicio propietario. Restaura el stack normal en
`finally`; no toca la base `rifacloud` ni ejecuta migraciones.

## Privacidad y documentos legales (S11)

- El registro exige aceptar la Política de Tratamiento de Datos Personales y
  los Términos y Condiciones del prototipo. El consentimiento promocional es
  opcional y separado.
- La aceptación se registra en `user_consents` con tipo, versión del documento
  (`2026-11-11`), fecha y usuario.
- Las páginas públicas son `/privacidad`, `/terminos` y
  `/alcance-regulatorio`; el pie de página y la aclaración contextual del
  checkout explican que es un prototipo académico sin autorización de
  Coljuegos.
- Los borradores y el inventario de datos están en
  [`docs/legal/`](docs/legal/README.md). **No son asesoría jurídica** y
  contienen marcadores pendientes.
- La migración aditiva `2026_11_11_000011_create_user_consents_table.php` y el
  permiso de `user_consents` para `rifacloud_auth` se aplicaron a `rifacloud`
  el 2026-11-12 con autorización explícita. Un registro temporal verificó las
  filas `PRIVACY`, `TERMS` y `MARKETING` y luego se eliminó; la base quedó sin
  datos de prueba.

## Comandos útiles

```sh
docker compose ps
docker compose logs -f app
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan orders:expire-reservations
docker compose down          # conserva el volumen mysql_data
docker compose down -v       # elimina también los datos locales
```

## Notas de diseño

- MySQL no publica puertos: solo es accesible desde la red interna de Docker
  (NFR-011 / AC-029).
- Los valores monetarios se almacenan en **centavos** (NFR-013).
- El correo local usa el mailer `log` (P1); Mailtrap se configura en P2.
- El valor por defecto de `src/.env.example` usa el gateway simulado
  (`PAYMENT_GATEWAY=fake`); la interfaz lo marca como **SIMULADO** y no se
  procesa dinero real. Si configuras `mercadopago`, revisa también el secreto y
  la URL pública del webhook. La aplicación se niega a arrancar en `production`
  si `PAYMENT_GATEWAY=fake`.
- Las reservas duran `RESERVATION_MINUTES` (10 por defecto) y se crean de forma
  atómica con locks de fila; los números reservados no se pueden duplicar
  (NFR-002, NFR-004).
- El servicio `scheduler` ejecuta `orders:expire-reservations` cada minuto. Solo
  libera órdenes `PENDING_PAYMENT` vencidas; nunca libera órdenes `PAID`,
  `PAYMENT_REVIEW` ni `REQUIRES_REFUND`.
- `ORDER_MAX_NUMBERS` (50 por defecto) es una **protección técnica** del payload
  de una orden; no es una regla de negocio de compra.
- `RAFFLE_MAX_NUMBERS` (por defecto 10000) es una **protección técnica** contra
  rangos desmesurados al generar números; no es una regla de negocio.
- Solo se pueden eliminar rifas en `DRAFT` sin números reservados ni vendidos.
  Las rifas publicadas/cerradas se conservan para auditoría y resolución de
  disputas (03-data-model.md §6). El borrado en cascada solo afecta a números
  `AVAILABLE`.
