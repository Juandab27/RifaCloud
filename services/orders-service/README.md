# orders-service - órdenes, reservas y vencimiento (S3)

Servicio independiente (proceso y contenedor propios) que expone la creación de
órdenes con reserva atómica de números, la lectura de una orden y el vencimiento
de reservas.

> **Alcance de esta extracción**
>
> La SDD aprobada y ADR-001 definen una evolución incremental hacia cuatro
> servicios de negocio. S3 traslada al servicio las escrituras de `orders`,
> `order_items` y las columnas de reserva de `raffle_numbers`, además del
> vencimiento de reservas. Los pagos siguen en el monolito hasta S4: mientras
> tanto, checkout/webhook/verify todavía escriben órdenes y números. Es la única
> escritura cruzada restante y está documentada como excepción transitoria.
>
> La migración es reversible: eliminando las dos `location` de órdenes en Nginx,
> las rutas vuelven al monolito sin migración de datos.

## Rutas atendidas por este servicio

| Método | Ruta | Contrato |
|---|---|---|
| POST | `/api/orders` | Crea la orden y reserva los números en una sola transacción |
| GET | `/api/orders/{id}` | Detalle de la orden del usuario autenticado |
| POST | `/internal/orders/expire-reservations` | Comando interno firmado (nunca expuesto por Nginx) |
| GET | `/health` | Salud del proceso y conectividad a MySQL (interno) |

Todo lo demás (`/api/orders/{id}/payments*`, checkout, verificación, webhooks)
pertenece al monolito hasta S4.

## Autenticación y autorización

- La identidad se obtiene de la cookie `rifacloud-session`, descifrada con el
  `APP_KEY` compartido (MAC y prefijo verificados). Nunca se confía en cabeceras
  de identidad enviadas por el cliente.
- La sesión debe existir en `sessions`, no estar expirada y su
  `password_hash_web` debe coincidir con la contraseña actual.
- Cualquier usuario autenticado puede crear una orden y leer **solo** las suyas.
  Anónimo → `401`; orden de otro usuario → `404` (no `403`, para no revelar su
  existencia).
- CSRF solo en peticiones *stateful* (Origin/Referer/`Sec-Fetch-Site`), con
  `X-XSRF-TOKEN`/`X-CSRF-TOKEN`/`_token`. Desajuste → `419`.
- El servicio solo **lee** `sessions` y `users`.

> **Límite conocido:** como `sessions` pertenece a auth-service, este servicio no
> refresca `sessions.last_activity`. Un usuario que solo navega el catálogo y crea
> órdenes no renueva su sesión y esta caduca a los `SESSION_LIFETIME` (120 min).
> Es la misma política que sigue `raffles-service` en administración; el contrato
> de "session touch" queda pendiente.

## Reserva atómica y doble venta

Una transacción MySQL:

1. `SELECT ... FROM raffles ... FOR UPDATE` (estado y ventana de compra).
2. `INSERT INTO orders` (`PENDING_PAYMENT`, `expires_at = now + RESERVATION_MINUTES`).
3. `SELECT ... FROM raffle_numbers ... ORDER BY number FOR UPDATE`.
4. `UPDATE raffle_numbers SET status='RESERVED', ... WHERE id IN (...) AND status='AVAILABLE'`
   con comprobación de filas afectadas.
5. `INSERT INTO order_items` con `price_at_purchase` en centavos.
6. `COMMIT`.

Reintentos acotados solo para deadlock (1213), lock wait timeout (1205), SQLSTATE
`40001` o duplicado de `order_number`. Si otro proceso ganó la carrera, la
respuesta es `409` con `code=NUMBERS_UNAVAILABLE` y `unavailable_numbers`.

## Vencimiento de reservas

- `POST /internal/orders/expire-reservations` se autentica con
  `HMAC-SHA256(timestamp "\n" SHA256(body))`, usando una clave derivada de
  `APP_KEY` (`HMAC("rifacloud-orders-internal", APP_KEY)`), con ventana de reloj
  de 60 s.
- Cada ejecución toma `GET_LOCK('rifacloud_orders_expire_reservations', 0)`. Si
  otra ejecución lo tiene, responde `{"expired":0,"skipped":true}`.
- Solo expira órdenes `PENDING_PAYMENT` vencidas. Nunca libera números si hay un
  pago `UNKNOWN`, `APPROVED` o `REFUND_REQUIRED`, ni si algún número está `SOLD`.
  Los `order_items` se conservan para auditoría.
- El comando `orders:expire-reservations` del monolito solo invoca este endpoint
  y falla en cerrado (exit 1) si el servicio no responde.

## Configuración

| Variable | Default | Uso |
|---|---|---|
| `ORDERS_DB_HOST` / `ORDERS_DB_PORT` | `DB_HOST` / `DB_PORT` | MySQL |
| `ORDERS_DB_DATABASE` / `ORDERS_DB_USERNAME` / `ORDERS_DB_PASSWORD` | `DB_*` | Esquema compartido (transitorio) |
| `ORDERS_RESERVATION_MINUTES` | `RESERVATION_MINUTES` (10) | Ventana de reserva |
| `ORDERS_ORDER_MAX_NUMBERS` | `ORDER_MAX_NUMBERS` (50) | Guarda técnica de payload |
| `PAYMENT_GATEWAY` | `fake` | `simulated_payments` / `simulated` |
| `APP_KEY` | — | Cookie de sesión y secreto interno |
| `ORDERS_INTERNAL_CLOCK_SKEW` | 60 | Ventana anti-replay |

## Pruebas

```bash
# Integración contra rifacloud_test (rechaza cualquier otra base)
docker run --rm --network rifacloud_default --env-file src/.env \
  -e ORDERS_DB_HOST=mysql -e ORDERS_DB_DATABASE=rifacloud_test \
  -e ORDERS_TEST_DB_DATABASE=rifacloud_test \
  -v "${PWD}/services/orders-service:/var/www/html" \
  rifacloud-orders-service php tests/orders_integration.php

# E2E a través de Nginx (fixtures temporales con limpieza)
powershell -ExecutionPolicy Bypass -File scripts/verify-orders-service.ps1
```

La prueba de integración incluye concurrencia real con dos procesos PHP: dos
`POST` simultáneos por los mismos números producen exactamente un `201` y un
`409`, con una sola orden creada.
