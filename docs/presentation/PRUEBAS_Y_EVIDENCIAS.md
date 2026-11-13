# RifaCloud — Pruebas y evidencias

**Proyecto:** RifaCloud  
**Uso:** saber qué se probó, qué resultado quedó registrado y qué se puede mostrar sin inventar  
**Base:** suites del repositorio y resultados documentados en SDD/README

> **Aviso metodológico**
>
> Este documento **recopila resultados ya registrados** en la documentación del
> repositorio al cierre de cada incremento. No se re-ejecutaron suites que
> escriben datos durante la preparación de esta guía, porque la restricción de
> la tarea lo prohíbe. Antes de la sustentación, el equipo puede repetir las
> verificaciones seguras indicadas en la sección 7.
>
> “Resultado documentado” no es lo mismo que “resultado de hoy”. Si el profesor
> pregunta “¿lo corriste ahora?”, la respuesta honesta es: “la evidencia está
> registrada en el SDD; la suite segura se puede repetir en vivo”.

---

## 1. Qué tipos de evidencia existen

| Tipo | Qué demuestra | Dónde vive |
|---|---|---|
| Pruebas de integración PHP | Contratos, autenticación, validaciones, transacciones, estados | `services/*/tests/` |
| Suites Laravel | Regresiones del monolito y rutas de rollback | `src/tests/` |
| E2E por Nginx | Rutas reales, 401/403/409/419/503, stop/start | `scripts/verify-*.ps1` |
| Concurrencia real | Doble venta y webhook duplicado con dos procesos | `tests/concurrent_create.php`, `tests/webhook_concurrency.php` |
| Pruebas de navegador | UI, estados, accesibilidad básica, legal/checkout | `frontend/` (Playwright) |
| Pruebas unitarias de frontend | Derivación de estado temporal | `frontend/` (16 checks) |
| Documentación SDD | Resultados, fechas, decisiones y limitaciones | `docs/sdd/` |

---

## 2. Suites existentes y cómo se ejecutan

### 2.1 Monolito Laravel

```sh
docker compose exec app php artisan test
```

- Usa `src/.env.testing` y el esquema `rifacloud_test`.
- `RefreshDatabase` deja el esquema de test limpio.
- No modifica `rifacloud`.

### 2.2 auth-service

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-auth-service-integration.ps1
powershell -ExecutionPolicy Bypass -File scripts/verify-auth-isolation.ps1
```

- Integración contra `rifacloud_test` con limpieza del usuario creado.
- Aislamiento: detiene `auth-service` y comprueba 503 controlado; restaura.
- También existe `scripts/verify-auth-session-compat.php` para la cookie
  compartida y `scripts/verify-auth-consent-evidence.php` para S11.

### 2.3 raffles-service

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-raffles-service.ps1
powershell -ExecutionPolicy Bypass -File scripts/verify-raffles-service-admin.ps1
```

- Catálogo, detalle, números, 503.
- Administración: CRUD, publish/close, validaciones, autorización ADMIN.
- Integración directa:

```sh
docker compose run --rm \
  -e RAFFLES_DB_DATABASE=rifacloud_test \
  -e RAFFLES_TEST_DB_DATABASE=rifacloud_test \
  raffles-service php tests/repository_integration.php

docker compose run --rm \
  -e RAFFLES_DB_DATABASE=rifacloud_test \
  -e RAFFLES_TEST_DB_DATABASE=rifacloud_test \
  raffles-service php tests/admin_integration.php
```

### 2.4 orders-service

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-orders-service.ps1
```

- Integración: auth, CSRF, validación, códigos de conflicto, reserva atómica,
  propiedad, doble venta real con dos procesos, vencimiento, lock, firma
  interna, proyección de pagos.
- El test de integración **se niega a correr** si la base no es
  `rifacloud_test`.
- La prueba de concurrencia crea dos procesos PHP separados y los libera casi
  al mismo tiempo con un archivo barrera.

### 2.5 payments-service

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-payments-service.ps1
```

- Integración con un `orders-service` real apuntando a `rifacloud_test`.
- Contrato del adaptador de Mercado Pago sin red (transporte falso).
- Concurrencia real de webhooks con dos procesos.
- E2E por Nginx con gateway simulado.

Comandos directos:

```sh
# Contrato del adaptador (sin red)
docker run --rm -v "${PWD}/services/payments-service:/var/www/html" \
  rifacloud-payments-service php tests/gateway_integration.php

# Integración (requiere orders-service de test y rifacloud_test)
docker run --rm --network rifacloud_default --env-file src/.env \
  -e PAYMENTS_DB_HOST=mysql -e PAYMENTS_DB_DATABASE=rifacloud_test \
  -e PAYMENTS_TEST_DB_DATABASE=rifacloud_test \
  -e TEST_FIXTURE_DB_PASSWORD="$TEST_FIXTURE_DB_PASSWORD" \
  -e ORDERS_SERVICE_URL=http://rifacloud-orders-service-test \
  -v "${PWD}/services/payments-service:/var/www/html" \
  rifacloud-payments-service php tests/payments_integration.php

# Dos procesos reales con el mismo webhook
docker run ... php tests/webhook_concurrency.php
```

### 2.6 Flujos S6/S7/S10.3/S11

| Script | Qué verifica |
|---|---|
| `scripts/verify-s6-session-and-return.ps1` | Cookie, `/me`, retorno de checkout, 401/409/419/503, vencimiento, idempotencia |
| `scripts/verify-s7-purchases.ps1` | Historial, propiedad, paginación, estados de números |
| `scripts/verify-admin-dashboard.ps1` | 401/403/200, valores exactos, 503 por servicio |
| `scripts/verify-auth-consent-evidence.php` | Evidencia de consentimiento en `user_consents` |
| `scripts/verify-demo-environment.ps1` | Demo: login de tres usuarios, catálogo, historial, aislamiento, métricas; **no escribe datos** |

### 2.7 Frontend

```sh
cd frontend
npm run test:unit    # 16 comprobaciones puras del estado temporal
npx playwright test  # 20 pruebas de navegador documentadas
```

- Las pruebas de navegador interceptan `/api/**` en el navegador: usan datos
  aislados, un gateway simulado y **no escriben en MySQL ni procesan pagos**.
- Usan el Chrome/Edge instalado (`channel: 'chrome'`), no descargan navegadores.

---

## 3. Resultados documentados por etapa

> **Fuente:** `docs/sdd/02-technical-design.md` (secciones 3 y 8),
> `docs/sdd/04-implementation-plan.md` (sección 10), `README.md` y
> `docs/legal/README.md`. Son los resultados registrados al cerrar cada etapa.

| Etapa | Evidencia registrada | Fuente |
|---|---|---|
| **S0 Catálogo** | Catálogo, detalle y números funcionando; E2E verde; 503 al detener el servicio; auth/orders/payments sin afectar | SDD 02 §3 |
| **S1 auth** | **12/12** integración (incluye sesión aceptada por el monolito), **14/14** aislamiento, **59** tests Laravel | SDD 02 §3 |
| **S2 admin rifas** | **56/56** aserciones de integración admin, **64/64** E2E por Nginx (auth, CRUD, validación, reservas, webhook, stop/start), S0 verde, lint PHP | SDD 02 §3 |
| **S3 órdenes** | **97/97** integración (auth, CSRF, validación, conflictos, reserva atómica, propiedad, doble venta real, vencimiento, lock, firma, proyección), **33/33** E2E, **186/186** monolito | SDD 02 §3 |
| **S4 pagos** | **72/72** integración, **38/38** adaptador, **9/9** concurrencia real de webhooks, **39/39** E2E, **190/190** monolito | SDD 02 §3 |
| **S5 mínima** | Migraciones aditivas aplicadas por separado; **4** usuarios de mínimo privilegio validados (positivo/negativo/locking); servicios recreados uno a uno; **190/190** monolito + **33/33** orders E2E | SDD 02 §8 |
| **S6 continuidad** | **42/42** E2E, **12/12** auth, **190/190** monolito, `tsc --noEmit` + `vite build` | SDD 02 §3 |
| **S7 Mis compras** | **123/123** orders integration (26 checks de listado), **44/44** E2E, **190/190** monolito, build | SDD 02 §3 |
| **S9.1 audit fixes** | **31/31** admin/result, **12/12** reconcile/notify, **193/193** monolito, regresiones previas verdes, backup restaurable | SDD 02 §3 |
| **S10.2 UX** | **16/16** unit de estados, **12/12** Playwright en esa etapa, regresiones críticas verdes, build | SDD 02 §3 |
| **S10.3 dashboard** | **28/28** E2E de dashboard, **20/20** Playwright, **129/129** orders integration, **77/77** payments integration, **42/42** S6, **44/44** S7, **39/39** payments E2E, **12/12** auth, **193/193** monolito | SDD 02 §3 |
| **S11 privacidad** | **4/4** pruebas de consentimiento, **6/6** Playwright legal/checkout, **19/19** auth-service integration; migración y grant aplicados a `rifacloud` el 2026-11-12; registro controlado con tres filas verificado | Pruebas: SDD 02 §8 y `docs/legal/README.md`; estado de migración: `AGENTS.md` (2026-11-12) |

### 3.1 Cómo leer estos números

- No son “tests unitarios sueltos”: varias cifras son **aserciones** dentro de
  scripts E2E o de integración.
- Cada etapa tiene su propio alcance; no se deben sumar todos los números y
  presentarlos como “700 tests”.
- La cifra más reciente del monolito es **193/193** (S9.1/S10.3).
- La cifra más reciente de Playwright es **20/20** (S10.3/README).
- La cifra más reciente de orders es **129/129** (S10.3).
- La cifra más reciente de payments es **77/77** (S10.3).

---

## 4. Pruebas de concurrencia

### 4.1 Doble venta de números

**Qué se prueba:** dos procesos PHP independientes envían `POST /api/orders`
por la misma rifa y los mismos números, casi al mismo tiempo.

**Resultado registrado:** exactamente **un `201`** y **un `409`**, una sola
orden creada, números reservados una vez.

**Por qué es creíble:**

- Son procesos reales, no dos llamadas en el mismo proceso.
- Usan una barrera para liberar ambos casi simultáneamente.
- La transacción usa `SELECT ... FOR UPDATE` y verifica filas afectadas.
- La base tiene `UNIQUE(raffle_id, number)`.

**Archivo:** `services/orders-service/tests/concurrent_create.php` y
`orders_integration.php`.

### 4.2 Webhook duplicado

**Qué se prueba:** dos procesos independientes envían el mismo webhook
simulado al mismo tiempo.

**Resultado registrado:** **una** fila de evento, el número queda `SOLD` una
sola vez, la orden queda `PAID` una sola vez.

**Por qué es creíble:**

- `UNIQUE(provider, provider_event_id)`.
- Lock corto por evento.
- Comando idempotente a `orders-service`.
- Convergencia por estado, no por “contador”.

**Archivo:** `services/payments-service/tests/webhook_concurrency.php`.

### 4.3 Vencimiento concurrente

**Qué se prueba:** dos ejecuciones del scheduler o una manual y otra automática
intentan expirar la misma reserva.

**Resultado registrado:** `GET_LOCK` hace que una ejecución devuelva
`skipped=true`; la otra libera una sola vez. No se libera una orden `PAID`,
`PAYMENT_REVIEW`, `REQUIRES_REFUND` ni con números `SOLD`.

**Archivo:** `ReservationExpiryService.php` y pruebas de S3/S6.

---

## 5. Pruebas de seguridad

| Control | Qué se prueba | Evidencia |
|---|---|---|
| Autenticación | Sin sesión → `401` JSON, no redirect HTML | S1/S3/S6 |
| Autorización admin | `USER` → `403`; `ADMIN` → `200` | S2/S10.3 |
| Propiedad de orden | Otro usuario → `404`, no `403` | S3/S7 |
| Cabeceras falsas | `X-User-Id`/`X-Role` no autorizan | AC-043 |
| CSRF | Token ausente/incorrecto → `419` | S2/S3/S6 |
| Cambio de contraseña | `password_hash_web` invalida la sesión | S2 |
| Firma interna | HMAC incorrecto/fuera de ventana → `401` | S3/S4 |
| Firma de webhook | Firma inválida → `401` | S4 |
| Grants MySQL | Escritura cruzada de columnas de negocio denegada | S5 |
| Secretos | Sin tokens en código/logs; variables de entorno | NFR-010 |
| Rate limit | Registro/login 6 por minuto → `429` | S9.1 |
| Logs | Sin payloads completos ni datos sensibles | Revisión de código |

**Matiz:** la prueba de grants demuestra que el usuario de un servicio no puede
cambiar columnas de negocio de otro. No demuestra aislamiento físico de bases,
porque no existe.

---

## 6. Pruebas de aislamiento

Se detiene un servicio a la vez y se comprueba que:

| Servicio detenido | Rutas afectadas | Qué sigue funcionando |
|---|---|---|
| `auth-service` | register/login/logout/me y CSRF → 503 | Catálogo, órdenes con sesión existente, pagos con sesión existente |
| `raffles-service` | catálogo, detalle, números y admin rifas → 503 | Auth, órdenes, pagos |
| `orders-service` | `POST/GET /api/orders*` → 503; expiración falla en cerrado | Catálogo, auth, lectura de pagos |
| `payments-service` | checkout/verify/resolve/webhook → 503; reconciliación falla en cerrado | Catálogo, auth, órdenes existentes |
| `mysql` | todo lo que escribe falla en cerrado | Nginx puede responder 503; no hay datos falsos |
| `scheduler` | no hay expiración/reconciliación/notificaciones automáticas | Catálogo, auth y compras existentes |

**Resultado registrado:** los E2E de S1, S3, S4, S6, S7 y S10.3 comprueban
stop/start y restauración. El dashboard muestra “No disponible” por tarjeta, no
un cero.

---

## 7. Cómo ejecutar una demostración segura

### 7.1 Antes de la demo

```powershell
# 1. Ver el estado del stack
docker compose ps

# 2. Preparar el entorno de pruebas (SOLO rifacloud_test)
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1 -SkipBuild

# 3. Verificación que NO escribe datos
powershell -ExecutionPolicy Bypass -File scripts/verify-demo-environment.ps1

# 4. Ver credenciales ficticias locales (no mostrarlas en pantalla)
Get-Content docs/testing/.local/test-users.json
```

`prepare-demo-environment.ps1`:

- aplica migraciones existentes **solo** a `rifacloud_test`;
- levanta el stack con `docker-compose.e2e.yml` y gateway simulado;
- genera credenciales ficticias en `docs/testing/.local/` (ignorado por Git);
- siembra rifas/órdenes demo de forma idempotente;
- no toca `rifacloud`.

`verify-demo-environment.ps1`:

- inicia sesión con los tres usuarios;
- comprueba catálogo, estados, historial, aislamiento entre compradores y
  métricas;
- **no crea órdenes, no migra y no modifica `rifacloud`**.

### 7.2 Durante la demo

Usar solo:

- `rifacloud_test`;
- gateway simulado (`PAYMENT_GATEWAY=fake`);
- usuarios demo;
- rifas `[DEMO]`;
- Chrome en ventana normal (no incógnito si se quiere conservar la cookie).

No usar datos personales reales, correos reales ni tarjetas reales.

### 7.3 Después de la demo

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1
```

- Elimina rifas `[DEMO]`, órdenes `DEMO-` y sesiones demo de `rifacloud_test`.
- Restaura el stack normal sobre `rifacloud` en `finally`.
- Conserva usuarios demo salvo `-PurgeUsers`.
- No borra el volumen.

### 7.4 Verificaciones que escriben o detienen servicios

| Script | Escribe | Detiene servicios | ¿Usar en vivo? |
|---|---|---|---|
| `verify-demo-environment.ps1` | No | No | Sí |
| `verify-auth-service-integration.ps1` | Sí (usuario temporal) | No | Con precaución |
| `verify-auth-isolation.ps1` | No | Sí (`auth-service`) | Solo si se quiere demostrar 503 |
| `verify-orders-service.ps1` | Sí (fixtures) | Sí (`orders-service`) | No en la demo principal |
| `verify-payments-service.ps1` | Sí (fixtures) | Sí (`payments-service`) | No en la demo principal |
| `verify-admin-dashboard.ps1` | Sí (fixtures) | Sí (servicios) | No en la demo principal |
| `verify-s6/verify-s7` | Sí (fixtures) | Posiblemente | No en la demo principal |

La demo principal debe usar `prepare` + navegador + `verify-demo-environment` +
`cleanup`. Los scripts de aislamiento se usan en una diapositiva o en un ensayo,
no en medio de la compra.

---

## 8. Capturas que debemos obtener

> No se incluyen imágenes en este repositorio. Estas son las capturas que el
> equipo debe tomar de una ejecución real, sin inventarlas.

| # | Captura | Qué debe mostrar | Para qué sirve |
|---:|---|---|---|
| 1 | `docker compose ps` | Ocho contenedores; MySQL sin puerto publicado | Infraestructura |
| 2 | Catálogo | Rifa disponible, próxima, cerrada y finalizada con resultado | Estados derivados |
| 3 | Registro | Casillas de privacidad/términos y enlace a documentos | S11 |
| 4 | Reserva | Números seleccionados y temporizador | Reserva |
| 5 | Orden pendiente | `PENDING_PAYMENT`, números `RESERVED` | Estado de negocio |
| 6 | Checkout simulado | Aviso “no se cobra dinero real” | Distinción fake/sandbox |
| 7 | Verificación | Orden `PAID`, números `SOLD` | Confirmación server-side |
| 8 | Mis compras | Dos usuarios con órdenes distintas | Aislamiento |
| 9 | Dashboard | 3 rifas publicadas, 2 pagadas, 5 vendidas, 1 incidencia | Métricas |
| 10 | Incidencias | `PAYMENT_REVIEW` visible para ADMIN | Operación |
| 11 | Resultado | Rifa `FINISHED` con ganador y evidencia | FR-014 |
| 12 | 503 controlado | `orders-service` detenido y dashboard “No disponible” | Resiliencia |

Consejos:

- Usar nombres ficticios (`Demo comprador uno`), no datos reales.
- No mostrar contraseñas ni tokens.
- No mostrar la consola con `APP_KEY`, `MERCADOPAGO_ACCESS_TOKEN` o
  `DB_PASSWORD`.
- Si se captura el dashboard, hacerlo antes de modificar datos demo para que
  coincida con 3/2/5/1.

---

## 9. Limitaciones que se deben declarar

### 9.1 Webhook real de Mercado Pago

- El adaptador implementa la firma oficial y la reconsulta del pago.
- **No se ha recibido una notificación firmada real** en este entorno.
- `MERCADOPAGO_WEBHOOK_SECRET` y `MERCADOPAGO_WEBHOOK_URL` están vacíos en el
  stack principal.
- Sin secreto, una notificación se rechaza y se responde 503 para reintento; no
  se acepta silenciosamente.
- `APP_URL=http://localhost` no es una URL pública HTTPS.
- La guía de sandbox está preparada, pero **no ejecutada** con credenciales
  reales.

### 9.2 SMTP / correo

- El mailer local es `log`; no se envían correos reales.
- Las notificaciones se encolan en `notifications` y se despachan con reintentos
  acotados.
- Una falla de correo no cambia una orden ni un pago.
- Mailtrap/SMTP es P2 y requiere credenciales.

### 9.3 AWS

- No hay EC2, EBS, S3, ALB, ACM, Route 53 ni Security Groups creados.
- Los scripts están preparados y el runbook existe, pero no se ejecutó un
  despliegue real.
- No hay HTTPS público ni backups en S3.
- No hay alta disponibilidad: una sola instancia es el objetivo, no HA.

### 9.4 Esquema compartido

- Una sola instancia y un solo esquema MySQL durante la transición.
- Usuarios y grants por servicio, pero no bases separadas.
- Claves foráneas entre dominios.
- `raffle_numbers` tiene creación en `raffles-service` y estado en
  `orders-service`.
- Sin outbox ni compensación automática; la saga se resuelve por replay.

### 9.5 S11

- La migración `user_consents` y el grant de `rifacloud_auth` fueron aplicados a
  `rifacloud` el 2026-11-12 con autorización explícita.
- Una prueba controlada de registro/limpieza verificó las tres filas de
  consentimiento.
- **Nota de vigencia:** el SDD y `docs/legal/` pueden reflejar el estado previo a
  esa autorización; la actualización de `AGENTS.md` del 2026-11-12 es la
  referencia más reciente.
- Los documentos legales siguen siendo borradores, no asesoría jurídica.
- No hay autorización de Coljuegos ni operación real.
- Siguen pendientes la identidad del responsable, el DPO, los contratos con
  encargados y la revisión legal (Q-21 a Q-25).

### 9.6 Otras

- Sin HA.
- Sin sorteo automático: resultado manual.
- Sin verificación de edad/identidad.
- Sin eliminación/corrección/revocatoria desde la interfaz.
- Sin RNBD ni contratos con encargados.
- Sin pruebas de carga formales.

---

## 10. Lo que no se puede afirmar

| Afirmación | Realidad |
|---|---|
| “El webhook real funciona.” | No verificado con notificación firmada |
| “Enviamos correos reales.” | Mailer `log`; no hay SMTP |
| “Está desplegado en AWS.” | Solo preparado; no hay recursos |
| “Cada servicio tiene su base.” | Una instancia/esquema; propiedad lógica y grants |
| “Cumplimos la ley de rifas.” | Borradores y consentimiento; no autorización |
| “Los tests se corrieron hoy.” | Resultados documentados; no re-ejecutados en esta guía |
| “Tenemos alta disponibilidad.” | Una sola instancia |
| “El pago se aprueba con la URL.” | Se verifica server-side |

---

## 11. Archivos que respaldan estas pruebas

| Tema | Archivo |
|---|---|
| Resultados y etapas | `docs/sdd/02-technical-design.md` |
| Plan de pruebas | `docs/sdd/04-implementation-plan.md` |
| Criterios | `docs/sdd/05-acceptance-criteria.md` |
| Fallos | `docs/sdd/07-resilience-and-failure-handling.md` |
| Pruebas manuales | `docs/testing/README.md`, `MANUAL_TEST_PLAN.md`, `MERCADOPAGO_SANDBOX.md` |
| Scripts E2E | `scripts/verify-*.ps1` |
| Concurrencia | `services/orders-service/tests/concurrent_create.php`, `services/payments-service/tests/webhook_concurrency.php` |
| Integración | `services/*/tests/*.php`, `src/tests/` |
| Frontend | `frontend/` (Vitest/Playwright) |
| S11 | `docs/legal/README.md`, `scripts/verify-auth-consent-evidence.php`, `AGENTS.md` |
| AWS | `deploy/aws/README.md`, `deploy/aws/verify-deployment.sh` |
