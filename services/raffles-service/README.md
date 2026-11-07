# raffles-service - catálogo y administración de rifas (S0 + S2)

Servicio independiente (proceso y contenedor propios) que expone el catálogo
público de rifas, la disponibilidad de números y la administración de rifas.

> **Alcance de esta extracción**
>
> La SDD aprobada y ADR-001 definen una evolución incremental hacia cuatro
> servicios de negocio. S2 traslada al servicio las escrituras de `raffles` y
> la generación de números. Las reservas, órdenes, ventas y pagos permanecen en
> sus servicios propietarios. La migración es reversible enrutando las rutas
> de vuelta al monolito, sin migración de datos.

## Rutas atendidas por este servicio

### Catálogo público (S0)

| Método | Ruta | Contrato |
|---|---|---|
| GET | `/api/raffles` | Catálogo paginado de rifas `PUBLISHED` |
| GET | `/api/raffles/{id}` | Detalle de una rifa `PUBLISHED` |
| GET | `/api/raffles/{id}/numbers` | Números paginados y conteos por estado |
| GET | `/health` | Salud del proceso y conectividad a MySQL (interno) |

### Administración (S2)

| Método | Ruta | Contrato |
|---|---|---|
| GET | `/api/admin/raffles` | Listado paginado de todas las rifas (15/página) |
| POST | `/api/admin/raffles` | Crea una rifa `DRAFT` y genera sus números (`201`) |
| GET | `/api/admin/raffles/{id}` | Detalle de cualquier rifa |
| PUT | `/api/admin/raffles/{id}` | Edita una rifa `DRAFT` (regenera rango si cambia) |
| DELETE | `/api/admin/raffles/{id}` | Borra una rifa `DRAFT` sin números asignados (`204`) |
| POST | `/api/admin/raffles/{id}/publish` | `DRAFT` → `PUBLISHED` |
| POST | `/api/admin/raffles/{id}/close` | `PUBLISHED` → `CLOSED` |

El JSON conserva la forma del `RaffleResource` y del paginador de Laravel:
`data`, `links`, `meta`, `numbers_count`, `reservation_minutes`,
`max_numbers_per_order`, fechas ISO-8601, precios en centavos y conteos
`available` / `reserved` / `sold`. Los errores conservan `message` + `errors`
(422), `401`, `403`, `404`, `405` y `419`.

## Autorización administrativa

El servicio **no confía en cabeceras de identidad** enviadas por el cliente.
En cada request administrativo:

1. Descifra la cookie `rifacloud-session` con el `APP_KEY` compartido y valida
   el prefijo HMAC de Laravel.
2. Busca la sesión en la tabla `sessions` y rechaza sesiones expiradas o sin
   `user_id`.
3. Exige `users.role = 'ADMIN'`.
4. Valida `password_hash_web` para que un cambio de contraseña invalide la
   sesión, igual que `AuthenticateSession` de Laravel.
5. Valida CSRF (`X-XSRF-TOKEN`/`X-CSRF-TOKEN`, o `Sec-Fetch-Site:
   same-origin`) antes de las escrituras, como Sanctum.

El servicio **solo lee** `sessions` y `users`; no las escribe.

## Rutas que siguen en sus servicios propietarios

- Autenticación: `/api/register`, `/api/login`, `/api/logout`, `/api/me`,
  `/sanctum/csrf-cookie` (`auth-service`).
- Órdenes y reservas: `POST /api/orders`, `GET /api/orders/{order}` (monolito,
  futuro `orders-service`).
- Pagos: `POST /api/orders/{order}/payments`,
  `POST /api/orders/{order}/payments/{payment}/verify` (monolito, futuro
  `payments-service`).
- Webhooks: `POST /api/webhooks/mercadopago`.
- Resolución de revisión de pagos por administrador.

## Propiedad de datos

El servicio usa la misma instancia y el mismo esquema MySQL durante la
transición. Es una decisión **transitoria** (ADR-001, S5).

| Tabla | Propietario | Uso del servicio |
|---|---|---|
| `raffles` | `raffles-service` (S2) | `SELECT`, `INSERT`, `UPDATE`, `DELETE` |
| `raffle_numbers` | `raffles-service` para generación; `orders` para reservas/ventas | `SELECT`; `INSERT`/`DELETE` solo de filas `AVAILABLE` |
| `sessions`, `users` | `auth-service` | Solo `SELECT` (autorización) |
| `orders`, `order_items`, `payments`, `payment_webhook_events` | Monolito / futuro `orders-service` y `payments-service` | No se usan |

Guardas de integridad:

- El rango de una rifa solo se regenera si **ningún** número está `RESERVED` o
  `SOLD`; de lo contrario responde `422`.
- Una rifa no se borra si tiene números reservados o vendidos; el `DELETE`
  solo puede eliminar filas `AVAILABLE` (cascada FK).
- La generación corre dentro de la misma transacción que crea/edita la rifa y
  usa el índice único `(raffle_id, number)`.
- El bloqueo de fila (`SELECT ... FOR UPDATE`) serializa ediciones, publicación
  y borrado contra el resto de escritores del esquema compartido.

### Por qué las reservas siguen en el monolito

`raffles` y `raffle_numbers` participan en la transacción atómica de reserva:

1. `OrderReservationService` bloquea la fila de `raffles` con
   `SELECT ... FOR UPDATE`.
2. Bloquea los `raffle_numbers` solicitados.
3. Actualiza `status`, `reserved_until` y `order_id` en la misma transacción
   que crea la orden y sus `order_items`.

Separar esas tablas en otra base rompería la atomicidad y la prevención de
doble venta (NFR-002, NFR-004). Por eso S2 **no** mueve reservas, órdenes ni
pagos: solo mueve la configuración de la rifa y la generación de números.

### Riesgos asumidos (temporales)

- Acoplamiento al esquema compartido: un cambio de columnas en `raffles` o
  `raffle_numbers` puede romper el servicio.
- El servicio comparte credenciales de MySQL con el monolito. La conexión del
  catálogo sigue siendo de solo lectura; la de administración escribe solo las
  dos tablas anteriores. S5 debe introducir usuarios/grants por servicio.
- Los requests administrativos no actualizan `sessions.last_activity` (esa
  tabla pertenece a `auth-service`). La sesión se renueva al usar las rutas de
  autenticación; un contrato de "touch session" queda para S5.
- El catálogo y la administración devuelven 503 controlado si el servicio se
  detiene; autenticación, órdenes y pagos siguen operando.

## Variables de entorno

| Variable | Default | Uso |
|---|---|---|
| `RAFFLES_DB_HOST` | `mysql` | Host MySQL interno |
| `RAFFLES_DB_PORT` | `3306` | Puerto MySQL |
| `RAFFLES_DB_DATABASE` | `rifacloud` | Esquema compartido |
| `RAFFLES_DB_USERNAME` | `rifacloud` | Usuario MySQL |
| `RAFFLES_DB_PASSWORD` | vacío | Contraseña MySQL (solo desde entorno) |
| `RAFFLES_RESERVATION_MINUTES` | `10` | Debe coincidir con el monolito |
| `RAFFLES_ORDER_MAX_NUMBERS` | `50` | Debe coincidir con el monolito |
| `RAFFLE_MAX_NUMBERS` | `10000` | Límite técnico de generación |
| `APP_KEY` | vacío | Compartido con el monolito/auth-service (solo entorno) |
| `SESSION_COOKIE` | `rifacloud-session` | Cookie de sesión de Laravel |
| `SESSION_LIFETIME` | `120` | Minutos de vigencia de la sesión |
| `SESSION_TABLE` | `sessions` | Tabla de sesiones |

No hay archivo `.env` dentro del servicio ni secretos en la imagen. Compose
inyecta `env_file: ./src/.env` para compartir `APP_KEY` y la configuración de
sesión.

## Comandos

```sh
# Construir y iniciar solo este servicio
docker compose build raffles-service
docker compose up -d --no-deps raffles-service

# Ciclo de vida independiente
docker compose stop raffles-service
docker compose start raffles-service
docker compose ps raffles-service
docker compose logs -f raffles-service

# Pruebas de integración (siempre contra rifacloud_test)
docker compose run --rm \
  -e RAFFLES_DB_DATABASE=rifacloud_test \
  -e RAFFLES_TEST_DB_DATABASE=rifacloud_test \
  raffles-service php tests/repository_integration.php

docker compose run --rm \
  -e RAFFLES_DB_DATABASE=rifacloud_test \
  -e RAFFLES_TEST_DB_DATABASE=rifacloud_test \
  raffles-service php tests/admin_integration.php

# Verificación E2E del catálogo y de la caída controlada
pwsh -File scripts/verify-raffles-service.ps1

# Verificación E2E de administración S2 (crea y limpia datos temporales)
pwsh -File scripts/verify-raffles-service-admin.ps1
```

## Rollback

La extracción es reversible sin tocar datos:

1. Quitar el bloque `location ~ ^/api/admin/raffles` de
   `docker/nginx/default.conf` (y, si se desea revertir S0, el del catálogo).
2. Recargar/recrear Nginx.
3. `docker compose stop raffles-service` y eliminar el contenedor si se desea.

Las rutas de Laravel siguen existiendo en el monolito, por lo que las rutas
administrativas vuelven a `app` sin migración de datos.
