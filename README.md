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

# 4. Construir y levantar servicios
docker compose build
docker compose up -d

# 5. Esquema y datos iniciales
docker compose exec app php artisan migrate --seed
```

La aplicación queda en <http://localhost>.

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

### Ejecución

```sh
docker compose exec app php artisan test
```

Los tests usan exclusivamente el esquema `rifacloud_test` configurado en
`src/.env.testing` (no versionado). La suite no modifica la base de datos de
desarrollo `rifacloud`; `RefreshDatabase` deja el esquema de test limpio.

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
- En local el gateway de pagos es simulado (`PAYMENT_GATEWAY=fake`). La interfaz
  lo marca como **SIMULADO** y no se procesa dinero real. La aplicación se niega
  a arrancar en `production` si `PAYMENT_GATEWAY=fake`.
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
