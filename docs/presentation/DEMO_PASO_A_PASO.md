# RifaCloud — Demo paso a paso

**Proyecto:** RifaCloud  
**Uso:** guion operativo para una demostración segura en la sustentación  
**Base:** scripts de `scripts/`, overlay `docker-compose.e2e.yml` y datos demo de `rifacloud_test`

> **Regla número uno**
>
> La demo se ejecuta sobre `rifacloud_test`, con gateway simulado y usuarios
> ficticios. **Nunca** sobre la base principal `rifacloud`, con datos reales, con
> tarjetas reales o con correos reales.
>
> Los comandos de este documento están escritos para **no ejecutarse ahora** en
> la preparación de la guía. Se ejecutan el día de la demo, en un entorno
> controlado.

---

## 1. Qué se va a demostrar

| # | Momento | Qué prueba | Servicio visible |
|---:|---|---|---|
| 1 | Registro | Consentimiento obligatorio y versión legal | `auth-service` |
| 2 | Catálogo | Estados de rifa y resultado público | `raffles-service` |
| 3 | Reserva | Números reservados y temporizador | `orders-service` |
| 4 | Pago simulado | Verificación server-side y números `SOLD` | `payments-service` + `orders-service` |
| 5 | Mis compras | Aislamiento por propietario | `orders-service` |
| 6 | Panel admin | Métricas, órdenes e incidencias | 3 servicios |
| 7 | Resultado | Publicación manual con evidencia | `raffles-service` |
| 8 | Fallo controlado | 503 JSON y “No disponible” | Nginx + dashboard |

**Duración sugerida:** 6–8 minutos de demo, dentro de una exposición de 8–10.

---

## 2. Preparación (30 minutos antes)

### 2.1 Requisitos

- Docker Desktop iniciado y con el daemon corriendo.
- Puerto 80 libre.
- Repositorio en la raíz `RifaCloud`.
- Imágenes ya construidas (si no, se construyen en el primer `prepare`).
- Navegador Chrome o Edge.
- Dos perfiles/ventanas para comprador 1 y comprador 2 (una normal y una
  incógnito, o dos perfiles distintos).
- Credenciales demo a mano, **sin mostrarlas en pantalla**.

### 2.2 Verificar el estado

```powershell
docker compose ps
docker compose -f docker-compose.yml -f docker-compose.e2e.yml ps
```

Qué mirar:

- `mysql` healthy.
- `auth-service`, `raffles-service`, `orders-service`, `payments-service` y
  `scheduler` healthy.
- `nginx` arriba.
- MySQL **sin** puerto publicado.

Si el stack normal está arriba, no pasa nada: `prepare-demo-environment.ps1`
recrea los servicios sobre `rifacloud_test`.

### 2.3 Preparar el entorno demo

Primera vez o si cambió el código:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1
```

Si las imágenes ya están construidas:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1 -SkipBuild
```

Qué hace:

- aplica migraciones **solo** a `rifacloud_test`;
- levanta el stack con `docker-compose.e2e.yml`;
- fuerza gateway simulado;
- deja reservas de **60 minutos** para la demo;
- genera credenciales ficticias en `docs/testing/.local/test-users.json`;
- siembra rifas, órdenes y números demo de forma idempotente.

**No toca `rifacloud`.**

### 2.4 Verificación sin escribir datos

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-demo-environment.ps1
```

Comprueba login de los tres usuarios, catálogo, estados, historial, aislamiento y
métricas. **No crea órdenes, no migra y no modifica `rifacloud`.**

### 2.5 Credenciales

```powershell
Get-Content docs/testing/.local/test-users.json
```

Usuarios esperados:

- `demo-admin@rifacloud.test` (ADMIN)
- `demo-buyer1@rifacloud.test` (USER)
- `demo-buyer2@rifacloud.test` (USER)

No mostrar contraseñas en la proyección. Tenerlas en un papel o en otra ventana.

### 2.6 Capturas previas

Antes de modificar datos demo, tomar:

- `docker compose ps`;
- catálogo con los cuatro estados;
- dashboard con **3 rifas publicadas, 2 órdenes pagadas, 5 números vendidos, 1
  incidencia pendiente**;
- detalle de una rifa finalizada con resultado.

Así se tiene respaldo visual si algo falla después.

---

## 3. Guion de la demo

### Paso 1 — Registro

**Navegación:** `http://localhost/register`

**Qué hacer:**

1. Abrir el formulario.
2. Mostrar los dos checkboxes obligatorios:
   - Política de Tratamiento de Datos Personales;
   - Términos y Condiciones del prototipo.
3. Mostrar que el consentimiento promocional es opcional y está separado.
4. Abrir `/privacidad` y `/terminos` en otra pestaña (enlaces del formulario o
   del pie).
5. Registrar un usuario ficticio si se quiere mostrar el flujo completo.

**Datos sugeridos:**

- Nombre: `Demo nuevo`
- Email: `demo-nuevo-<hora>@rifacloud.test`
- Teléfono: `3000000000`
- Contraseña: una de prueba, mínimo 8 caracteres.

> **Advertencia:** un usuario registrado manualmente queda en `rifacloud_test`.
> No afecta `rifacloud`, pero no lo elimina `cleanup-demo-environment.ps1` si no
> es uno de los usuarios demo. Si se quiere evitar residuos, mostrar el
> formulario sin enviarlo y usar `demo-buyer1` para el resto.

**Qué decir:**

> “El registro no solo pide usuario y contraseña: exige aceptar privacidad y
> términos, con una versión de documento. El marketing es opcional y separado.
> La evidencia se guarda en `user_consents`.”

**Resultado esperado:** `201`, usuario autenticado, redirección a la ruta
original o al catálogo.

### Paso 2 — Catálogo

**Navegación:** `http://localhost/`

**Qué mostrar:**

- Rifa disponible.
- Rifa próximamente (sin botón de reserva).
- Rifa cerrada por fecha (sin reserva).
- Rifa finalizada con resultado (ganador, fecha, evidencia).
- La rifa `CLOSED` de administración **no** aparece en el catálogo público.

**Qué decir:**

> “El catálogo es público y lo sirve raffles-service. El estado visible se deriva
> del estado del backend y de las fechas, pero el servidor sigue validando todo
> al reservar.”

### Paso 3 — Reserva de números

**Navegación:** iniciar sesión como `demo-buyer1`; abrir la rifa disponible.

**Qué hacer:**

1. Seleccionar 1–3 números libres (no `SOLD` ni `RESERVED`).
2. Pulsar **Reservar números**.
3. Ver la orden `PENDING_PAYMENT`.
4. Ver el temporizador de 60 minutos.
5. Ver los números en `RESERVED`.

**Qué decir:**

> “La reserva es una sola transacción en orders-service. Bloquea la rifa y los
> números; si otro usuario tomó el mismo número, la segunda transacción recibe
> 409. La base también tiene una restricción única.”

**Resultado esperado:** `201`, orden creada, números `RESERVED`.

### Paso 4 — Pago simulado y verificación

**Navegación:** detalle de la orden.

**Qué hacer:**

1. Pulsar **Iniciar pago**.
2. Observar que el gateway simulado **no redirige** a un sitio externo; muestra
   el aviso de demostración y el botón **Verificar pago**.
3. Pulsar **Verificar pago**.
4. Ver la orden en `PAID` y los números en `SOLD`.
5. Actualizar la página: el estado debe mantenerse.

**Qué decir:**

> “Esto es gateway simulado: no se cobra dinero real. En Mercado Pago sandbox
> sería una redirección al checkout, pero la aprobación siempre la decide el
> servidor consultando al proveedor. La URL de retorno no aprueba nada.”

**Si se quiere mostrar rechazo (opcional, en ensayo):**

```powershell
$env:PAYMENT_FAKE_OUTCOME = 'REJECTED'
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --force-recreate payments-service
```

Luego crear otra reserva y pagar: la orden queda `REJECTED`, los números se
liberan y la UI ofrece reintentar. Para volver a `APPROVED`:

```powershell
$env:PAYMENT_FAKE_OUTCOME = 'APPROVED'
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --force-recreate payments-service
```

> No hacer esto en medio de la demo principal si el tiempo es corto; es un
> cambio de estado del entorno de pruebas.

### Paso 5 — Mis compras

**Navegación:** `http://localhost/orders` como `demo-buyer1`.

**Qué mostrar:**

- Órdenes pagadas, pendiente, expirada, rechazada y en revisión (según fixtures).
- Números `SOLD` como comprados; `RESERVED` como reservados; `AVAILABLE` como
  liberados.
- Fechas y total.

**Aislamiento:**

1. Abrir una segunda ventana/perfil como `demo-buyer2`.
2. Ir a `/orders`: solo debe ver su orden pendiente.
3. Intentar abrir por URL una orden de buyer1: debe dar 404 / “no encontrada”.

**Qué decir:**

> “El filtro por propietario está en la consulta SQL, no en React. Otro usuario
> recibe 404, no 403, para no revelar que la orden existe.”

### Paso 6 — Panel administrativo

**Navegación:** iniciar sesión como `demo-admin`; `http://localhost/admin/dashboard`.

**Qué mostrar:**

| Indicador | Valor inicial esperado |
|---|---:|
| Rifas publicadas | 3 |
| Órdenes pagadas | 2 |
| Números vendidos | 5 |
| Incidencias pendientes | 1 |

Luego:

- **Órdenes e incidencias**: pestaña de incidencias, `DEMO-REVIEW-B1` con pago
  `UNKNOWN`.
- **Rifas**: lista de administración.

**Qué decir:**

> “Cada indicador lo calcula el servicio dueño. Si un servicio se cae, la tarjeta
> muestra ‘No disponible’, no un cero. React no carga todas las órdenes para
> contar.”

**Importante:** si ya se hizo la compra del paso 3–4, los valores habrán subido
(por ejemplo, 3 pagadas y más números vendidos). No es un error. Para mostrar
3/2/5/1, abrir el dashboard **antes** de la compra o volver a sembrar los
fixtures.

### Paso 7 — Publicar resultado

**Navegación:** `http://localhost/admin/raffles` como ADMIN.

**Qué hacer:**

1. Localizar `[DEMO] Rifa cerrada con ventas para publicar resultado`.
2. Pulsar **Publicar resultado**.
3. Usar un número vendido: **1**, **2** o **3**.
4. Escribir una descripción de evidencia (por ejemplo, “Acta de sorteo firmada
   del ensayo”).
5. Opcional: URL de evidencia.
6. Publicar.

**Resultado esperado:**

- La rifa pasa a `FINISHED`.
- El resultado aparece en el catálogo y en el detalle público.
- No se puede publicar un segundo resultado.

**Qué decir:**

> “El resultado no se sortea automáticamente: lo publica un administrador sobre
> una rifa cerrada, con un número que ya está `SOLD` y con evidencia. Es un
> control de auditoría, no un generador de aleatoriedad.”

> Este paso modifica datos demo. Para restaurar el escenario, volver a ejecutar
> el seeder o limpiar el entorno.

### Paso 8 — Fallo controlado de un servicio

**Opción segura:** detener `orders-service`.

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml stop orders-service
```

**Qué mostrar:**

1. Abrir `/admin/dashboard`: la tarjeta de órdenes muestra **“No disponible”**.
2. Abrir `/api/orders` o intentar reservar: Nginx devuelve **503 JSON**
   controlado.
3. Abrir el catálogo y `/api/me`: siguen funcionando.
4. Restaurar:

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml start orders-service
```

5. Esperar healthy y refrescar.

**Qué decir:**

> “No se cae todo. Nginx convierte el fallo de conexión en un 503 JSON. El
> dashboard no inventa un cero. Cuando el servicio vuelve, se recupera.”

**No detener MySQL ni Nginx** en la demo.

### Paso 9 — Cierre y limpieza

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1
```

- Elimina rifas `[DEMO]`, órdenes `DEMO-` y sesiones demo de `rifacloud_test`.
- Restaura el stack normal sobre `rifacloud`.
- No borra el volumen.
- Conserva usuarios demo salvo `-PurgeUsers`.

Si se necesita conservar el stack de pruebas para seguir ensayando:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1 -KeepTestStack
```

---

## 4. Qué **no** ejecutar para proteger datos

| Comando | Por qué no |
|---|---|
| `docker compose exec app php artisan migrate` | Aplicaría migraciones a la base principal; requiere autorización |
| `docker compose exec app php artisan migrate:fresh` | Borra y recrea el esquema |
| `docker compose exec app php artisan db:wipe` | Borra datos |
| `docker compose down -v` | Elimina el volumen `mysql_data` |
| Borrar `mysql_data` desde Docker Desktop | Pérdida de datos |
| `deploy/aws/bootstrap-db.sh` | Es para AWS; no para la demo local |
| Crear recursos AWS | Fuera de alcance y con costo |
| Usar `MERCADOPAGO_ACCESS_TOKEN` real | No es necesario; la demo es simulada |
| Configurar SMTP real | No se envían correos reales |
| Publicar resultados sobre `rifacloud` | Modifica datos principales |
| Usar datos personales reales | Privacidad y minimización |
| `git commit` / `git push` | La tarea no lo autoriza |
| `scripts/verify-admin-dashboard.ps1` en medio de la demo | Recrea el stack y usa fixtures |
| `scripts/verify-orders-service.ps1` / `verify-payments-service.ps1` en vivo | Escriben fixtures y detienen servicios |

**Regla:** si un comando no está en este documento, no se ejecuta durante la
demo.

---

## 5. Plan alternativo si Docker o un contenedor falla

### 5.1 Diagnóstico rápido

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml ps
docker compose -f docker-compose.yml -f docker-compose.e2e.yml logs --tail=80 <servicio>
```

Buscar:

- `healthy` / `unhealthy`;
- `Exited`;
- errores de conexión a MySQL;
- puerto 80 ocupado;
- imagen no encontrada.

### 5.2 Árbol de decisión

| Síntoma | Acción | Si no se recupera |
|---|---|---|
| Docker Desktop apagado | Iniciarlo, esperar, `docker compose up -d` | Pasar a plan sin Docker (5.3) |
| Un servicio `unhealthy` | `docker compose restart <servicio>`; esperar healthy | Mostrar 503 controlado y seguir con lo demás |
| `orders-service` caído | Demostrar 503 + dashboard “No disponible”; reiniciar | Usar capturas de la compra y seguir con catálogo/admin |
| `payments-service` caído | Mostrar que catálogo/auth/orders siguen; reiniciar | Usar capturas del pago |
| `auth-service` caído | No se puede login nuevo; reiniciar | Usar una sesión ya abierta o capturas |
| MySQL `unhealthy` | **No** borrar volumen; revisar logs; reiniciar MySQL | Plan sin Docker; no improvisar `down -v` |
| Puerto 80 ocupado | Identificar el proceso y liberarlo; no cambiar Compose a ciegas | Usar capturas y explicar |
| Imagen faltante | `docker compose build <servicio>` | Usar imágenes existentes o plan sin Docker |
| Pantalla/proyector falla | Cambiar a otra pantalla; usar diagramas y código | Explicar con pizarra |

### 5.3 Plan sin Docker (o sin demo en vivo)

No fingir una demo. Decir:

> “El entorno local no está disponible en este equipo; mostramos la arquitectura,
> los contratos y las evidencias registradas. La demo completa se puede repetir
> con los scripts del repositorio.”

Material de respaldo:

1. Diagrama de arquitectura de `ARQUITECTURA_EXPLICADA.md`.
2. Diagramas de secuencia de `FLUJOS_TECNICOS.md`.
3. Tabla de resultados de `PRUEBAS_Y_EVIDENCIAS.md`.
4. Capturas tomadas en el ensayo (sección 2.6).
5. Código clave:
   - `OrderReservationService.php` (transacción y locks).
   - `PaymentResultApplier.php` (máquina de estados).
   - `WebhookService.php` (idempotencia).
   - `docker/nginx/default.conf` (rutas y 503).

**No decir:** “esto se está ejecutando” si es una captura. Decir: “esta es una
captura del ensayo; el comando para reproducirlo es…”.

### 5.4 Plan si falla el pago simulado

1. Verificar `PAYMENT_GATEWAY=fake` y `PAYMENT_FAKE_OUTCOME=APPROVED`:
   ```powershell
   docker compose -f docker-compose.yml -f docker-compose.e2e.yml exec -T payments-service printenv PAYMENT_GATEWAY PAYMENT_FAKE_OUTCOME
   ```
2. Si el pago quedó `UNKNOWN`, no liberar números a mano. Mostrar la orden en
   revisión y explicar la regla.
3. Reiniciar `payments-service` y reintentar la verificación.
4. Si sigue fallando, usar capturas y explicar el flujo; no inventar una
   aprobación.

### 5.5 Plan si falla la publicación del resultado

1. Verificar que la rifa esté `CLOSED`.
2. Verificar que el número sea `SOLD` (1, 2 o 3 en el fixture).
3. Verificar que no exista ya un resultado.
4. Si la tabla no estuviera disponible, el sistema devuelve validación
   controlada; no forzar datos por SQL.

---

## 6. Frases para acompañar cada paso

| Paso | Frase |
|---|---|
| Registro | “Consentimiento informado, versión y evidencia.” |
| Catálogo | “Público, paginado, con estados derivados y resultado.” |
| Reserva | “Una transacción, locks y restricción única.” |
| Pago | “El servidor verifica; la URL no aprueba.” |
| Mis compras | “El dueño sale de la sesión, no del cliente.” |
| Dashboard | “Cada servicio calcula lo suyo; 503 no es cero.” |
| Resultado | “Manual, con evidencia y una sola vez.” |
| Fallo | “503 controlado, sin datos falsos.” |

---

## 7. Checklist final de la demo

- [ ] Docker Desktop arriba.
- [ ] `prepare-demo-environment.ps1 -SkipBuild` ejecutado.
- [ ] `verify-demo-environment.ps1` verde.
- [ ] Credenciales demo a mano, no visibles.
- [ ] Navegador con sesión limpia.
- [ ] Dashboard 3/2/5/1 capturado antes de comprar.
- [ ] Números libres identificados.
- [ ] Plan B de capturas listo.
- [ ] `cleanup-demo-environment.ps1` preparado para el final.
- [ ] Nadie va a ejecutar `migrate`, `migrate:fresh`, `db:wipe` ni `down -v`.

---

## 8. Archivos que respaldan esta demo

| Tema | Archivo |
|---|---|
| Preparación | `scripts/prepare-demo-environment.ps1` |
| Verificación segura | `scripts/verify-demo-environment.ps1` |
| Limpieza | `scripts/cleanup-demo-environment.ps1` |
| Datos demo | `docs/testing/TEST_DATA.md`, `docs/testing/TEST_USERS.md` |
| Guion manual | `docs/testing/MANUAL_TEST_PLAN.md` |
| Sandbox | `docs/testing/MERCADOPAGO_SANDBOX.md` |
| Overlay de pruebas | `docker-compose.e2e.yml` |
| Rutas y 503 | `docker/nginx/default.conf` |
| Reserva | `services/orders-service/src/Orders/OrderReservationService.php` |
| Pago | `services/payments-service/src/Payments/VerificationService.php` |
| Resultado | `services/raffles-service/src/Admin/RaffleAdminService.php` |
| Dashboard | `frontend/src/pages/admin/AdminDashboardPage.tsx` |
