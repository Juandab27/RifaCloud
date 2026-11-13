# Mercado Pago sandbox

> Estado: **procedimiento preparado, no ejecutado**. No se han usado
> credenciales reales, no se ha creado una preferencia real y no se ha
> recibido un webhook de Mercado Pago en este entorno. No se debe afirmar que
> el sandbox funciona hasta completar esta guía con credenciales del usuario.

## 1. Qué necesita realmente el adaptador

Código relevante:

- `services/payments-service/src/Payments/MercadoPagoGateway.php`
- `services/payments-service/src/Payments/WebhookService.php`
- `services/payments-service/src/Config.php`
- `services/payments-service/public/index.php`

Variables necesarias para Checkout Pro:

| Variable | Uso |
|---|---|
| `PAYMENT_GATEWAY=mercadopago` | Selecciona el adaptador real |
| `MERCADOPAGO_ACCESS_TOKEN` | Access token del **vendedor de prueba** |
| `MERCADOPAGO_WEBHOOK_SECRET` | Secreto de firma del webhook |
| `MERCADOPAGO_WEBHOOK_URL` | URL HTTPS pública exacta del webhook |
| `MERCADOPAGO_BACK_URL` | URL HTTPS pública base; el adaptador añade `/orders/{id}?pago=retorno` |
| `MERCADOPAGO_SANDBOX=true` | Usa `sandbox_init_point` de la preferencia |
| `MERCADOPAGO_BASE_URL` | `https://api.mercadopago.com` |
| `MERCADOPAGO_TIMEOUT` | Timeout HTTP en segundos |

`MERCADOPAGO_PUBLIC_KEY` **no se usa** actualmente: el checkout es una
redirección a Checkout Pro, no un Brick embebido. Puede quedar vacía.

El adaptador:

- crea una preferencia en `POST /checkout/preferences` con
  `external_reference = MP-ORDER-{order_id}`, reutilizado como
  `X-Idempotency-Key`;
- envía `notification_url` y `back_urls`;
- en sandbox elige `sandbox_init_point`;
- verifica el pago con `GET /v1/payments/search` por `external_reference`;
- en el webhook valida la firma, consulta el pago autoritativo con
  `GET /v1/payments/{id}` y delega la transición a `orders-service`;
- no confía en el cuerpo de la notificación como prueba de pago.

## 2. Crear cuentas de prueba

Fuente oficial (consultada el 2026-11-09):

- Cuentas de prueba:
  <https://www.mercadopago.com.co/developers/es/docs/checkout-pro/test-accounts>
- Tarjetas de prueba:
  <https://www.mercadopago.com.co/developers/es/docs/checkout-pro/test-cards>
- Webhooks y firma:
  <https://www.mercadopago.com.co/developers/es/docs/your-integrations/notifications/webhooks>

Pasos:

1. Entra en Mercado Pago Developers con una cuenta de integración.
2. Crea una aplicación y selecciona **Checkout Pro**.
3. En **Tus integraciones → Tu aplicación → Cuentas de prueba**, crea:
   - una cuenta **Seller** (vendedor) del país de operación (Colombia/MCO);
   - una cuenta **Buyer** (comprador) del mismo país.
4. Usa las credenciales del vendedor de prueba para el access token. En
   Checkout Pro el token de prueba suele empezar por `APP_USR-`.
5. Guarda el usuario y contraseña del comprador para iniciar sesión en la
   pantalla de pago de Mercado Pago. Si se solicita un código, usa el código de
   6 dígitos de la cuenta de prueba.
6. No uses cuentas personales ni credenciales de producción.

No inventes usuarios, tokens, secretos ni tarjetas. Los datos deben salir del
panel de Mercado Pago.

## 3. Configurar la URL HTTPS temporal

Mercado Pago necesita una URL pública HTTPS. Opciones locales:

```powershell
# Cloudflare Tunnel (si cloudflared está instalado)
cloudflared tunnel --url http://localhost:80
# Copia la URL https://....trycloudflare.com que muestre

# Alternativa: ngrok
ngrok http 80
# Copia la URL https://....ngrok-free.app
```

No se crea ningún recurso AWS ni se abre un puerto público permanente.

Con la URL del túnel, define en la sesión de PowerShell:

```powershell
$env:SANDBOX_PUBLIC_URL      = 'https://TU-TUNEL'
$env:SANDBOX_SESSION_DOMAIN  = 'TU-TUNEL'   # solo el host, sin https://
$env:MERCADOPAGO_ACCESS_TOKEN   = '...'
$env:MERCADOPAGO_WEBHOOK_SECRET = '...'
$env:MERCADOPAGO_WEBHOOK_URL    = 'https://TU-TUNEL/api/webhooks/mercadopago'
$env:MERCADOPAGO_BACK_URL       = 'https://TU-TUNEL'
```

No imprimas esos valores ni los escribas en la documentación. No los guardes
en Git.

El overlay `docker-compose.mercadopago-sandbox.yml` inyecta configuración sin
secretos en el archivo:

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml `
  -f docker-compose.mercadopago-sandbox.yml `
  up -d --force-recreate app auth-service payments-service nginx
```

Se usa junto con `docker-compose.e2e.yml` para mantener `rifacloud_test`. El
overlay fuerza `PAYMENT_GATEWAY=mercadopago` y `MERCADOPAGO_SANDBOX=true`.

Comprobaciones sin mostrar secretos:

```powershell
docker compose exec -T payments-service printenv PAYMENT_GATEWAY MERCADOPAGO_SANDBOX MERCADOPAGO_BASE_URL
```

Debe mostrar `mercadopago`, `true` y `https://api.mercadopago.com`.

### Sesión y cookies a través del túnel

El overlay configura:

- `APP_URL=https://TU-TUNEL`;
- `SESSION_DOMAIN=TU-TUNEL`;
- `SANCTUM_STATEFUL_DOMAINS=TU-TUNEL`;
- `SESSION_SECURE_COOKIE=true`.

Abre RifaCloud usando la URL del túnel, no `localhost`, para que la cookie de
sesión y el retorno del checkout coincidan con el dominio público.

Si el túnel cambia, actualiza las variables y recrea `app`, `auth-service` y
`payments-service`.

## 4. Tarjetas de prueba de Colombia

Datos oficiales vigentes al 2026-11-09. Comprueba la página oficial antes de
usarlos porque Mercado Pago puede actualizarlos.

| Tipo | Marca | Número | CVV | Vencimiento |
|---|---|---|---|---|
| Crédito | Mastercard | `5254 1336 7440 3564` | `123` | `11/30` |
| Crédito | Visa | `4013 5406 8274 6260` | `123` | `11/30` |
| Crédito | American Express | `3743 781877 55283` | `1234` | `11/30` |
| Débito | Visa | `4915 1120 5524 6507` | `123` | `11/30` |

El **nombre del titular** decide el resultado:

| Resultado | Titular | Documento |
|---|---|---|
| Aprobado | `APRO` | `123456789` |
| Rechazado general | `OTHE` | `123456789` |
| Pendiente | `CONT` | — |

No uses estos datos fuera del sandbox. No son tarjetas reales.

## 5. Verificar firma y webhook

La firma oficial usa:

```text
id:{data.id};request-id:{x-request-id};ts:{ts};
```

y `HMAC-SHA256` con el secreto del webhook. El servicio implementa esta
validación en `MercadoPagoGateway::assertValidSignature` y después consulta el
pago real.

Comprobaciones básicas sin una notificación real:

```powershell
# GET no permitido: 405
curl.exe -i https://TU-TUNEL/api/webhooks/mercadopago

# POST vacío: 400
curl.exe -i -X POST https://TU-TUNEL/api/webhooks/mercadopago `
  -H "Content-Type: application/json" --data "{}"
```

Con el secreto configurado, un cuerpo con `type` y `data.id` pero sin firma
válida debe devolver **401**. No fabriques una firma con un secreto que no
conozcas.

La prueba real requiere que Mercado Pago envíe una notificación desde el panel
o desde un pago de prueba. Verifica:

1. `payment_webhook_events` recibe una fila `PROCESSED`.
2. El pago se consulta de nuevo en Mercado Pago.
3. La orden pasa a `PAID` y los números a `SOLD` una sola vez.
4. Un reenvío de la misma notificación no crea una segunda venta.
5. Una firma inválida no cambia la orden.

Consultas de solo lectura:

```sql
SELECT id, status, external_id, order_id FROM rifacloud_test.payments ORDER BY id DESC LIMIT 10;
SELECT provider_event_id, event_type, status FROM rifacloud_test.payment_webhook_events ORDER BY id DESC LIMIT 10;
```

## 6. Escenarios de pago

### Aprobado

1. Inicia sesión como comprador demo en RifaCloud y reserva un número.
2. Pulsa **Iniciar pago**; se abre Checkout Pro sandbox.
3. En una ventana incógnito, inicia sesión como comprador de prueba de Mercado
   Pago.
4. Paga con la tarjeta Mastercard/Visa y titular `APRO`, documento
   `123456789`.
5. Vuelve a RifaCloud: la URL de retorno no marca la orden como pagada; el
   backend ejecuta `/verify`.
6. Comprueba orden `PAID`, pago `APPROVED`, número `SOLD`.
7. Comprueba que el webhook llegó y que una notificación duplicada no vende
   otra vez.

### Rechazado

1. Repite con titular `OTHE`.
2. Comprueba orden `REJECTED`, pago `REJECTED` y números liberados.
3. La UI debe mostrar la tarjeta de recuperación para elegir otros números.

### Pendiente / incierto

1. Repite con titular `CONT`.
2. Comprueba que la orden no se marca `PAID` ni se liberan los números.
3. Revisa el estado real con **Verificar pago** o el reconciliador.
4. Si el proveedor devuelve un estado incierto, la orden debe quedar en
   revisión y requerir intervención administrativa; no debe autoapropiarse el
   pago.

## 7. Notificaciones duplicadas

Usa el panel de Mercado Pago o el reenvío de la misma notificación; no intentes
falsificar la firma. El servicio ya implementa:

- `UNIQUE(provider, provider_event_id)`;
- lock corto por evento;
- comando idempotente hacia `orders-service`;
- una sola venta aunque lleguen dos notificaciones.

Comprueba en la base de prueba que solo hay un evento y que no aumentó el
número de ventas de la orden.

## 8. Limitaciones y pendientes

- Este procedimiento no se ha ejecutado con credenciales reales.
- El túnel es temporal; si cambia el host, hay que recrear la configuración.
- No se debe usar `PAYMENT_GATEWAY=fake` en producción; el servicio lo rechaza.
- No se deben usar credenciales de producción en el sandbox.
- La confirmación oficial es siempre la consulta server-side al proveedor, no
  la URL de retorno ni el cuerpo del webhook.
- Si Mercado Pago cambia tarjetas, endpoints o requisitos de la cuenta, prevalece
  su documentación oficial.

## 9. Limpieza

```powershell
# Volver al gateway simulado y al stack normal
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --force-recreate payments-service app auth-service nginx
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1
```

No borres credenciales del panel de Mercado Pago sin autorización. Rota o
revoca las credenciales de prueba si dejaron de ser necesarias.
