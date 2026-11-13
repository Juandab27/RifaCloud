# RifaCloud — Guía para compañeros

**Proyecto:** RifaCloud  
**Uso:** preparar la sustentación sin haber desarrollado todo el sistema  
**Enfoque:** entender, no memorizar

> **Promesa de esta guía:** al terminarla, cualquier integrante del equipo debe
> poder explicar qué hace RifaCloud, cómo se divide, por qué no se cayó al
> separar servicios, qué está probado y qué no. Sin inventar.

---

## 1. RifaCloud en una frase

> “RifaCloud es un prototipo académico que permite publicar rifas, reservar
> números sin duplicarlos, pagar en un entorno simulado o sandbox, consultar las
> compras y administrar resultados, usando cuatro servicios de negocio detrás de
> Nginx y una base MySQL compartida durante la transición.”

### 1.1 La analogía del edificio

Imagina un edificio con una recepción y cuatro oficinas:

- **Recepción (Nginx):** recibe a todo el mundo y dirige a la oficina correcta.
  No decide si tienes permiso para entrar a un área; solo enruta.
- **Oficina de identidad (auth-service):** registra, da credenciales y valida
  quién eres.
- **Oficina de catálogo (raffles-service):** muestra las rifas y administra sus
  datos.
- **Oficina de compras (orders-service):** aparta números y crea órdenes.
- **Oficina de pagos (payments-service):** habla con el proveedor y confirma el
  pago.
- **Archivo común (MySQL):** por ahora es un solo archivo compartido, con
  secciones asignadas a cada oficina.
- **Conserje de tareas (scheduler):** cada cierto tiempo revisa reservas
  vencidas, pagos pendientes y correos.

La analogía funciona, pero tiene un límite: en un edificio ideal cada oficina
tendría su propio archivo. Aquí el archivo es compartido **a propósito y de
forma transitoria**.

---

## 2. Glosario sin tecnicismos

| Término | Explicación simple | En RifaCloud |
|---|---|---|
| **Microservicio** | Un servicio pequeño con una responsabilidad de negocio clara, que se ejecuta en su propio proceso y puede desplegarse por separado | auth, raffles, orders, payments |
| **Contenedor** | Paquete aislado con una aplicación y sus dependencias; comparte el sistema operativo del host | Cada servicio corre en un contenedor Docker |
| **API Gateway** | Puerta de entrada que recibe las peticiones y las dirige al servicio correcto | Nginx cumple el papel de gateway de entrada; no es un API Gateway completo |
| **Transacción** | Varias operaciones que se confirman todas o se cancelan todas | Reserva de números: orden + números + ítems |
| **Lock (bloqueo)** | Un “turno” que impide que dos procesos toquen la misma fila al mismo tiempo | `SELECT ... FOR UPDATE` sobre rifa y números |
| **Idempotencia** | Repetir la misma operación no cambia el resultado | Webhook duplicado no vende dos veces |
| **Webhook** | El proveedor de pagos llama a nuestro servidor para avisar de un evento | `POST /api/webhooks/mercadopago` |
| **Saga** | Varias transacciones locales encadenadas, con compensación/reintento; no es una transacción única | Orden se confirma primero; pago se persiste después |
| **Reserva** | Apartar un número por tiempo limitado antes de pagar | 10 minutos por defecto; 60 en demo |
| **Sandbox** | Entorno de pruebas de un proveedor real, sin dinero real | Mercado Pago sandbox |
| **Migración** | Cambio versionado del esquema de base de datos | `2026_11_11_000011_create_user_consents_table.php` |
| **Outbox** | Patrón para guardar eventos y publicarlos después de forma confiable | **No implementado**; es deuda técnica |
| **HA (alta disponibilidad)** | Varias instancias para que si una falla, otra siga | **No implementado**; una sola EC2 |
| **Healthcheck** | Chequeo automático de si un contenedor está sano | `/health`, `mysqladmin ping`, heartbeat |
| **CSRF** | Protección contra peticiones falsas desde otro sitio | Cookie XSRF + token; 419 si falla |
| **Sesión** | Identidad temporal guardada en el servidor | Tabla `sessions`, cookie HttpOnly |
| **Pesos COP** | Guardar dinero como entero en pesos colombianos para evitar decimales | `price_per_number`, `total_amount` en enteros COP |

### 2.1 “Microservicio” no siempre significa “base separada”

En muchos libros, microservicio implica base propia. En RifaCloud, la evolución
fue incremental y la base se mantuvo compartida para no romper la reserva
atómica. Por eso la frase correcta es:

> “Son microservicios en proceso, despliegue y responsabilidad; la separación
> física de datos es la deuda técnica que sigue.”

No decir “son microservicios completos con bases independientes”, porque es
falso.

---

## 3. Mapa mental en seis capas

```text
1. Navegador (React + TypeScript)
2. Nginx (entrada, rutas, 503, SPA)
3. Servicios de negocio (auth, raffles, orders, payments)
4. Integraciones (Mercado Pago, scheduler, comandos internos)
5. Datos (MySQL compartido, grants, transacciones)
6. Operación (Docker, healthchecks, logs, backups, AWS preparado)
```

### 3.1 Recorrido de una compra en 30 segundos

1. El usuario se registra o inicia sesión → `auth-service`.
2. Ve el catálogo → `raffles-service`.
3. Elige números y reserva → `orders-service` crea la orden y bloquea los
   números.
4. Paga → `payments-service` crea el checkout.
5. El proveedor responde o llama por webhook → `payments-service` verifica y
   pide a `orders-service` que confirme.
6. `orders-service` marca la orden `PAID` y los números `SOLD`.
7. El usuario ve “Mis compras” → `orders-service`.
8. El admin ve métricas, órdenes e incidencias → tres servicios.
9. El admin cierra la rifa y publica el ganador → `raffles-service`.

---

## 4. Distribución para cuatro expositores

**Duración objetivo:** 9 minutos + preguntas.

| Expositor | Tema | Tiempo | Idea que debe quedar |
|---|---|---:|---|
| **1. Contexto y arquitectura** | Problema, monolito inicial, evolución a cuatro servicios, por qué | 2:15 | “Evolucionamos sin big-bang y sin romper reservas/pagos” |
| **2. Servicios y flujo de compra** | Responsabilidad de cada servicio, reserva, pago, webhook | 2:45 | “Cada dominio decide; la reserva es atómica y el pago se verifica server-side” |
| **3. Infraestructura y operación** | Nginx, Docker, MySQL, scheduler, aislamiento, AWS preparado | 2:15 | “Un solo punto de entrada, servicios internos, fallos controlados” |
| **4. Evidencias, S11 y límites** | Pruebas, concurrencia, consentimiento, legal, deuda técnica | 1:45 | “Sabemos qué está probado y qué no; no vendemos humo” |

### 4.1 Frases de transición entre expositores

- 1 → 2: “Ya sabemos por qué cuatro servicios; ahora veamos qué hace cada uno y
  cómo se compra.”
- 2 → 3: “Ese flujo funciona porque la infraestructura aísla procesos y
  responde 503 cuando algo falla.”
- 3 → 4: “La infraestructura está preparada; ahora veamos qué evidencia tenemos
  y qué límites aceptamos.”
- 4 → cierre: “Con eso, lo que defendemos no es que sea perfecto, sino que es
  honesto y verificable.”

### 4.2 Versión para tres expositores

| Expositor | Temas | Tiempo |
|---|---|---:|
| **1. Arquitectura y servicios** | Contexto, evolución, responsabilidades, por qué cuatro | 3:00 |
| **2. Flujo y operación** | Reserva, pago, webhook, Nginx, Docker, MySQL, scheduler | 3:30 |
| **3. Evidencias y defensa** | Pruebas, S11, legal, AWS, límites, preguntas | 2:30 |

Si son tres, el expositor 2 debe ensayar especialmente la transición reserva →
pago → expiración, porque es el bloque más denso.

### 4.3 Reparto de preguntas para el Q&A

| Tema probable | Quién responde |
|---|---|
| Arquitectura, microservicios, bases | Expositor 1 |
| Reservas, doble venta, pagos, webhooks | Expositor 2 |
| Docker, Nginx, MySQL, AWS, fallos | Expositor 3 |
| Pruebas, S11, legal, deuda técnica | Expositor 4 (o 3 en versión de tres) |

Regla: si no sabe, **no inventa**. Deriva: “Eso lo puede responder [nombre]” o
“Está documentado en [archivo]; lo revisamos y te confirmamos”.

---

## 5. Guion de exposición de 8 a 10 minutos

> **Cómo usarlo:** no leerlo palabra por palabra. Ensayar con las ideas fuerza y
> las transiciones. Cada bloque tiene el texto sugerido y lo que debe quedar.

### 0:00–0:45 — Gancho y problema

**Texto sugerido:**

> “Una rifa vendida por WhatsApp tiene un problema clásico: dos personas pueden
> creer que compraron el mismo número, o el pago puede quedar en el aire. Ese es
> el problema que atacamos. RifaCloud es un prototipo académico que permite
> publicar rifas, reservar números sin duplicarlos, pagar en un entorno
> simulado o sandbox y administrar todo desde un panel.”

**Idea fuerza:** no es “una página de rifas”; es un sistema con invariantes de
negocio.

### 0:45–2:00 — Arquitectura inicial y actual

**Texto sugerido:**

> “Empezamos como un monolito Laravel con React, Nginx y MySQL. Funcionaba,
> pero no demostraba evolución arquitectónica. Con ADR-001 pasamos a cuatro
> servicios de negocio: auth, raffles, orders y payments, detrás de Nginx. La
> migración fue incremental: primero catálogo, luego auth, administración,
> órdenes y pagos. Cada etapa se podía revertir por ruta sin migrar datos. Hoy
> los servicios tienen contenedor, healthcheck y usuario MySQL propio, pero
> comparten una instancia y un esquema. Eso es transitorio y está documentado.”

**Mostrar:** diagrama de arquitectura actual.

**No decir:** “tenemos cuatro bases”.

### 2:00–3:00 — Responsabilidad de cada servicio

**Texto sugerido:**

> “auth es dueño de usuarios, sesiones, roles y consentimiento. raffles es dueño
> del catálogo y la administración de rifas. orders es dueño de la reserva, la
> orden y el vencimiento. payments es dueño del checkout, la verificación, los
> webhooks y la reconciliación. El monolito sigue vivo como ruta de rollback y
> para tareas programadas. El scheduler no es un quinto servicio: es un proceso
> de tareas.”

**Truco:** señalar el diagrama mientras se nombra cada servicio.

### 3:00–4:20 — Reserva y doble venta

**Texto sugerido:**

> “La reserva es la parte más delicada. Todo ocurre en una transacción: se
> bloquea la rifa, se crea la orden, se bloquean los números en orden ascendente
> y se actualizan solo si están disponibles. Si otro proceso llegó primero, ve
> el número reservado y recibe 409. La base tiene además
> `UNIQUE(raffle_id, number)`. Lo probamos con dos procesos PHP reales: un 201 y
> un 409, una sola orden.”

**Si hay pizarra:** dibujar dos cajas “Proceso A” y “Proceso B” entrando al mismo
número; una espera, la otra gana.

### 4:20–5:45 — Pago y webhook

**Texto sugerido:**

> “El pago nunca se aprueba por la URL de retorno. El servidor consulta al
> proveedor. Si el pago es `UNKNOWN`, la orden queda en revisión y los números
> siguen reservados; no se liberan. Si el proveedor confirma, payments envía un
> comando firmado a orders, que decide la transición y marca los números como
> vendidos. Los webhooks son idempotentes: firma oficial, evento único, lock
> corto y comando que converge por estado. Un webhook duplicado no vende dos
> veces.”

**Mostrar:** matriz APPROVED / REJECTED / UNKNOWN / late approval.

### 5:45–6:45 — Infraestructura y fallos

**Texto sugerido:**

> “Nginx es el único punto de entrada. MySQL no publica puertos. Cada servicio
> tiene su healthcheck y se puede detener sin tumbar a los demás. Si orders se
> cae, el catálogo y el login siguen; Nginx devuelve un 503 JSON. Si payments se
> cae, el webhook devuelve 503 para que Mercado Pago reintente. Si MySQL se cae,
> todo falla en cerrado: no hay órdenes ni pagos falsos.”

### 6:45–7:45 — Pruebas y S11

**Texto sugerido:**

> “No pedimos que nos crean: hay evidencia documentada. Doble venta con dos
> procesos, webhook duplicado con dos procesos, 401/403/404, CSRF 419, grants
> que impiden escrituras cruzadas y stop/start por servicio. En S11 agregamos
> consentimiento informado versionado: privacidad y términos obligatorios,
> marketing opcional y separado. La migración y el grant se aplicaron a la base
> principal el 12 de noviembre y se verificaron las tres filas. Los documentos
> legales siguen siendo borradores, no asesoría jurídica.”

### 7:45–9:00 — Límites y cierre

**Texto sugerido:**

> “Lo que no tenemos: bases separadas, outbox, HA, webhook real verificado,
> SMTP real y AWS desplegado. Lo que sí tenemos: una arquitectura defendible,
> invariantes probadas y una lista honesta de deuda técnica. AWS está preparado
> con Compose de producción, bootstrap, backups cifrados y runbook, pero no se
> creó ningún recurso. Nuestra tesis no es que sea perfecto: es que evolucionó
> sin romper lo importante y que sabemos exactamente qué falta.”

**Cierre:** “¿Preguntas?”

### 5.1 Versión ultra-corta (si solo hay 5 minutos)

1. Problema: doble venta / pago incierto.
2. Solución: cuatro servicios + Nginx + MySQL compartido.
3. Invariante: transacción con locks; pago server-side; webhook idempotente.
4. Evidencia: dos procesos, 201/409, 503 controlado.
5. Límites: bases separadas, webhook real, AWS y HA pendientes.

---

## 6. Preguntas difíciles del profesor (30 respuestas fundamentadas)

> **Cómo usar:** leer la pregunta, intentar responder en 20 segundos y luego
> comparar. La columna “si insiste” da el detalle técnico.

### P1. “Si comparten la misma base de datos, ¿son realmente microservicios?”

**Respuesta corta:** “Son microservicios en proceso, despliegue y
responsabilidad; la separación física de datos es la deuda transitoria. ADR-001
define el objetivo con datos por dominio, pero la reserva atómica impide
separar la base sin un rediseño.”

**Si insiste:** explicar que cada servicio tiene contenedor, healthcheck,
usuario MySQL y límites de escritura; que la tabla `raffle_numbers` tiene
creación y estado delimitados; y que el objetivo incluye outbox y contratos.

### P2. “¿Por qué no separaron las bases desde el principio?”

**Respuesta corta:** “Porque la reserva es una transacción que bloquea la rifa
y los números. Si `raffles` y `raffle_numbers` estuvieran en bases distintas, esa
transacción dejaría de ser atómica. Primero había que estabilizar servicios y
diseñar una proyección versionada; por eso se difirió.”

**Si insiste:** citar `docs/sdd/02-technical-design.md` §6: en el objetivo la rifa se
proyecta en orders con versión y una reserva con versión vieja se rechaza y
reintenta. Eso no está implementado.

### P3. “¿Por qué cuatro servicios y no dos o cinco?”

**Respuesta corta:** “Cuatro es el corte que separa las capacidades con ciclos
de cambio y fallo distintos: identidad, catálogo, órdenes y pagos. Cinco
agregaría un servicio artificial; dos juntaría reservas con pagos, que es
justamente lo que queremos aislar.”

**Si insiste:** el scheduler no es un quinto servicio porque no tiene API de
negocio ni datos propios.

### P4. “¿Cómo evitan que dos personas compren el mismo número?”

**Respuesta corta:** “Con una transacción: bloqueo de la rifa, creación de la
orden, bloqueo de los números en orden ascendente y actualización solo si están
`AVAILABLE`. Si otro llegó primero, la segunda transacción ve `RESERVED` y
recibe 409. Además hay `UNIQUE(raffle_id, number)`.”

**Si insiste:** la prueba real usa dos procesos PHP y devuelve exactamente un
201 y un 409.

### P5. “¿Qué pasa si dos personas pagan al mismo tiempo por el mismo número?”

**Respuesta corta:** “No pueden reservar el mismo número: la segunda reserva
falla antes del pago. Si por una carrera excepcional el pago se aprueba y el
número ya no está disponible, la orden pasa a `REQUIRES_REFUND` y el pago a
`REFUND_REQUIRED`; el número no se asigna dos veces.”

**Si insiste:** `PaymentResultApplier` comprueba `numbersCanBeAssigned`; si no,
no vende y marca reembolso manual.

### P6. “¿Qué pasa si el pago se aprueba después de que venció la reserva?”

**Respuesta corta:** “Se trata como aprobación tardía: la orden pasa a
`REQUIRES_REFUND` y el pago a `REFUND_REQUIRED`. No se reasignan números y el
reembolso es manual.”

**Si insiste:** el estado `EXPIRED` o `REJECTED` se detecta como
`late_approval`; la evidencia queda en el pago.

### P7. “¿Qué pasa si el pago queda en `UNKNOWN`?”

**Respuesta corta:** “La orden pasa a `PAYMENT_REVIEW`, los números siguen
reservados y no se liberan. Un administrador o la reconciliación vuelve a
consultar al proveedor. Nunca se libera por incertidumbre.”

**Si insiste:** `ReservationExpiryService` bloquea la liberación si hay pago
`UNKNOWN`, `APPROVED` o `REFUND_REQUIRED`.

### P8. “¿Cómo evitan un doble cobro?”

**Respuesta corta:** “`UNIQUE(order_id)` en `payments` impide un segundo pago
lógico por orden. Si hay un pago `PENDING`, se reanuda; si es `UNKNOWN` o final,
se bloquea un checkout nuevo.”

**Si insiste:** el adaptador de Mercado Pago usa `MP-ORDER-{id}` como
`external_reference` e `X-Idempotency-Key`.

### P9. “¿Cómo garantizan que un webhook duplicado no venda dos veces?”

**Respuesta corta:** “Con tres capas: `UNIQUE(provider, provider_event_id)`,
un lock corto por evento y un comando a orders que converge por estado. Repetir
el mismo resultado es no-op; los números se mueven una sola vez.”

**Si insiste:** la prueba de concurrencia usa dos procesos reales y verifica una
fila de evento, un `SOLD` y un `PAID`.

### P10. “¿Confían en la URL de retorno de Mercado Pago para aprobar?”

**Respuesta corta:** “No. La URL solo indica que el usuario volvió. El frontend
dispara una verificación y el servidor consulta al proveedor. La aprobación
nunca se deduce del navegador.”

**Si insiste:** NFR-001 y AC-014/AC-047.

### P11. “¿Cómo se autentican los servicios entre sí?”

**Respuesta corta:** “Con HMAC-SHA256 sobre timestamp y hash del cuerpo, con un
secreto derivado de `APP_KEY` y una ventana de 60 segundos. Los endpoints
`/internal/` no se exponen por Nginx.”

**Si insiste:** el formato es
`HMAC(timestamp + "\n" + SHA256(body))`; hay secretos separados por contexto.

### P12. “¿Puede un cliente falsificar `X-User-Id` o `X-Role`?”

**Respuesta corta:** “No. Ningún servicio confía en cabeceras de identidad. Se
descifra la cookie de sesión con `APP_KEY`, se busca la sesión, se valida
expiración, rol y `password_hash_web`.”

**Si insiste:** hay pruebas negativas con cabeceras falsas (AC-043).

### P13. “¿Por qué comparten `APP_KEY` y la tabla de sesiones?”

**Respuesta corta:** “Para que una sesión emitida por auth-service sea aceptada
por el monolito y los demás servicios sin cambiar el frontend. Es una decisión
transitoria; un rediseño basado en tokens o introspección la reemplazaría.”

**Si insiste:** el costo es que un cambio de contraseña debe invalidar la sesión
y que los servicios no refrescan `last_activity`.

### P14. “¿Qué pasa si auth-service se cae?”

**Respuesta corta:** “Register/login/logout/me y CSRF devuelven 503 controlado.
El catálogo sigue público. Los servicios que ya tienen una sesión en MySQL
pueden validarla, pero el usuario no puede renovarla por `/me`.”

**Si insiste:** no se debe decir “todo sigue igual”: la experiencia de login se
degrada.

### P15. “¿Qué pasa si orders-service se cae?”

**Respuesta corta:** “Las rutas de órdenes devuelven 503; no se crea una orden
parcial. La expiración falla en cerrado y no libera nada. Catálogo y auth
siguen.”

**Si insiste:** el comando del scheduler termina con error y reintenta en la
siguiente ejecución.

### P16. “¿Qué pasa si payments-service se cae?”

**Respuesta corta:** “Checkout/verify/resolve y webhook devuelven 503. El 503
del webhook hace que Mercado Pago reintente; no se marca una orden como pagada
ni se vende un número. Catálogo y auth siguen.”

**Si insiste:** la reconciliación también falla en cerrado.

### P17. “¿Qué pasa si MySQL se cae?”

**Respuesta corta:** “Todo lo que escribe falla en cerrado: no hay orden,
pago ni número falso. Los healthchecks reportan unhealthy. El volumen queda
intacto.”

**Si insiste:** no hay “modo offline” ni caché de escritura; es una decisión
deliberada.

### P18. “¿Dónde está la transacción distribuida?”

**Respuesta corta:** “No existe. La reserva es una transacción local en
orders-service. La confirmación del pago es una saga: orders confirma primero y
payments persiste después; si el segundo paso falla, el replay converge.”

**Si insiste:** no hay 2PC; el orden de escritura y la idempotencia son la
garantía.

### P19. “¿Qué ventana de inconsistencia tiene la saga?”

**Respuesta corta:** “Entre el commit de la orden y la actualización de la fila
de pago. Si el proceso muere ahí, la fila de pago queda `PENDING`/`UNKNOWN`
mientras la orden ya está `PAID`. La expiración no libera una orden pagada y el
siguiente webhook/verify/reconcile converge.”

**Si insiste:** es una inconsistencia temporal observable, no una doble venta.

### P20. “¿Por qué el pago no libera números cuando es incierto?”

**Respuesta corta:** “Porque `UNKNOWN` puede ser una aprobación tardía. Liberar
el número podría vendérselo a otra persona y luego descubrir que el primer pago
sí se cobró. Preferimos revisión humana y reembolso antes que doble venta.”

**Si insiste:** AC-018, AC-019 y la regla de “no auto-release”.

### P21. “¿Está desplegado en AWS?”

**Respuesta corta:** “No. Está preparado: Compose de producción, plantilla de
entorno, bootstrap de base, backups cifrados, restore y runbook. No se creó
ningún recurso AWS, ni dominio, ni certificado, ni bucket.”

**Si insiste:** mostrar `deploy/aws/README.md` y la sección “Bloqueos actuales”.

### P22. “¿Mercado Pago está integrado y probado?”

**Respuesta corta:** “El adaptador sandbox y la firma de webhook están
implementados y probados con transporte falso. No se ejecutó con credenciales
reales ni se recibió una notificación firmada. La demo usa gateway simulado.”

**Si insiste:** en el estado documentado,
`MERCADOPAGO_WEBHOOK_SECRET` y `MERCADOPAGO_WEBHOOK_URL` no están configurados
con una URL pública verificada; sin secreto se responde 503, no se acepta.

### P23. “¿Cumplen la ley colombiana de rifas?”

**Respuesta corta:** “No podemos afirmar eso. Somos un prototipo académico, no
un operador autorizado. Los documentos de `docs/legal/` son borradores para
revisión jurídica. Una operación real exigiría operador autorizado, registro,
mecanismo de sorteo aprobado, derechos de explotación, plan de premios,
garantías y reportes.”

**Si insiste:** citar Ley 643 de 2001 y Decreto 1486 de 2024, y aclarar que no
somos asesoría jurídica.

### P24. “¿Qué implementaron de privacidad y consentimiento?”

**Respuesta corta:** “Registro con privacidad y términos obligatorios,
marketing opcional separado, versión de documento y evidencia en
`user_consents` con usuario, tipo, versión, fecha y origen. La migración y el
grant se aplicaron a `rifacloud` el 12 de noviembre y se verificaron tres filas.”

**Si insiste:** no se guarda IP ni user-agent; el `UNIQUE` evita duplicados; la
versión vencida devuelve 422.

### P25. “¿Por qué no hay alta disponibilidad?”

**Respuesta corta:** “Porque el alcance aprobado es una sola EC2 con Docker
Compose. HA y Kubernetes están fuera de alcance. La consecuencia es honesta: si
la instancia falla, hay caída total.”

**Si insiste:** el objetivo es demostrar arquitectura y invariantes, no operar
un servicio crítico 24/7.

### P26. “¿Cómo prueban concurrencia de verdad?”

**Respuesta corta:** “Con dos procesos PHP independientes y una barrera que los
libera casi al mismo tiempo, contra MySQL real en `rifacloud_test`. Para
reservas: un 201 y un 409. Para webhooks: una fila de evento, un `SOLD`, un
`PAID`.”

**Si insiste:** no son mocks; los tests se niegan a correr si la base no es
`rifacloud_test`.

### P27. “¿Nginx es un API Gateway?”

**Respuesta corta:** “Cumple el papel de gateway de entrada: enruta, aplica
timeouts y devuelve 503 controlado. No es un API Gateway completo: no
autentica, no hace rate limiting global ni transforma payloads. La autorización
ocurre en cada servicio.”

**Si insiste:** “enrutar no es autorizar”.

### P28. “¿Por qué tres servicios usan Apache y auth usa PHP-FPM?”

**Respuesta corta:** “Es una decisión de implementación: auth es una aplicación
Laravel completa sobre PHP-FPM; raffles, orders y payments son aplicaciones PHP
ligeras servidas por Apache. No cambia el contrato ni la arquitectura.”

**Si insiste:** no hay una razón de negocio; se puede unificar en el futuro sin
cambiar las rutas.

### P29. “¿Por qué no hay un worker de cola separado?”

**Respuesta corta:** “El estado actual usa la tabla `notifications` y comandos
programados (`notifications:queue` y `notifications:dispatch`) con mailer `log`.
No hay un worker dedicado ni un broker. La compra nunca depende del correo.”

**Si insiste:** el flujo de correo está desacoplado de la compra y es
reintentable; la entrega real por SMTP es P2 y no está validada. La compra nunca
depende del correo.

### P30. “¿Qué pasa si un admin publica un ganador equivocado?”

**Respuesta corta:** “El sistema exige rifa `CLOSED`, número `SOLD` dentro del
rango, evidencia y una sola publicación. No hay endpoint para editar un
resultado ya publicado; corregirlo requeriría una intervención administrativa
controlada, que no está implementada como funcionalidad.”

**Si insiste:** esto es una limitación de producto y una razón para revisar el
resultado antes de publicar; no se debe prometer un “undo” que no existe.

---

## 7. Preguntas que no se deben responder con inventos

| Si preguntan… | No responder… | Responder… |
|---|---|---|
| “¿Cuántos usuarios soporta?” | “Miles” sin prueba | “No hay prueba de carga formal; el alcance es una demo académica.” |
| “¿Es seguro para producción?” | “Sí, cumple todo” | “Tiene controles, pero faltan TLS real, SMTP, webhook verificado, revisión legal y HA.” |
| “¿Cada servicio tiene su base?” | “Sí” | “No; una instancia/esquema con usuarios y propiedad lógica.” |
| “¿Ya está en AWS?” | “Sí” | “Preparado, no desplegado.” |
| “¿Mercado Pago funciona?” | “Sí, probado” | “Adaptador sandbox implementado; falta prueba con credenciales y webhook real.” |
| “¿Cumple Coljuegos?” | “Sí” | “No; es un prototipo, no un operador autorizado.” |
| “¿Los tests pasaron hoy?” | “Sí” | “Hay resultados documentados; no se re-ejecutaron en esta preparación.” |

---

## 8. Ensayo: checklist de 30 minutos

### 8.1 Antes de ensayar

- [ ] Leer este documento y `ARQUITECTURA_EXPLICADA.md`.
- [ ] Abrir `FLUJOS_TECNICOS.md` y seguir los diagramas con el dedo.
- [ ] Tener a mano `docs/presentation/PRUEBAS_Y_EVIDENCIAS.md`.
- [ ] Elegir quién responde cada bloque de preguntas.
- [ ] Preparar una pizarra o diapositiva con el diagrama actual.

### 8.2 Ensayo cronometrado

- [ ] Expositor 1: 2:15.
- [ ] Expositor 2: 2:45.
- [ ] Expositor 3: 2:15.
- [ ] Expositor 4: 1:45.
- [ ] Cierre: 30 s.
- [ ] Q&A de prueba: 10 preguntas aleatorias de la sección 6.

### 8.3 Reglas de equipo

1. Nadie dice “eso no es mi parte”. Se deriva con nombre.
2. Nadie inventa cifras, rutas o estados.
3. Si hay una pregunta técnica muy profunda, se ofrece el archivo del
   repositorio.
4. Si el profesor ataca una debilidad, se acepta y se explica el plan.
5. No se habla mal del trabajo anterior: el monolito fue el baseline correcto
   para el MVP; ADR-001 lo evolucionó.

---

## 9. Resumen de una página para llevar impreso

```text
RifaCloud = prototipo académico de rifas.

Problema: doble venta + pago incierto + poca visibilidad administrativa.
Solución: Nginx + 4 servicios + MySQL compartido + scheduler.
Servicios:
  auth     → usuarios/sesiones/roles/consentimiento
  raffles  → catálogo/administración/resultados
  orders   → reservas/órdenes/historial/vencimiento
  payments → checkout/verify/webhooks/reconciliación

Invariantes:
  - Reserva atómica con locks + UNIQUE(raffle_id, number).
  - Pago verificado server-side; la URL no aprueba.
  - Webhook idempotente: firma + evento único + comando convergente.
  - UNKNOWN no libera números; late approval → refund manual.
  - Resultado manual: solo CLOSED, número SOLD, una vez, con evidencia.

Infra:
  - Solo Nginx publica 80; MySQL sin puerto público.
  - 8 contenedores; healthchecks; restart unless-stopped.
  - AWS preparado, no desplegado; sin HA.

Límites:
  - Una instancia/esquema MySQL (deuda transitoria).
  - Webhook real, SMTP real y AWS no verificados.
  - S11: consentimiento aplicado; textos legales son borradores.
```

---

## 10. Archivos para profundizar

| Tema | Archivo |
|---|---|
| Arquitectura | `docs/presentation/ARQUITECTURA_EXPLICADA.md` |
| Flujos | `docs/presentation/FLUJOS_TECNICOS.md` |
| Docker/infra | `docs/presentation/DOCKER_E_INFRAESTRUCTURA.md` |
| Pruebas | `docs/presentation/PRUEBAS_Y_EVIDENCIAS.md` |
| Demo | `docs/presentation/DEMO_PASO_A_PASO.md` |
| Decisión de arquitectura | `docs/sdd/08-adr-001-four-service-architecture.md` |
| Diseño técnico | `docs/sdd/02-technical-design.md` |
| Datos | `docs/sdd/03-data-model.md` |
| Requisitos | `docs/sdd/01-product-requirements.md` |
| Criterios | `docs/sdd/05-acceptance-criteria.md` |
| Legal | `docs/legal/README.md`, `docs/legal/05-alcance-regulatorio-rifas-colombia.md` |
