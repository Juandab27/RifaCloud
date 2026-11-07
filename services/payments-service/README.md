# payments-service - pagos, Mercado Pago y webhooks (S4)

Servicio independiente (proceso y contenedor propios) que expone checkout,
verificación, revisión administrativa y notificaciones de Mercado Pago. Es el
único propietario de `payments` y `payment_webhook_events`.

> **Alcance de esta extracción**
>
> La SDD aprobada y ADR-001 definen una evolución incremental hacia cuatro
> servicios de negocio. S4 traslada al servicio el checkout, la verificación
> server-side, la resolución de incidencias, los webhooks y la reconciliación.
> Las transiciones de `orders`, `order_items` y `raffle_numbers` las ejecuta
> `orders-service` mediante un comando interno firmado e idempotente: este
> servicio nunca escribe esas tablas.
>
> Rollback: eliminando las cuatro `location` de pagos en Nginx, las rutas
> vuelven al monolito, que conserva las rutas y los mismos datos.

## Rutas atendidas por este servicio

| Método | Ruta | Contrato |
|---|---|---|
| POST | `/api/orders/{order}/payments` | Crea (201) o reanuda (200) el checkout del dueño |
| POST | `/api/orders/{order}/payments/{payment}/verify` | Verifica con el proveedor; dueño, orden pagable |
| POST | `/api/admin/orders/{order}/payments/{payment}/resolve` | ADMIN resuelve una incidencia `PAYMENT_REVIEW` |
| POST | `/api/webhooks/mercadopago` | Notificación firmada por Mercado Pago |
| POST | `/internal/payments/reconcile` | Reconciliación firmada, solo scheduler (nunca expuesta por Nginx) |
| GET | `/health` | Salud del proceso y MySQL (interno) |

## Contrato interno con orders-service

`POST /internal/orders/payment-result` (desde este servicio):

- Cabeceras `X-RifaCloud-Timestamp` y `X-RifaCloud-Signature`.
- Firma `HMAC-SHA256(timestamp "\n" SHA256(body))` con
  `HMAC-SHA256("rifacloud-payments-internal", APP_KEY)`; ventana de reloj de 60 s.
- Cuerpo: `payment_id`, `order_id`, `external_id`, `amount`, `currency`,
  `status`, `raw`, `source`, `resolve_review`, `actor_role`.
- Respuesta: `{outcome, reason, payment_status, order_status, evidence,
  payment_changed, order_changed}`.
- Idempotencia por convergencia de estado: repetir el mismo resultado no vende
  un número dos veces, no reabre una orden final y no vuelve a fijar `paid_at`.
  Un evento obsoleto o conflictivo devuelve `ignored`/`noop` sin segundo efecto.

La transacción de orders-service bloquea `orders → payments → raffle_numbers`
(la fila de `payments` solo se bloquea, nunca se escribe), decide el nuevo estado
y aplica la venta/liberación de números. Después, payments-service persiste el
`payment_status` devuelto en su propia fila con la tabla de transiciones
monotónica.

## Mercado Pago

- Checkout Pro sandbox; `external_reference` determinista `MP-ORDER-{id}` reusado
  como `X-Idempotency-Key`.
- La preferencia no cobra; el estado real siempre se consulta al proveedor.
- Webhook: se valida la firma oficial (`id:{data.id};request-id:{x-request-id};ts:{ts};`)
  y se vuelve a consultar el pago. El cuerpo de la notificación nunca es prueba
  de pago.
- Duplicados: `UNIQUE(provider, provider_event_id)` + bloqueo corto por evento +
  comando idempotente.
- `reconcile` y `reconcile --approved` delegan desde el comando del monolito
  (`payments:reconcile`), que falla en cerrado si el servicio no responde.
- Nunca se crea un segundo cobro: `UNIQUE(order_id)`, se reanuda un `PENDING` y
  un pago `UNKNOWN`/final bloquea un checkout nuevo.

### Modo simulado

`PAYMENT_GATEWAY=fake` se usa solo en pruebas locales; el servicio se niega a
arrancar con `APP_ENV=production`. El driver simulado acepta notificaciones sin
firma (`{"type":"payment","data":{"id":"SIM-COP-...","status":"APPROVED"}}`) para
poder verificar la idempotencia de webhooks sin credenciales.

## Configuración

| Variable | Default | Uso |
|---|---|---|
| `PAYMENTS_DB_*` | `DB_*` | MySQL compartido (transitorio) |
| `PAYMENT_GATEWAY` | `fake` | `mercadopago` en el stack real |
| `PAYMENT_FAKE_OUTCOME` | `UNKNOWN` | `APPROVED`, `REJECTED`, `UNKNOWN`, `TIMEOUT`, `PENDING`, `REFUND_REQUIRED` |
| `MERCADOPAGO_ACCESS_TOKEN` / `MERCADOPAGO_WEBHOOK_SECRET` | — | Solo entorno; nunca en código ni logs |
| `MERCADOPAGO_SANDBOX` / `MERCADOPAGO_BASE_URL` / `MERCADOPAGO_TIMEOUT` | sandbox | Proveedor |
| `ORDERS_SERVICE_URL` / `ORDERS_INTERNAL_TIMEOUT` | `http://orders-service` / 5 | Comando interno |
| `APP_KEY` | — | Sesión compartida y claves internas |
| `PAYMENTS_INTERNAL_CLOCK_SKEW` | 60 | Ventana anti-replay |

## Pruebas

```bash
# Contrato del adaptador de Mercado Pago (sin red, transporte falso).
docker run --rm -v "${PWD}/services/payments-service:/var/www/html" \
  rifacloud-payments-service php tests/gateway_integration.php

# Requiere un orders-service apuntando a rifacloud_test y todas las migraciones.
docker run --rm --network rifacloud_default --env-file src/.env \
  -e PAYMENTS_DB_HOST=mysql -e PAYMENTS_DB_DATABASE=rifacloud_test \
  -e PAYMENTS_TEST_DB_DATABASE=rifacloud_test \
  -e ORDERS_SERVICE_URL=http://rifacloud-orders-service-test \
  -v "${PWD}/services/payments-service:/var/www/html" \
  rifacloud-payments-service php tests/payments_integration.php

# Dos procesos reales con el mismo webhook simultáneo.
docker run ... php tests/webhook_concurrency.php

# E2E por Nginx sobre rifacloud_test con el gateway simulado.
powershell -ExecutionPolicy Bypass -File scripts/verify-payments-service.ps1
```

> **Esquema principal (S5 aplicado):** `rifacloud` ya tiene el índice único
> `payments.order_id` y la tabla `payment_webhook_events`; cada servicio usa su
> propio usuario MySQL. Pendiente para producción: configurar
> `MERCADOPAGO_WEBHOOK_SECRET` y una URL pública. No ejecutes `migrate` ni
> cambies credenciales sin autorización.
