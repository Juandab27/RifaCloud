# RifaCloud

Plataforma web para gestionar rifas: catálogo público, selección de números y
panel de administración. Proyecto universitario de Cloud Computing.

Estado actual: **Fase 1 (Week 1)** del plan aprobado en `docs/sdd/04-implementation-plan.md`.

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

### Administrador inicial

`AdminUserSeeder` crea (o actualiza) el administrador leyendo
`ADMIN_EMAIL` y `ADMIN_PASSWORD` de `src/.env`. Si están vacíos, el seeder se
omite y no existe ningún administrador por defecto.

## Pruebas

```sh
docker compose exec app php artisan test
```

Antes de la primera ejecución, crea `src/.env.testing` a partir de la plantilla y
coloca la misma contraseña de base de datos que usaste en `src/.env`:

```sh
cp src/.env.testing.example src/.env.testing
# edita DB_PASSWORD en src/.env.testing
```

Los tests usan el esquema `rifacloud_test` (creado por
`docker/mysql/init/01-test-database.sql`) y la configuración de
`src/.env.testing` (no versionado). No tocan la base de datos de desarrollo.

## Comandos útiles

```sh
docker compose ps
docker compose logs -f app
docker compose exec app php artisan migrate:fresh --seed
docker compose down          # conserva el volumen mysql_data
docker compose down -v       # elimina también los datos locales
```

## Notas de diseño

- MySQL no publica puertos: solo es accesible desde la red interna de Docker
  (NFR-011 / AC-029).
- Los valores monetarios se almacenan en **centavos** (NFR-013).
- El correo local usa el mailer `log` (P1); Mailtrap se configura en P2.
- `RAFFLE_MAX_NUMBERS` (por defecto 10000) es una **protección técnica** contra
  rangos desmesurados al generar números; no es una regla de negocio.
- La SDD (`docs/sdd/`) y `AGENTS.md` son documentación local y están excluidos
  de Git a propósito.
