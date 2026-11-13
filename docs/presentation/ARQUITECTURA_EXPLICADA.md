# RifaCloud — Arquitectura explicada

**Proyecto:** RifaCloud  
**Uso:** material de estudio y sustentación universitaria  
**Fecha de preparación:** 12 de noviembre de 2026  
**Base:** código del repositorio y documentación SDD/ADR vigente al 2026-11-11

> **Cómo leer este documento**
>
> No es una nueva especificación ni una auditoría. Es una explicación educativa
> de lo que el repositorio realmente implementa. Cuando algo está preparado pero
> no desplegado, se dice explícitamente. Cuando algo es una deuda técnica
> aceptada, también.

---

## 1. Qué es RifaCloud y qué problema resuelve

RifaCloud es una plataforma web para gestionar rifas: catálogo público,
selección y reserva de números, pago, historial de compras, administración de
rifas, gestión de incidencias y publicación manual de resultados.

Es un **prototipo académico** de un proyecto de Cloud Computing. No es una
operación comercial autorizada, no procesa dinero real en la demostración y no
afirma tener autorización de Coljuegos.

### 1.1 Problema que ataca

Una rifa administrada con formularios, hojas de cálculo o mensajes tiene cuatro
problemas clásicos:

| Problema | Respuesta de RifaCloud |
|---|---|
| Dos personas creen que compraron el mismo número | Reserva atómica con transacción, locks de fila y restricción única |
| El pago queda “en el aire” | Verificación del pago en el servidor y estados explícitos (`PENDING`, `UNKNOWN`, `PAID`, `REJECTED`, `REQUIRES_REFUND`) |
| El administrador no sabe qué pasó | Panel con métricas, órdenes, incidencias y resultados |
| El catálogo se cae cuando falla el pago | Servicios separados y respuestas 503 controladas; el catálogo sigue funcionando |

### 1.2 Qué sí hace el sistema

- Registro e inicio de sesión con sesión de Laravel/Sanctum.
- Catálogo de rifas publicadas y finalizadas, con detalle y números.
- Reserva de varios números en una sola orden, con vencimiento configurable
  (10 minutos por defecto; 60 minutos en el entorno de demostración).
- Checkout con gateway simulado o adaptador de Mercado Pago Checkout Pro en
  sandbox.
- Verificación del pago en el servidor, webhooks idempotentes y reconciliación.
- “Mis compras” con aislamiento por propietario.
- Administración de rifas, órdenes, incidencias y publicación de resultados.
- Consentimiento informado versionado en el registro (S11).

### 1.3 Qué no hace o no está probado

- No opera rifas reales ni recibe pagos reales.
- No tiene alta disponibilidad: una sola instancia.
- No tiene bases de datos físicamente separadas por servicio.
- No tiene un webhook real de Mercado Pago verificado con una notificación
  firmada del proveedor.
- No envía correos reales: usa el mailer `log` en local.
- No tiene AWS desplegado: solo preparación reproducible.
- No tiene un proceso automático de sorteo: el ganador lo publica un
  administrador.

**Archivos de respaldo:** `README.md`, `docs/sdd/01-product-requirements.md`,
`docs/sdd/02-technical-design.md`, `docs/legal/README.md`.

---

## 2. Arquitectura inicial, arquitectura actual y arquitectura objetivo

### 2.1 Tres estados que no se deben confundir

| Estado | Qué es | ¿Está implementado? |
|---|---|---|
| **Inicial (v1.2)** | Monolito modular Laravel con React, Nginx, PHP-FPM, scheduler y MySQL; microservicios y Kubernetes fuera de alcance | Fue la decisión original del MVP; quedó superseded por ADR-001 |
| **Actual (transicional)** | Monolito + cuatro servicios de negocio extraídos + Nginx + MySQL compartido + scheduler | **Sí, es lo que existe en el repositorio** |
| **Objetivo** | Cuatro servicios independientes con datos por dominio, contratos autenticados, outbox/compensación y sin rutas de rollback | **Aprobado, pero no implementado por completo** |

La documentación es explícita: no se debe presentar el estado transicional como
el estado final, ni el objetivo como si ya estuviera hecho
(`docs/sdd/02-technical-design.md`, sección 1.1).

### 2.2 Arquitectura inicial (v1.2)

```text
Internet
   │
   ▼
Nginx
   ├── React SPA (build estático)
   └── /api/* ──► Laravel modular monolith (PHP-FPM)
                        │
                        ├── MySQL
                        ├── scheduler
                        └── cola / correo
```

Características:

- Un solo despliegue de aplicación.
- Módulos internos (`Auth`, `Raffles`, `Numbers`, `Orders`, `Payments`).
- Nginx, PHP-FPM, MySQL y scheduler en una sola instancia.
- Sin microservicios ni Kubernetes en el alcance original.

### 2.3 Arquitectura actual

```text
Internet
   │
   ▼
Nginx (:80; :443 solo con overlay TLS)
   │
   ├── /api/(register|login|logout|me|auth/health)
   │        └── auth-service :9000 (PHP-FPM)
   ├── /api/raffles*, /api/admin/raffles*
   │        └── raffles-service :80 (Apache)
   ├── /api/orders, /api/orders/{id}, /api/admin/orders*
   │        └── orders-service :80 (Apache)
   ├── /api/orders/{id}/payments*, /api/webhooks/mercadopago,
   │   /api/admin/payments/incidents*
   │        └── payments-service :80 (Apache)
   ├── /sanctum/csrf-cookie
   │        └── auth-service
   └── resto de /api/* y SPA
            └── app (monolito Laravel, PHP-FPM :9000)

scheduler (proceso Laravel schedule:work, sin API de negocio)
   ├── orders:expire-reservations ──► orders-service (comando firmado)
   ├── payments:reconcile ─────────► payments-service (comando firmado)
   └── notifications:queue / dispatch ──► tabla notifications

payments-service ──► orders-service (comando firmado payment-result)

MySQL 8.4 (una instancia, un esquema compartido; sin puerto público)
```

**Lo importante:** los cuatro servicios son procesos y contenedores
independientes, con su propio health check y su propio usuario MySQL de mínimo
privilegio. Pero **no** son cuatro bases físicas independientes.

### 2.4 Arquitectura objetivo (aprobada, no completa)

```text
Nginx
 ├── auth-service      ── datos de identidad/sesión
 ├── raffles-service   ── datos de rifas
 ├── orders-service    ── datos de órdenes/reservas
 └── payments-service  ── datos de pagos
        │
        └── contratos autenticados + eventos/outbox + compensación
```

En el objetivo:

- Cada dominio tiene su propia base o esquema.
- No hay claves foráneas entre dominios.
- Las transiciones entre dominios se hacen con comandos/eventos idempotentes.
- Se elimina la ruta de rollback al monolito.
- La reserva atómica se rediseña con una proyección versionada de la rifa.

Eso **no** está hecho. La división física de bases está diferida; el esquema
compartido es una decisión transitoria documentada.

**Archivos de respaldo:** `docs/sdd/08-adr-001-four-service-architecture.md`,
`docs/sdd/02-technical-design.md` (secciones 1, 3, 8 y 12),
`docker-compose.yml`, `docker/nginx/default.conf`.

---

## 3. Por qué se adoptaron cuatro microservicios

ADR-001 aprobó cuatro servicios de negocio detrás de Nginx. Las razones son
concretas, no moda tecnológica:

1. **Separar responsabilidades:** autenticación, catálogo, órdenes/reservas y
   pagos tienen ciclos de cambio distintos.
2. **Aislar fallos:** si el catálogo falla, el pago no debería caerse; si el
   pago falla, el catálogo debería seguir disponible.
3. **Ciclo de vida independiente:** cada servicio se puede detener, arrancar y
   verificar sin detener MySQL ni Nginx.
4. **Conservar contratos:** React y Mercado Pago siguen hablando con los mismos
   endpoints y envelopes JSON.
5. **Proteger invariantes:** la reserva atómica y la idempotencia del pago no se
   podían romper con una reescritura big-bang.
6. **Migración incremental y reversible:** cada etapa se puede volver a enrutar
   al monolito sin migración de datos.

### 3.1 Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Dejar el monolito para siempre | Rechazada para la evolución aprobada; el monolito queda como baseline de rollback |
| Reescritura big-bang a cuatro servicios | Riesgo alto para reservas y pagos |
| Extraer primero órdenes/pagos | Es la parte transaccional más delicada; se hizo después de auth y catálogo |
| Kubernetes / HA multi-instancia | Fuera de alcance; una sola EC2 |
| Separar bases de datos de inmediato | Rompería la reserva atómica sin un rediseño previo |

### 3.2 Por qué en ese orden

| Etapa | Servicio | Razón |
|---|---|---|
| S0 | Catálogo en `raffles-service` | Superficie de solo lectura, bajo riesgo |
| S1 | `auth-service` | Superficie transaccional pequeña; sesiones ya estaban en MySQL |
| S2 | Administración de rifas | Escrituras acotadas a `raffles` y generación de números |
| S3 | `orders-service` | Contiene la reserva atómica y la prevención de doble venta |
| S4 | `payments-service` | Depende de comandos de orden e idempotencia del proveedor |
| S5 | Separación de datos | Solo después de estabilizar S1–S4; dividida en “mínima” y “completa” |

**Archivos de respaldo:** `docs/sdd/08-adr-001-four-service-architecture.md`,
`docs/sdd/04-implementation-plan.md` (sección 10),
`docs/sdd/02-technical-design.md` (sección 8).

---

## 4. Responsabilidad de cada servicio

### 4.1 Matriz de responsabilidades

| Servicio | Es dueño de | No debe ser dueño de | Rutas principales |
|---|---|---|---|
| `auth-service` | Usuarios, credenciales, sesiones, roles, decisiones de autorización, evidencia de consentimiento (`user_consents`) | Rifas, números, órdenes, pagos | `POST /api/register`, `POST /api/login`, `POST /api/logout`, `GET /api/me`, `GET /sanctum/csrf-cookie`, `GET /api/auth/health` |
| `raffles-service` | Catálogo, administración de rifas, metadatos/configuración, generación inicial de números | Reservas, propiedad de números, órdenes, pagos | `GET /api/raffles*`, `GET/POST/PUT/DELETE /api/admin/raffles*`, `POST /api/admin/raffles/{id}/publish`, `.../close`, `.../result` |
| `orders-service` | Disponibilidad de números, reservas, órdenes, `order_items`, vencimiento, asignación atómica | Integración con el proveedor de pagos, contenido de la rifa | `POST /api/orders`, `GET /api/orders`, `GET /api/orders/{id}`, `GET /api/admin/orders*` |
| `payments-service` | Integración con Mercado Pago, checkout, verificación, webhooks, reconciliación, idempotencia | Creación de órdenes, asignación de números, contenido de la rifa | `POST /api/orders/{id}/payments`, `.../verify`, `POST /api/webhooks/mercadopago`, `POST /api/admin/orders/{id}/payments/{payment}/resolve`, `GET /api/admin/payments/incidents*` |
| `app` (monolito) | Rutas restantes, comandos de scheduler, código de rollback, notificaciones | No debe escribir estado de otro dominio fuera de sus rutas de rollback | Rutas no extraídas y `/api/*` restante |
| `scheduler` | Tareas programadas; no es un quinto microservicio ni tiene API de negocio | Datos de negocio propios | Comandos `orders:expire-reservations`, `payments:reconcile`, `notifications:*` |

### 4.2 El caso especial de `raffle_numbers`

`raffle_numbers` se comparte deliberadamente durante la transición:

- `raffles-service` **crea** el rango inicial de números al crear/editar una
  rifa y puede borrar filas `AVAILABLE` de un `DRAFT`.
- `orders-service` es el único que hace las transiciones de estado
  `RESERVED → SOLD` o `RESERVED → AVAILABLE` al reservar, vender, rechazar o
  expirar.
- `payments-service` **lee** el estado, pero no escribe la tabla; pide la
  transición a `orders-service` con un comando firmado.
- `raffles-service` lee conteos para el catálogo y la administración.

Esto no es una tabla “sin dueño”: es una tabla con **dos responsabilidades
delimitadas** durante la transición. Antes de partirla en otra base hace falta
definir el contrato de creación/entrega de números (handoff), tal como advierte
`docs/sdd/03-data-model.md` §1.1.

### 4.3 Lo que el monolito sigue haciendo

No es “código muerto”. Sigue siendo:

- el punto de rollback de todas las rutas extraídas;
- el dueño del scheduler y de los comandos de notificaciones;
- el receptor de rutas API no extraídas;
- el lugar donde viven clases equivalentes de reserva/pago para volver atrás.

Su presencia explica parte de la deuda técnica: hay lógica duplicada
deliberadamente para poder revertir sin migrar datos.

**Archivos de respaldo:** `docs/sdd/02-technical-design.md` (sección 4),
`docs/sdd/03-data-model.md`, `AGENTS.md` (tabla de microservicios),
`services/*/README.md`, `src/routes/api/*.php`.

---

## 5. Nginx: único punto de entrada

Nginx hace cuatro trabajos:

1. Sirve el build estático de React.
2. Enruta las rutas API al servicio propietario.
3. Devuelve un **503 JSON controlado** cuando un servicio no está disponible.
4. Bloquea `/internal/` para que los comandos entre servicios nunca sean
   públicos.

### 5.1 Enrutamiento real

| Ruta | Destino | Tipo |
|---|---|---|
| `/api/register`, `/api/login`, `/api/logout`, `/api/me`, `/api/auth/health` | `auth-service:9000` | FastCGI |
| `/sanctum/csrf-cookie` | `auth-service:9000` | FastCGI |
| `/api/raffles`, `/api/raffles/{id}`, `/api/raffles/{id}/numbers` | `raffles-service:80` | Proxy HTTP |
| `/api/admin/raffles*` (incluye `metrics` y `result`) | `raffles-service:80` | Proxy HTTP |
| `POST/GET /api/orders` | `orders-service:80` | Proxy HTTP |
| `/api/orders/{id}` | `orders-service:80` | Proxy HTTP |
| `/api/admin/orders`, `/api/admin/orders/metrics` | `orders-service:80` | Proxy HTTP |
| `/api/orders/{id}/payments`, `.../verify` | `payments-service:80` | Proxy HTTP |
| `/api/webhooks/mercadopago` | `payments-service:80` | Proxy HTTP |
| `/api/admin/orders/{id}/payments/{payment}/resolve` | `payments-service:80` | Proxy HTTP |
| `/api/admin/payments/incidents`, `.../metrics` | `payments-service:80` | Proxy HTTP |
| `/api/*` restante | `app:9000` | FastCGI |
| `/internal/*` | `404` | Bloqueo explícito |
| `/` | SPA React | `try_files` |

### 5.2 Degradación controlada

Cada grupo de rutas tiene un `error_page` que convierte un fallo de conexión
(`502/503/504`) en un JSON 503 con un mensaje distinto:

- “Catálogo de rifas temporalmente no disponible.”
- “Servicio de autenticación temporalmente no disponible.”
- “Servicio de órdenes temporalmente no disponible.”
- “Servicio de pagos temporalmente no disponible.”

Eso permite que el frontend muestre un estado de indisponibilidad y no invente
un cero, un pago aprobado o una orden creada.

### 5.3 Nginx no es un API Gateway completo

En RifaCloud, Nginx cumple el papel de **gateway de entrada**: enruta, aplica
timeouts y devuelve 503 controlados. No implementa por sí solo:

- autenticación de usuarios;
- rate limiting global;
- transformación de payloads;
- descubrimiento de servicios;
- políticas de API.

La autorización real ocurre en cada servicio. Esto es intencional: **enrutar no
es autorizar**.

**Archivos de respaldo:** `docker/nginx/default.conf`,
`docker/nginx/Dockerfile`, `docs/sdd/02-technical-design.md` (sección 5).

---

## 6. Docker Compose: una sola red, varios procesos

El stack se levanta con `docker-compose.yml` y overlays opcionales. Los
contenedores son:

| Contenedor | Rol | Puerto publicado |
|---|---|---|
| `nginx` | Gateway + SPA | `80` (`443` solo con overlay TLS) |
| `app` | Monolito Laravel/PHP-FPM | Ninguno |
| `scheduler` | `schedule:work` con supervisor | Ninguno |
| `auth-service` | Autenticación/sesiones | Ninguno |
| `raffles-service` | Catálogo/administración | Ninguno |
| `orders-service` | Órdenes/reservas/vencimiento | Ninguno |
| `payments-service` | Pagos/webhooks/reconciliación | Ninguno |
| `mysql` | Base de datos | Ninguno |

Detalle completo de imágenes, healthchecks, volúmenes y red:
[`DOCKER_E_INFRAESTRUCTURA.md`](DOCKER_E_INFRAESTRUCTURA.md).

Puntos arquitectónicos:

- Todos comparten la red por defecto de Compose (`rifacloud_default`).
- Los servicios se resuelven por nombre DNS (`mysql`, `orders-service`, etc.).
- `app`, `scheduler` y `nginx` montan `./src`; los servicios llevan su código
  dentro de la imagen.
- MySQL usa el volumen nombrado `mysql_data`.
- `restart: unless-stopped` en todos los servicios.
- `payments-service` no depende de que `orders-service` esté healthy: el
  checkout puede seguir disponible mientras la verificación de la orden falla
  en cerrado.

**Archivos de respaldo:** `docker-compose.yml`,
`docker-compose.prod.yml`, `docker-compose.prod-tls.yml`,
`docker-compose.e2e.yml`.

---

## 7. MySQL: una instancia, cuatro propietarios lógicos

### 7.1 Lo que es verdad

- Hay **una instancia MySQL 8.4**.
- Hay **un esquema compartido** (`rifacloud`) en la transición.
- Hay **cuatro usuarios MySQL de mínimo privilegio**, uno por servicio.
- Cada tabla tiene un servicio propietario lógico.
- Las escrituras cruzan dominios solo por comandos aprobados o por las rutas de
  rollback del monolito.
- MySQL **no publica puertos** al host: solo es accesible desde la red Docker.
- Los valores monetarios se guardan en **pesos colombianos enteros (COP)**.

### 7.2 Usuarios y permisos

| Servicio | Tablas propias (SELECT/INSERT/UPDATE/DELETE) | Lectura | Permiso especial de lock |
|---|---|---|---|
| `auth-service` | `users`, `sessions`, `cache`, `cache_locks` | — | — |
| `raffles-service` | `raffles`, `raffle_numbers` | `users`, `sessions` | — |
| `orders-service` | `orders`, `order_items`, `raffle_numbers` | `raffles`, `payments`, `users`, `sessions` | `UPDATE(updated_at)` en `raffles` y `payments` |
| `payments-service` | `payments`, `payment_webhook_events` | `orders`, `order_items`, `raffle_numbers`, `raffles`, `users`, `sessions` | `UPDATE(updated_at)` en `orders` |

El permiso de `UPDATE` sobre la columna inerte `updated_at` existe porque
**MySQL 8.4 exige privilegio de UPDATE para `SELECT ... FOR UPDATE`**. No habilita
cambiar columnas de negocio; los tests negativos comprueban que un servicio no
puede escribir el estado de otro.

### 7.3 Restricciones que sostienen el negocio

- `UNIQUE(raffle_id, number)` en `raffle_numbers`.
- `UNIQUE(order_id)` en `payments`.
- `UNIQUE(provider, external_id)` en `payments`.
- `UNIQUE(provider, provider_event_id)` en `payment_webhook_events`.
- `UNIQUE(user_id, type, document_version)` en `user_consents`.
- `UNIQUE(type, order_id)` en `notifications`.
- Claves foráneas para integridad mientras se comparte el esquema.

### 7.4 Lo que no es verdad

No se debe afirmar:

- “Cada microservicio tiene su propia base de datos.”
- “La separación de datos está completa.”
- “El esquema compartido es el diseño final.”

La separación completa (bases por dominio, outbox, compensación y eliminación
del rollback) está diferida. Lo aplicado es la **S5 mínima**: migraciones
aditivas, usuarios por servicio y grants verificados.

**Archivos de respaldo:** `docs/sdd/03-data-model.md`,
`docs/sdd/02-technical-design.md` (sección 9),
`scripts/mysql-s5-grants.sql`, `scripts/mysql-s91-grants.sql`,
`scripts/mysql-s11-grants.sql`, `docker/mysql/init/01-test-database.sql`.

---

## 8. Scheduler: proceso de tareas, no quinto microservicio

El scheduler es un contenedor que ejecuta `php artisan schedule:work` bajo un
pequeño supervisor (`docker/scheduler/entrypoint.sh`). No expone API de negocio.

### 8.1 Tareas programadas

| Tarea | Frecuencia | Qué hace | Protección |
|---|---|---|---|
| `orders:expire-reservations` | Cada minuto | Llama al endpoint interno firmado de `orders-service` | `withoutOverlapping(5)` + `GET_LOCK` en el servicio |
| `payments:reconcile --limit=50` | Cada 5 minutos | Llama al endpoint interno firmado de `payments-service` | `withoutOverlapping(10)` + idempotencia |
| `notifications:queue --limit=100` | Cada minuto | Crea filas idempotentes en `notifications` | `UNIQUE(type, order_id)` |
| `notifications:dispatch --limit=50` | Cada minuto | Envía correos con reintentos acotados | 5 intentos; nunca cambia orden/pago |
| Heartbeat | Cada minuto | Escribe `/tmp/rifacloud-scheduler-heartbeat` | Healthcheck falla si tiene más de 3 minutos |

### 8.2 Comportamiento ante fallos

- Si `orders-service` no responde, el comando del monolito termina con error y
  **no libera nada localmente**. La siguiente ejecución reintenta.
- Si `payments-service` no responde, la reconciliación falla en cerrado.
- Si el scheduler muere, no hay corrupción: solo se dejan de ejecutar tareas
  automáticas. El sistema sigue operando para catálogo, login y compras
  existentes.
- El supervisor reinicia el hijo si sale inesperadamente y permite un apagado
  limpio con `SIGTERM`.

**Archivos de respaldo:** `src/routes/console.php`,
`docker/scheduler/entrypoint.sh`, `src/app/Console/Commands/*.php`,
`docker-compose.yml`.

---

## 9. Comunicación, autenticación compartida y límites de independencia

### 9.1 Tipos de comunicación

| Comunicación | Mecanismo real |
|---|---|
| Navegador → Nginx | HTTP/HTTPS, cookie de sesión `rifacloud-session` |
| Nginx → `auth-service` | FastCGI a `:9000` |
| Nginx → `raffles/orders/payments` | Proxy HTTP a `:80` |
| Nginx → monolito | FastCGI a `app:9000` |
| `payments-service` → `orders-service` | `POST /internal/orders/payment-result` firmado con HMAC |
| Scheduler → `orders-service` | `POST /internal/orders/expire-reservations` firmado con HMAC |
| Scheduler → `payments-service` | `POST /internal/payments/reconcile` firmado con HMAC |
| Todos → MySQL | PDO, red interna, usuario propio |
| Proveedor → `payments-service` | Webhook HTTPS con firma de Mercado Pago |

No hay broker de mensajes, no hay outbox implementado y no hay transacción
distribuida. La integración entre pagos y órdenes es una **saga** con comando
idempotente y compensación por replay.

### 9.2 Autenticación compartida (transición)

Los cuatro servicios y el monolito comparten:

- `APP_KEY`;
- nombre de cookie `rifacloud-session`;
- tabla `sessions` en MySQL;
- contrato de `password_hash_web`.

Cuando un servicio recibe una petición autenticada:

1. Descifra la cookie con `APP_KEY` y valida MAC/prefijo.
2. Busca la sesión en `sessions` y comprueba expiración.
3. Verifica que el usuario siga existiendo y que `password_hash_web` coincida
   (un cambio de contraseña invalida la sesión).
4. Para rutas admin, exige `users.role = 'ADMIN'`.
5. Valida CSRF en peticiones stateful (419 si falla).

**Nunca** se confía en cabeceras como `X-User-Id` o `X-Role` enviadas por el
cliente. Los tests negativos lo comprueban.

### 9.3 Comandos internos firmados

Formato implementado:

```text
X-RifaCloud-Timestamp: <unix seconds>
X-RifaCloud-Signature: HMAC-SHA256(
    timestamp + "\n" + SHA256(raw_body),
    secret
)
```

- El secreto se deriva de `APP_KEY` con un contexto distinto para cada comando:
  - `rifacloud-orders-internal`
  - `rifacloud-payments-internal`
  - `rifacloud-payments-reconcile`
- Se rechaza una petición fuera de una ventana de **60 segundos**.
- No hay nonce separado en estos comandos; la ventana temporal y la idempotencia
  del negocio son la protección implementada.
- `/internal/` devuelve 404 en Nginx; los endpoints solo existen en la red
  Docker.

### 9.4 Límites reales de independencia

| Aspecto | ¿Independiente? | Matiz |
|---|---|---|
| Proceso/contenedor | Sí | Cada servicio tiene su propio contenedor |
| Health check | Sí | Cada uno reporta su estado |
| Reinicio/parada | Sí | `docker compose stop <servicio>` no detiene MySQL ni Nginx |
| Imagen/despliegue | Sí, por servicio | Se puede reconstruir uno solo |
| Base de datos | No | Una instancia y un esquema compartido |
| Sesión | No | `APP_KEY`, cookie y tabla `sessions` compartidos |
| Transacción de reserva | No | Una sola transacción MySQL dentro de `orders-service` |
| Autorización | Parcial | Cada servicio valida la sesión, pero contra la misma tabla |
| Rollback | Sí, por ruta | Nginx vuelve a enrutar al monolito sin migrar datos |

**Frase defendible:** “Son microservicios en proceso, despliegue y
responsabilidad; la separación de datos y de sesión es la deuda técnica
transitoria que ADR-001 documenta y que S5 completo/S8 deben cerrar.”

**Archivos de respaldo:** `docs/sdd/02-technical-design.md` (secciones 5 y 6),
`services/*/src/Auth/*`, `services/*/src/*/InternalRequestVerifier.php`,
`services/payments-service/src/Payments/OrdersClient.php`,
`src/app/Modules/Orders/Clients/InternalOrdersClient.php`.

---

## 10. Pagos: simulado, sandbox y producción

| Modo | Configuración | Qué ocurre | Estado real |
|---|---|---|---|
| **Simulado** | `PAYMENT_GATEWAY=fake` | Checkout interno `SIM-COP-...`, sin dinero; acepta webhooks simulados | Es el modo de la demo y de las pruebas P1 |
| **Sandbox** | `PAYMENT_GATEWAY=mercadopago`, `MERCADOPAGO_SANDBOX=true` | Adaptador Checkout Pro, preferencia sandbox, verificación server-side y firma de webhook | Implementado y probado con transporte falso; **no se ejecutó con credenciales reales** |
| **Producción** | `PAYMENT_GATEWAY=mercadopago`, sandbox false | Cobro real | **No autorizado ni desplegado**; el proyecto no opera dinero real |

El modo `fake` se rechaza en `APP_ENV=production`. La demo debe explicar que el
botón “Verificar pago” usa el gateway simulado y que la aprobación la decide el
servidor, no la URL.

**Archivos de respaldo:** `services/payments-service/src/Payments/FakePaymentGateway.php`,
`MercadoPagoGateway.php`, `docs/testing/MERCADOPAGO_SANDBOX.md`,
`README.md` (notas de diseño).

---

## 11. S11: privacidad, consentimiento y regulación

### 11.1 Qué se implementó

- Páginas públicas `/privacidad`, `/terminos` y `/alcance-regulatorio`.
- Enlaces legales en el pie de página.
- Aclaración contextual en el checkout: gateway simulado vs. Mercado Pago
  sandbox, prototipo académico, sin cobro real.
- Registro con dos aceptaciones obligatorias:
  - Política de Tratamiento de Datos Personales;
  - Términos y Condiciones del prototipo.
- Consentimiento promocional **opcional y separado**.
- Versión de documento `2026-11-11` enviada por el frontend y validada por el
  backend.
- Evidencia en `user_consents`: usuario, tipo (`PRIVACY`, `TERMS`, `MARKETING`),
  versión, decisión, fecha y origen. Sin IP ni user-agent.
- Restricción `UNIQUE(user_id, type, document_version)` e inserción idempotente.

### 11.2 Estado real de la migración

La migración aditiva
`2026_11_11_000011_create_user_consents_table.php`:

- está implementada;
- fue verificada primero en `rifacloud_test`;
- **fue aplicada a `rifacloud` el 2026-11-12 con autorización explícita**;
- el grant de `user_consents` para `rifacloud_auth` también fue aplicado;
- una prueba controlada de registro y limpieza verificó las tres filas de
  consentimiento (`PRIVACY`, `TERMS`, `MARKETING`).

El código conserva un *fallback*: si la tabla no existiera, el registro seguiría
funcionando y se registraría un warning. Pero en el estado actual de la base
principal la tabla **sí existe** y la evidencia se persiste. Ese *fallback* no es
una autorización para inventar cumplimiento.

> **Nota de vigencia:** el SDD y `docs/legal/` pueden describir el estado
> anterior a la autorización. La actualización de `AGENTS.md` del 2026-11-12 es
> la referencia más reciente para el estado de la migración y del grant.

### 11.3 Límites jurídicos que se deben decir en voz alta

Los documentos de `docs/legal/` son **borradores académicos**, no asesoría
jurídica. No acreditan cumplimiento ni autorizan rifas reales. RifaCloud:

- no es operador autorizado;
- no tiene contrato con un operador;
- no ha registrado rifas ante una autoridad;
- no celebra sorteos con efectos jurídicos;
- no afirma autorización de Coljuegos.

Una operación real exigiría, como mínimo, operador autorizado, registro de cada
rifa, mecanismo de sorteo aprobado (lotería autorizada o GNA validado), derechos
de explotación del 14 %, plan de premios, garantías, reportes, control de edad y
cumplimiento de protección al consumidor y datos.

**Archivos de respaldo:** `docs/legal/README.md`,
`docs/legal/05-alcance-regulatorio-rifas-colombia.md`,
`docs/legal/06-derechos-titular-y-limitaciones.md`,
`services/auth-service/app/Services/ConsentRecorder.php`,
`services/auth-service/app/Http/Requests/RegisterRequest.php`,
`frontend/src/lib/legal.ts`, `frontend/src/pages/PrivacyPage.tsx`,
`frontend/src/pages/TermsPage.tsx`,
`frontend/src/pages/RegulatoryScopePage.tsx`.

---

## 12. Ventajas, desventajas y deuda técnica aceptada

### 12.1 Ventajas reales

- **Aislamiento de fallos:** detener `orders-service` no tumba el catálogo ni
  el login.
- **Responsabilidad clara:** cada servicio sabe qué tablas escribe.
- **Despliegue incremental:** se puede reconstruir y reiniciar un servicio.
- **Rollback por ruta:** volver al monolito no requiere migración de datos.
- **Contratos estables:** React sigue consumiendo los mismos envelopes.
- **Defensa concreta:** hay tests de doble venta, webhook duplicado, 401/403/404,
  CSRF y aislamiento.

### 12.2 Desventajas reales

- **Más contenedores:** ocho procesos en una sola instancia; más superficie
  operativa.
- **Esquema compartido:** acoplamiento a columnas y claves foráneas.
- **Sesión compartida:** `APP_KEY`, cookie y `sessions` impiden independencia
  total.
- **Lógica duplicada:** el monolito conserva rutas y clases de rollback.
- **Saga, no transacción distribuida:** hay una ventana en la que la orden ya
  cambió y la fila de pago todavía no.
- **Sin HA:** una sola EC2; si falla la instancia, hay caída total.
- **Sin broker/outbox:** la integración es HTTP firmado y replay idempotente.

### 12.3 Deuda técnica aceptada (y por qué)

| Deuda | Por qué se aceptó | Qué la cierra |
|---|---|---|
| Esquema MySQL compartido | Permite reserva atómica y migración incremental sin big-bang | S5 completo / S8: bases por dominio |
| `APP_KEY` y `sessions` compartidos | Mantiene compatibilidad con el monolito y las rutas existentes | Rediseño de auth basado en tokens/contratos |
| Rutas de rollback en el monolito | Permite revertir sin migrar datos | Eliminarlas cuando la demo se acepte |
| `raffle_numbers` con creación y estado en servicios distintos | Evita romper la transacción de reserva | Contrato de handoff y partición de datos |
| Sin outbox/compensación automática | Complejidad alta para el plazo; replay idempotente cubre el caso | Outbox + reconciliación |
| Webhook real no verificado | Faltan secreto y URL pública | Prueba con notificación sandbox firmada |
| SMTP real no configurado | El correo no debe bloquear la compra | Mailtrap/SMTP en P2 |
| AWS solo preparado | Faltan credenciales, dominio, TLS y presupuesto | Despliegue P3 autorizado |
| Revisión jurídica de los textos S11 | Faltan identidad del responsable, DPO, contratos y aprobación legal | Revisión humana y cierre de Q-21 a Q-25 |
| Sin HA | Fuera de alcance; una sola EC2 | Multi-instancia/K8s, no aprobado |

**Frase defendible:** “La deuda no es accidental: está identificada, tiene
dueño y tiene una etapa de cierre. Lo que no hacemos es venderla como si ya
estuviera resuelta.”

---

## 13. Trazabilidad mínima

| Tema | Requisito | Criterio |
|---|---|---|
| Registro/login | FR-001, FR-002 | AC-001 |
| Compra autenticada | FR-003 | AC-002 |
| Catálogo | FR-004, FR-005 | AC-006, AC-007, AC-008 |
| Reserva 10 min | FR-007, NFR-004 | AC-009, AC-010, AC-012 |
| Doble venta | NFR-002 | AC-011 |
| Pago server-side | FR-008, NFR-001 | AC-013, AC-014, AC-015 |
| Webhook idempotente | NFR-003 | AC-017 |
| Pago incierto | FR-015 | AC-018, AC-019, AC-020 |
| Historial | FR-009 | AC-034, AC-048 |
| Resultado manual | FR-014 | AC-023, AC-024, AC-025, AC-049 |
| Dashboard admin | FR-013 | AC-026, AC-050 |
| Aislamiento de servicios | NFR-016, NFR-019 | AC-038 a AC-042 |
| Autorización de servicios | NFR-017 | AC-043 |
| Propiedad de datos | NFR-018 | AC-044 |
| Continuidad de sesión | NFR-019 | AC-046, AC-047 |
| S11 | FR-016, NFR-020 | AC-S11-01 a AC-S11-08 |

**Archivos de respaldo:** `docs/sdd/01-product-requirements.md`,
`docs/sdd/05-acceptance-criteria.md`.

---

## 14. Archivos que respaldan esta explicación

| Tema | Archivo |
|---|---|
| Contexto y stack | `README.md` |
| Decisión de cuatro servicios | `docs/sdd/08-adr-001-four-service-architecture.md` |
| Diseño técnico y estados | `docs/sdd/02-technical-design.md` |
| Modelo y propiedad de datos | `docs/sdd/03-data-model.md` |
| Requisitos | `docs/sdd/01-product-requirements.md` |
| Criterios de aceptación | `docs/sdd/05-acceptance-criteria.md` |
| Plan de migración | `docs/sdd/04-implementation-plan.md` |
| Fallos y resiliencia | `docs/sdd/07-resilience-and-failure-handling.md` |
| Rutas y degradación | `docker/nginx/default.conf` |
| Contenedores | `docker-compose.yml`, `docker-compose.prod.yml` |
| Servicios | `services/auth-service/`, `services/raffles-service/`, `services/orders-service/`, `services/payments-service/` |
| Monolito y rollback | `src/app/Modules/`, `src/routes/api/` |
| Scheduler | `src/routes/console.php`, `docker/scheduler/entrypoint.sh` |
| S11 | `docs/legal/`, `services/auth-service/app/Services/ConsentRecorder.php` |
| AWS preparado | `deploy/aws/README.md`, `deploy/aws/*.sh`, `deploy/aws/env.production.example` |

---

## 15. Explicación de 60 segundos para abrir la sustentación

> “RifaCloud es un prototipo académico de rifas. Empezó como un monolito Laravel
> con React, Nginx y MySQL. Para demostrar evolución arquitectónica lo llevamos
> a cuatro servicios de negocio —auth, raffles, orders y payments— detrás de
> Nginx, con contenedores y usuarios MySQL propios. La parte más delicada es la
> reserva: una transacción con locks impide que dos personas compren el mismo
> número. La segunda es el pago: el servidor consulta al proveedor, los webhooks
> son idempotentes y un pago incierto no libera números. Hoy los servicios
> comparten una instancia y un esquema MySQL; esa es una decisión transitoria
> documentada, no el diseño final. AWS está preparado pero no desplegado, y el
> webhook real de Mercado Pago todavía no se ha validado con una notificación
> firmada. La demo usa el gateway simulado y la base `rifacloud_test`.”

---

## 16. Frases que conviene evitar

| No decir | Decir |
|---|---|
| “Tenemos cuatro bases de datos.” | “Tenemos cuatro servicios con usuarios y propiedad lógica; una sola instancia/esquema durante la transición.” |
| “Los microservicios están completamente independientes.” | “Son independientes en proceso, despliegue y fallo; comparten sesión y esquema.” |
| “Mercado Pago está integrado y probado.” | “El adaptador sandbox está implementado; la prueba con credenciales y webhook real está pendiente.” |
| “Está desplegado en AWS.” | “El despliegue en AWS está preparado de forma reproducible; no se creó ningún recurso.” |
| “Cumplimos la ley de rifas.” | “Hay borradores y controles de consentimiento; no somos operador autorizado ni asesoría jurídica.” |
| “El pago se confirma con la URL de retorno.” | “La URL solo indica el retorno; el servidor verifica el pago con el proveedor.” |
| “Nginx autentica.” | “Nginx enruta; cada servicio valida sesión, rol y CSRF.” |
