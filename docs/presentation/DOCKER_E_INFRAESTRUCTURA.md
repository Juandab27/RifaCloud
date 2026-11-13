# RifaCloud — Docker e infraestructura

**Proyecto:** RifaCloud  
**Uso:** explicar contenedores, red, puertos, salud, persistencia y estado real de AWS  
**Base:** `docker-compose*.yml`, Dockerfiles, `deploy/aws/` y documentación SDD

> **Regla de honestidad**
>
> “Preparado” no significa “desplegado”. En este repositorio hay plantillas,
> scripts y runbooks de AWS, pero **no se creó ningún recurso AWS**. Tampoco hay
> un dominio, certificado, bucket S3 ni webhook público real.

---

## 1. Vista general

```text
                         Internet
                            │
                            ▼
                    ┌───────────────┐
                    │     Nginx     │  :80 público
                    │  SPA + rutas  │  :443 solo con overlay TLS
                    └───────┬───────┘
                            │ red Docker interna
        ┌───────────────────┼────────────────────┬───────────────────┐
        ▼                   ▼                    ▼                   ▼
 ┌─────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
 │    app      │    │ auth-service │    │raffles-service│   │ orders-service│
 │  monolito   │    │   PHP-FPM    │    │    Apache     │   │    Apache     │
 │  PHP-FPM    │    │    :9000     │    │     :80       │   │     :80       │
 └──────┬──────┘    └──────┬───────┘    └──────┬────────┘   └──────┬───────┘
        │                  │                   │                   │
        │           ┌──────┴───────────────────┴───────────────────┘
        │           ▼
        │    ┌──────────────┐        ┌──────────────────┐
        │    │payments-service│      │    scheduler     │
        │    │    Apache      │      │  schedule:work   │
        │    │     :80        │      │  sin API pública │
        │    └──────┬─────────┘      └────────┬─────────┘
        │           │                         │
        └───────────┴──────────┬──────────────┘
                               ▼
                    ┌─────────────────────┐
                    │       MySQL 8.4     │
                    │ :3306 solo interno  │
                    │ volumen mysql_data  │
                    └─────────────────────┘
```

**Hecho clave:** solo Nginx publica puertos al host. Los demás contenedores son
accesibles únicamente por la red Docker.

---

## 2. Contenedores y funciones

| Contenedor | Imagen base | Puerto interno | ¿Publica? | Función | Reinicio |
|---|---|---:|---|---|---|
| `nginx` | `nginx:1.27-alpine` (runtime) | 80 | **Sí, 80** (y 443 con TLS) | Sirve la SPA, enruta API, devuelve 503 controlado, bloquea `/internal/` | `unless-stopped` |
| `app` | `php:8.4-fpm` | 9000 | No | Monolito Laravel: rutas restantes, scheduler commands, rollback | `unless-stopped` |
| `scheduler` | `php:8.4-fpm` (misma definición que `app`) | — | No | Ejecuta `schedule:work` bajo supervisor; sin API de negocio | `unless-stopped` |
| `auth-service` | `php:8.4-fpm` | 9000 | No | Registro, login, logout, `/me`, sesiones, roles, consentimiento | `unless-stopped` |
| `raffles-service` | `php:8.4-apache` | 80 | No | Catálogo, administración de rifas, generación de números, resultados | `unless-stopped` |
| `orders-service` | `php:8.4-apache` | 80 | No | Reservas, órdenes, historial, vencimiento, asignación atómica | `unless-stopped` |
| `payments-service` | `php:8.4-apache` | 80 | No | Checkout, verificación, webhooks, reconciliación | `unless-stopped` |
| `mysql` | `mysql:8.4` | 3306 | No | Base de datos compartida durante la transición | `unless-stopped` |

### 2.1 Dependencias de arranque

- `app`, `scheduler`, `auth-service`, `raffles-service`, `orders-service` y
  `payments-service` esperan a que MySQL esté `healthy`.
- `nginx` depende de `app` (sin condición de salud en el Compose base).
- `payments-service` **no** depende de que `orders-service` esté healthy: el
  checkout puede seguir disponible mientras la verificación de la orden falla
  en cerrado.
- Los servicios se resuelven por DNS interno: `mysql`, `app`,
  `orders-service`, etc.

### 2.2 El scheduler no es un quinto microservicio

Es un proceso de tareas programadas. No tiene API de negocio, no publica
puertos y no es dueño de datos de rifas, órdenes o pagos. Su única escritura
propia es la tabla `notifications` (y el heartbeat local del contenedor).

**Archivos:** `docker-compose.yml`, `docker/scheduler/entrypoint.sh`,
`src/routes/console.php`.

---

## 3. Dockerfiles, imágenes y volúmenes

### 3.1 Dockerfiles

| Archivo | Construye | Notas |
|---|---|---|
| `docker/php/Dockerfile` | Imagen del monolito `app`/`scheduler` | `php:8.4-fpm`, instala `pdo_mysql` y `opcache`, copia Composer 2 y `php.ini` |
| `docker/nginx/Dockerfile` | Imagen de Nginx + SPA | Etapa `node:22-alpine` ejecuta `npm ci` y `npm run build`; runtime `nginx:1.27-alpine` |
| `services/auth-service/Dockerfile` | `auth-service` | PHP-FPM, `composer install` desde `composer.lock`, `artisan package:discover` |
| `services/raffles-service/Dockerfile` | `raffles-service` | `php:8.4-apache`, `pdo_mysql`, DocumentRoot en `public/` |
| `services/orders-service/Dockerfile` | `orders-service` | Igual patrón Apache + `pdo_mysql` |
| `services/payments-service/Dockerfile` | `payments-service` | Igual patrón Apache + `pdo_mysql` |

### 3.2 Particularidad del monolito

La imagen `app` **no copia** el código ni instala dependencias PHP en el build.
El código se monta desde `./src:/var/www` y el `vendor/` se instala una vez con:

```sh
docker compose run --rm app composer install
```

Por eso el README indica ese paso antes de levantar el stack. No es un error de
Docker: es una decisión de desarrollo para montar el código y poder editarlo.

### 3.3 Volúmenes y mounts

| Tipo | Nombre / ruta | Qué guarda | Persistencia |
|---|---|---|---|
| Volumen nombrado | `mysql_data` | Datos de MySQL | Sí, sobrevive `docker compose down`; **no** sobrevive `down -v` |
| Bind mount | `./src:/var/www` | Código del monolito | Host |
| Bind mount | `./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro` | Configuración de rutas | Host |
| Bind mount | `./docker/scheduler/entrypoint.sh:...:ro` | Supervisor del scheduler | Host |
| Bind mount | `./docker/mysql/init:/docker-entrypoint-initdb.d:ro` | Crea `rifacloud_test` al inicializar un volumen nuevo | Solo primera inicialización |
| Bind mount TLS | `${TLS_CERT_DIR}:/etc/nginx/certs:ro` | Certificados | Solo overlay TLS |

Los servicios `auth`, `raffles`, `orders` y `payments` llevan su código dentro
de la imagen; no montan `src/`. Son stateless: no guardan archivos de negocio en
el contenedor.

### 3.4 Imágenes

Docker Compose nombra las imágenes con el proyecto (`name: rifacloud`) y el
servicio, por ejemplo `rifacloud-nginx`, `rifacloud-app`,
`rifacloud-orders-service`. No se usa un registro externo en la demo: se
construyen localmente.

**Archivos:** `docker/php/Dockerfile`, `docker/nginx/Dockerfile`,
`services/*/Dockerfile`, `docker-compose.yml`, `README.md`.

---

## 4. Red y puertos expuestos

### 4.1 Red

- Compose crea una red bridge por defecto: `rifacloud_default`.
- Todos los servicios están en esa red.
- Nginx resuelve los upstreams con el DNS embebido de Docker
  (`resolver 127.0.0.11`), lo que permite arrancar aunque un servicio esté
  detenido.
- No hay red `host` ni publicación de puertos internos.

### 4.2 Puertos

| Puerto | Dónde | ¿Público? | Uso |
|---:|---|---|---|
| 80 | Nginx | **Sí** | HTTP de la demo |
| 443 | Nginx | Solo overlay TLS | HTTPS directo |
| 9000 | `app`, `auth-service` | No | PHP-FPM interno |
| 80 | `raffles`, `orders`, `payments` | No | Apache interno |
| 3306 | MySQL | **No** | Solo red Docker |
| 22 | EC2 (AWS) | Restringido | SSH administrativo, no existe aún |

### 4.3 Rutas internas

- `/internal/*` devuelve `404` en Nginx.
- Los health endpoints (`/health`, `/api/auth/health`) son internos; Nginx no
  los publica como rutas de negocio.
- `/healthz` es el liveness de Nginx y no toca la base.

**Archivos:** `docker-compose.yml`, `docker/nginx/default.conf`,
`docs/sdd/02-technical-design.md` (sección 9).

---

## 5. Healthchecks

| Contenedor | Chequeo | Intervalo / timeout / reintentos | Qué significa |
|---|---|---|---|
| `mysql` | `mysqladmin ping` con root | 5 s / 5 s / 20; start 30 s | MySQL acepta conexiones |
| `auth-service` | `php /var/www/healthcheck.php` | 10 s / 5 s / 12; start 15 s | Puede abrir PDO contra MySQL |
| `raffles-service` | `GET http://127.0.0.1/health` | 10 s / 5 s / 12; start 10 s | Proceso + `SELECT 1` |
| `orders-service` | `GET http://127.0.0.1/health` | 10 s / 5 s / 12; start 10 s | Proceso + `SELECT 1` |
| `payments-service` | `GET http://127.0.0.1/health` | 10 s / 5 s / 12; start 10 s | Proceso + `SELECT 1` |
| `scheduler` | Heartbeat `/tmp/rifacloud-scheduler-heartbeat` | 30 s / 5 s / 3; start 75 s | `schedule:work` sigue vivo (archivo < 180 s) |
| `nginx` | `GET /healthz` (solo overlay prod) | 30 s / 5 s / 3; start 20 s | Nginx responde |
| `app` | `fsockopen(127.0.0.1, 9000)` (solo overlay prod) | 30 s / 5 s / 3; start 30 s | PHP-FPM acepta conexiones |

**Matiz importante:** en el `docker-compose.yml` base, `nginx` y `app` no tienen
healthcheck; se añade en `docker-compose.prod.yml`. No se debe afirmar que el
stack de desarrollo tiene healthcheck de Nginx si se está usando solo el archivo
base.

**Archivos:** `docker-compose.yml`, `docker-compose.prod.yml`,
`services/auth-service/healthcheck.php`,
`services/*/public/index.php`.

---

## 6. Persistencia y reinicios

### 6.1 Persistencia

- Los datos viven en el volumen `mysql_data`.
- `docker compose down` conserva el volumen.
- `docker compose down -v` **borra** los datos; no usarlo en la demo.
- En AWS, el volumen viviría en el disco EBS de la instancia; **no está
  desplegado**.
- Las sesiones están en MySQL (`SESSION_DRIVER=database`), no en memoria del
  contenedor.
- El scheduler guarda el heartbeat en `/tmp` del contenedor; se pierde al
  recrearlo, pero se regenera cada minuto.

### 6.2 Reinicios

- Todos los servicios usan `restart: unless-stopped`.
- El scheduler usa `init: true` y `stop_grace_period: 30s`.
- El supervisor del scheduler atrapa `SIGTERM`/`SIGINT`/`SIGQUIT`, detiene al
  hijo y sale limpiamente; si el hijo muere inesperadamente, lo reinicia tras 5
  segundos.
- Un reinicio de un servicio no reinicia MySQL ni Nginx.
- Nginx puede arrancar aunque un upstream esté detenido gracias al resolver
  dinámico; devolverá 503 en las rutas afectadas.

### 6.3 Comandos seguros de operación

```sh
docker compose ps
docker compose logs -f app
docker compose logs -f orders-service
docker compose stop orders-service
docker compose start orders-service
docker compose restart payments-service
```

No ejecutar en la demo:

```sh
docker compose down -v
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan db:wipe
```

**Archivos:** `docker-compose.yml`, `docker/scheduler/entrypoint.sh`,
`README.md` (comandos útiles).

---

## 7. Overlays de Compose

| Archivo | Para qué | Qué cambia |
|---|---|---|
| `docker-compose.yml` | Stack base de desarrollo | Servicios, red, volumen, healthchecks de servicios |
| `docker-compose.e2e.yml` | Pruebas/demo sobre `rifacloud_test` | Cambia `DB_DATABASE`/`RAFFLES_DB_DATABASE`/`ORDERS_DB_DATABASE`/`PAYMENTS_DB_DATABASE` a `rifacloud_test`; fuerza `PAYMENT_GATEWAY=fake` |
| `docker-compose.prod.yml` | Overlay de producción | Variables explícitas, cookies seguras, `APP_ENV=production`, healthchecks de `app` y `nginx`, sin credenciales de panel |
| `docker-compose.prod-tls.yml` | TLS directo en Nginx | Publica 443, monta certificados y `https.conf` |
| `docker-compose.mercadopago-sandbox.yml` | Sandbox real temporal | Inyecta configuración de Mercado Pago sin secretos en el archivo |

**Uso típico de demo:**

```sh
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d
```

No usar `docker-compose.prod.yml` para la demo local: exige dominio, HTTPS,
tokens de Mercado Pago y secretos de webhook.

**Archivos:** `docker-compose.e2e.yml`, `docker-compose.prod.yml`,
`docker-compose.prod-tls.yml`, `docker-compose.mercadopago-sandbox.yml`.

---

## 8. AWS: preparado, no desplegado

### 8.1 Qué existe en el repositorio

| Archivo | Función | ¿Crea recursos? |
|---|---|---|
| `deploy/aws/README.md` | Runbook completo: requisitos, security groups, HTTPS, build, migración, backups, restauración, verificación, costos | No |
| `deploy/aws/env.production.example` | Plantilla de variables sin secretos | No |
| `deploy/aws/bootstrap-db.sh` | Espera MySQL, exige `CONFIRM_MIGRATE=yes`, respalda si hay datos, aplica migraciones, crea usuarios y grants | No crea EC2/S3 |
| `deploy/aws/grants-production.sql` | Grants mínimos por servicio | No |
| `deploy/aws/backup-db.sh` | Dump cifrado (age/GPG), subida a S3, retención local | No crea bucket |
| `deploy/aws/restore-db.sh` | Verifica un backup en base temporal; no restaura sobre `rifacloud` | No |
| `deploy/aws/render-nginx-tls.sh` | Renderiza configuración TLS para Nginx directo | No |
| `deploy/aws/verify-deployment.sh` | Verifica Compose, contenedores, `/healthz`, catálogo, 401 admin, `/internal/` 404, MySQL sin puerto, migraciones y heartbeat | No |
| `deploy/aws/nginx/https.conf.example` | Plantilla TLS | No |

### 8.2 Arquitectura AWS prevista

```text
Internet
  │ 80/443
  ▼
ALB + ACM (recomendado)  o  Nginx TLS directo
  │
  ▼
EC2 Ubuntu 24.04 (t3.medium o mayor)
  └── Docker Compose: nginx, app, scheduler, 4 servicios, mysql
        └── EBS gp3 (datos)
              └── backups cifrados a S3 + KMS
```

- Security Groups: 80/443 público solo en ALB (o en EC2 si TLS directo); 22
  restringido a IP administrativa; **nunca** 3306/9000/servicios internos.
- IAM instance profile para S3/SSM/CloudWatch, sin access keys estáticas.
- S3 con versioning, SSE-KMS y lifecycle.
- CloudWatch opcional.

### 8.3 Qué falta para desplegar

- Cuenta/permisos AWS y región.
- EC2, EBS, Elastic IP, Security Groups.
- Dominio/DNS y certificado (ALB+ACM o Nginx TLS).
- Credenciales sandbox de Mercado Pago y webhook secret.
- URL HTTPS pública del webhook.
- SMTP verificado (o decisión de mantener `log`).
- Bucket S3, KMS y destinatario GPG/age.
- Email/contraseña del administrador inicial.
- Autorización explícita para crear recursos y migrar.

### 8.4 Costos

El runbook da cifras orientativas: **~40–50 USD/mes sin ALB** y **~60–85
USD/mes con ALB**, pero deben confirmarse en la calculadora de AWS. No se ha
creado ni medido ningún recurso.

### 8.5 Lo que no se debe afirmar

- “Está en AWS.” → No.
- “Hay alta disponibilidad.” → No; una sola EC2.
- “El webhook real funciona.” → No verificado.
- “Los backups están en S3.” → El script está preparado; el bucket no existe.
- “Hay dominio y HTTPS.” → No creados.

**Archivos:** `deploy/aws/README.md`, `deploy/aws/env.production.example`,
`deploy/aws/*.sh`, `docker-compose.prod.yml`,
`docker-compose.prod-tls.yml`.

---

## 9. Seguridad de infraestructura

| Control | Implementación |
|---|---|
| MySQL no público | Sin `ports:`; solo red Docker |
| Único punto de entrada | Nginx publica 80/443 |
| Secretos fuera del código | Variables de entorno / `.env` no versionado |
| Servicios internos | Sin puertos publicados; `/internal/` bloqueado en Nginx |
| Usuarios MySQL mínimos | Cuatro usuarios por servicio |
| Cookies seguras en producción | `SESSION_SECURE_COOKIE=true`, `SameSite=lax` |
| TLS | ALB+ACM o Nginx TLS; no asumido en local |
| Backups | Cifrados antes de salir de la instancia |
| Logs | A stdout/stderr; sin secretos ni payloads completos |

**Matiz:** el stack local usa HTTP y `SESSION_SECURE_COOKIE=false`. Eso es
aceptable para la demo local, no para producción.

---

## 10. Checklist de infraestructura para la sustentación

- [ ] `docker compose ps` muestra los ocho contenedores.
- [ ] `mysql` está healthy y **no** tiene puerto publicado.
- [ ] Solo `nginx` tiene `0.0.0.0:80->80/tcp`.
- [ ] `curl http://localhost/healthz` responde `ok`.
- [ ] `docker compose stop orders-service` produce 503 controlado y no tumba
      auth/catálogo.
- [ ] `docker compose start orders-service` lo recupera.
- [ ] `docker volume ls` muestra `rifacloud_mysql_data`.
- [ ] Se puede explicar que `down` conserva datos y `down -v` los borra.
- [ ] Se puede explicar que AWS está preparado, no desplegado.

---

## 11. Archivos que respaldan esta explicación

| Tema | Archivo |
|---|---|
| Stack base | `docker-compose.yml` |
| Overlay de pruebas | `docker-compose.e2e.yml` |
| Overlay de producción | `docker-compose.prod.yml` |
| TLS directo | `docker-compose.prod-tls.yml` |
| Nginx | `docker/nginx/default.conf`, `docker/nginx/Dockerfile` |
| PHP del monolito | `docker/php/Dockerfile`, `docker/php/php.ini` |
| Servicios | `services/*/Dockerfile`, `services/*/apache.conf` |
| Scheduler | `docker/scheduler/entrypoint.sh` |
| MySQL init | `docker/mysql/init/01-test-database.sql` |
| AWS | `deploy/aws/README.md`, `deploy/aws/*.sh`, `deploy/aws/env.production.example` |
| Decisiones | `docs/sdd/02-technical-design.md` (secciones 9 y 10), `docs/sdd/08-adr-001-four-service-architecture.md` |
