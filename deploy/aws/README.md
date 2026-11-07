# RifaCloud — despliegue en AWS EC2

Este documento prepara un despliegue **reproducible** en una sola EC2 con
Docker Compose. **No crea recursos AWS por sí mismo.** Todos los valores
`YOUR_DOMAIN`, `YOUR_BUCKET`, certificados y credenciales deben ser provistos
por el operador; no se asume que existan.

## 1. Arquitectura de despliegue

```
Internet
  │  80/443 (ALB + ACM, opción recomendada; o Nginx TLS directo)
  ▼
Nginx (contenedor, único puerto publicado)
  ├── /api/*          → auth / raffles / orders / payments (red Docker)
  ├── /sanctum/*      → auth-service
  └── /                → SPA React
                           │
                     MySQL 8.4 (sin puerto público)
                     volumen mysql_data
```

Componentes: `nginx`, `app` (Laravel monolith + rollback), `scheduler`,
`auth-service`, `raffles-service`, `orders-service`, `payments-service` y
`mysql`. No hay un quinto microservicio.

## 2. Requisitos previos

- Cuenta AWS y región elegida por el usuario.
- EC2 **Ubuntu 24.04 LTS**, idealmente `t3.medium` (2 vCPU, 4 GB RAM) o mayor.
- 30–40 GB de disco **gp3**; los respaldos van fuera de la instancia.
- Docker Engine + plugin Compose v2.
- Un dominio/subdominio con acceso a DNS, o un ALB con certificado ACM.
- Credenciales **sandbox** de Mercado Pago y su webhook secret.
- Clave SSH pública y un CIDR de administración para el puerto 22.
- Opcional: credenciales SMTP ya verificadas. Sin ellas, usar `MAIL_MAILER=log`.

No se requieren PHP, Composer ni Node en el host: se usan las imágenes.

## 3. Recursos AWS necesarios (no creados aún)

| Recurso | Uso | Notas |
|---|---|---|
| EC2 `t3.medium` | ejecutar Compose | Ubuntu 24.04, 4 GB RAM recomendado |
| EBS gp3 30–40 GB | disco raíz | snapshots adicionales opcionales |
| Elastic IP | IP estable | asociada a la EC2 o al ALB |
| Security Group EC2 | 22 desde admin; 80 desde ALB o 80/443 si Nginx TLS | nunca 3306 público |
| Security Group ALB (opcional) | 80/443 público | recomendado para TLS |
| ALB + ACM (opcional) | TLS gestionado | requiere dominio y validación DNS |
| Route 53/DNS (si aplica) | nombre público | no asumido |
| S3 + SSE-KMS | respaldos cifrados | versioning y lifecycle |
| IAM instance profile | S3/SSM/CloudWatch | sin access keys estáticas |
| CloudWatch (opcional) | logs/alertas | retención definida |

### Security Groups

**Con ALB (recomendado):**

- ALB: inbound 80/443 desde `0.0.0.0/0`; outbound al SG de la EC2 en 80.
- EC2: inbound 80 **solo desde el SG del ALB**; 22 desde el CIDR de administración.
- No abrir 3306, 9000 ni los puertos internos de los servicios.

**Sin ALB, Nginx TLS directo:**

- EC2: inbound 80 y 443 desde `0.0.0.0/0`; 22 desde el CIDR de administración.
- No abrir MySQL.

## 4. Preparar el host

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg git
# Instalar Docker Engine y el plugin compose según la documentación oficial.
sudo usermod -aG docker "$USER"
newgrp docker
```

Clonar el repositorio en, por ejemplo, `/opt/rifacloud` y entrar en él.
Mantener el usuario de despliegue sin privilegios root; usar `sudo` solo para
paquetes y archivos de sistema.

```bash
chmod +x deploy/aws/*.sh
```

## 5. Configuración de producción

```bash
cp deploy/aws/env.production.example .env
chmod 600 .env
# Editar .env: dominio, APP_KEY, contraseñas, Mercado Pago, SMTP, admin.
# No subir .env a Git.
```

Generar `APP_KEY` en el host (no requiere que la aplicación arranque):

```bash
openssl rand -base64 32
```

Copiar el valor a `APP_KEY=` en `.env` con el prefijo `base64:`, por ejemplo
`APP_KEY=base64:...`. No reutilizar la clave de tests. Si se prefiere Laravel,
puede generarse después de configurar el resto del entorno con
`php artisan key:generate --show`.

`payments-service` conserva un `env_file` base; en producción debe existir un
`src/.env` **sin secretos**:

```bash
printf '# production: secrets come from the root .env\n' > src/.env
chmod 600 src/.env
```

Las variables reales se inyectan desde el `.env` raíz mediante
`docker-compose.prod.yml`. No escribir tokens en `src/.env`.

Si se usa ALB, `TRUSTED_PROXIES=*` es aceptable **solo** si el puerto 80 de la
EC2 está restringido al Security Group del ALB. Si el origen es accesible
directamente, usar el CIDR del proxy.

## 6. HTTPS

### Opción A — ALB + ACM (recomendada)

1. Solicitar/importar un certificado ACM para el dominio real en la región.
2. Crear un ALB público con listener 443 (ACM) y 80 → redirección a 443.
3. Target group HTTP al puerto 80 de la EC2, health check `/healthz`.
4. Mantener `docker-compose.prod.yml` sin el overlay TLS.
5. `APP_URL=https://YOUR_DOMAIN`, `SESSION_SECURE_COOKIE=true`,
   `TRUSTED_PROXIES=*`.

No se crea ni se valida el certificado en esta fase.

### Opción B — Nginx TLS directo

Requiere dominio, DNS apuntando a la Elastic IP y certificado. Puede usarse
Let's Encrypt o un certificado corporativo; **no se asume ninguno**.

```bash
deploy/aws/render-nginx-tls.sh \
  --domain YOUR_DOMAIN \
  --cert /etc/nginx/certs/fullchain.pem \
  --key /etc/nginx/certs/privkey.pem

TLS_CERT_DIR=/etc/nginx/certs \
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               -f docker-compose.prod-tls.yml up -d
```

El archivo renderizado `deploy/aws/nginx/https.conf` está ignorado por Git.
Para Let's Encrypt, montar los certificados en `/etc/nginx/certs` y renovar con
un timer del sistema; recargar Nginx tras la renovación.

## 7. Construir y arrancar

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# The monolith image does not embed vendor/ (src/ is mounted at runtime).
# Install the locked PHP dependencies once on a fresh checkout:
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
    run --rm app composer install --no-dev --no-interaction --prefer-dist --no-progress

# First deployment: migrate and create least-privilege users before exposing app.
set -a; . ./.env; set +a
CONFIRM_MIGRATE=yes deploy/aws/bootstrap-db.sh

docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
```

Antes de migrar, revisar el estado y el respaldo. En un esquema nuevo el
script arranca MySQL, aplica las migraciones y crea los usuarios; después se
levantan app/Nginx.

El script:
- espera MySQL healthy;
- exige `CONFIRM_MIGRATE=yes`;
- si ya hay tablas, exige un respaldo cifrado previo;
- aplica las migraciones con `migrate --force` (13 en un esquema nuevo);
- crea/actualiza los cuatro usuarios de servicio desde `.env`;
- aplica `deploy/aws/grants-production.sql`;
- opcionalmente ejecuta `AdminUserSeeder` con `SEED_ADMIN=yes`.

No ejecuta `migrate:fresh` ni elimina volúmenes.

Administrador inicial opcional:

```bash
SEED_ADMIN=yes CONFIRM_MIGRATE=yes deploy/aws/bootstrap-db.sh
```

Requiere `ADMIN_EMAIL` y `ADMIN_PASSWORD` en `.env`; no se crea un
administrador con contraseña por defecto.

## 8. Mercado Pago sandbox y webhook

Configurar en `.env`:

```dotenv
PAYMENT_GATEWAY=mercadopago
MERCADOPAGO_SANDBOX=true
MERCADOPAGO_ACCESS_TOKEN=...
MERCADOPAGO_WEBHOOK_SECRET=...
MERCADOPAGO_WEBHOOK_URL=https://YOUR_DOMAIN/api/webhooks/mercadopago
# Base URL only: payments-service appends /orders/{id}?pago=retorno.
MERCADOPAGO_BACK_URL=https://YOUR_DOMAIN
```

La URL pública apunta a Nginx, que la reenvía a `payments-service`; `/internal/`
sigue bloqueado. Registrar la **misma** URL en el panel de Mercado Pago.

Comprobaciones que no demuestran por sí solas un pago real:

```bash
curl -i https://YOUR_DOMAIN/api/webhooks/mercadopago
# GET debe ser 405; POST mal formado 400; firma inválida 401 con secreto.
```

La validación real requiere una notificación sandbox del proveedor con la
firma correcta. **No afirmar que el webhook funciona hasta completar esa
prueba** y verificar que la orden/números cambian una sola vez.

Para el retorno, `MERCADOPAGO_BACK_URL` debe ser la **base** de la URL pública
(sin `/orders`): `payments-service` añade `/orders/{id}?pago=retorno`. La
aprobación la decide el servidor con `/verify`, nunca la URL del navegador.

## 9. Backups cifrados y restauración

### Configuración

Crear `/etc/rifacloud/backup.env`, root:root, modo 600:

```bash
sudo cp deploy/aws/backup.env.example /etc/rifacloud/backup.env
sudo chmod 600 /etc/rifacloud/backup.env
sudo editor /etc/rifacloud/backup.env
```

Importar la clave pública GPG del destinatario (o registrar la clave age) en la
EC2. El script no genera claves privadas ni las almacena.

Requisitos: `gpg` o `age`, AWS CLI y un rol de instancia con permiso de
escritura al bucket. El script **se niega a crear respaldos sin cifrar**.

Bucket S3: bloquear acceso público, habilitar versioning, SSE-KMS y lifecycle
(p. ej. transición a Glacier a los 30 días y expiración a los 90/365 según la
política de la universidad). Retención local por defecto: 14 días.

### Ejecutar

```bash
sudo deploy/aws/backup-db.sh
```

Programar diariamente con systemd timer o cron (ej. 03:15 UTC) y ejecutar un
respaldo antes de cada despliegue con migraciones.

### Verificar un respaldo

```bash
deploy/aws/restore-db.sh --file /var/backups/rifacloud/rifacloud-....sql.gz.gpg
```

El script restaura en una base temporal `rifacloud_restore_check_*`, muestra
conteos y la elimina. **Nunca restaura sobre `rifacloud`.**

### Recuperación real (procedimiento manual)

1. Detener `app` y `scheduler` (mantener MySQL).
2. Tomar un respaldo del estado actual.
3. Descifrar el respaldo elegido y restaurarlo en `rifacloud` con MySQL.
4. Ejecutar `php artisan migrate --force` si el respaldo es anterior a las
   migraciones actuales.
5. Verificar `migrate:status`, conteos y `/healthz`.
6. Arrancar `app`/`scheduler` y ejecutar `deploy/aws/verify-deployment.sh`.

No borrar el volumen `mysql_data` como parte de una restauración.

## 10. Verificación posterior

```bash
deploy/aws/verify-deployment.sh
# Con Nginx TLS directo, usar la URL HTTPS pública:
BASE_URL=https://YOUR_DOMAIN deploy/aws/verify-deployment.sh
```

Comprueba Compose, contenedores, `/healthz`, catálogo, 401 administrativo,
`/internal/` 404, MySQL sin puerto público, migraciones y heartbeat del
scheduler. No imprime secretos.

## 11. Actualizaciones y rollback

Actualización:

```bash
git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
deploy/aws/backup-db.sh          # obligatorio si hay migraciones
CONFIRM_MIGRATE=yes deploy/aws/bootstrap-db.sh
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
deploy/aws/verify-deployment.sh
```

El monolito conserva sus rutas de rollback; no se retiran en esta fase. Para
volver atrás una ruta, se revierte el `location` de Nginx y se reconstruye
Nginx. No se eliminan datos.

## 12. Costos aproximados (USD/mes, varían por región y fecha)

| Concepto | Aproximado |
|---|---|
| EC2 t3.medium on-demand | ~30 |
| EBS gp3 30 GB | ~2–3 |
| Elastic IP | ~3–4 |
| S3 + KMS (respaldos pequeños) | <2 |
| CloudWatch/logs | ~2–5 |
| ALB + LCU (opcional) | ~18–30 |
| Route 53 hosted zone (si se usa) | ~0.50 |

Total orientativo: **~40–50 USD/mes sin ALB**, **~60–85 USD/mes con ALB**.
Los precios deben confirmarse en la calculadora de AWS antes de crear recursos.
No se ha creado ningún recurso.

## 13. Información que debe proporcionar el usuario

- Región AWS, cuenta/permisos para crear EC2, SG, EIP, S3, IAM y (si aplica) ALB/ACM.
- Clave SSH pública y CIDR de administración.
- Dominio/subdominio y acceso DNS, o decisión de usar ALB + ACM.
- Certificado/renovación si se elige Nginx TLS.
- Credenciales sandbox de Mercado Pago y webhook secret (nunca en Git).
- SMTP/Mailtrap verificado o decisión de mantener `MAIL_MAILER=log`.
- Bucket S3, clave KMS y destinatario GPG/age para backups.
- Email y contraseña del administrador inicial.
- Confirmación de que no se harán cobros reales.

## 14. Bloqueos actuales

- No se han creado recursos AWS, DNS, certificados ni buckets.
- El webhook real de Mercado Pago no está probado: faltan secreto y URL pública.
- SMTP real no está configurado; la cola usa `log` para no enviar correos.
- El respaldo externo S3/KMS aún no existe; el script está preparado.
- Los documentos SDD/AGENTS se han desbloqueado en `.gitignore`, pero **no se
  han commiteado ni pusheado**: para recuperarlos desde GitHub hace falta un
  commit/push autorizado.
- La aplicación main ya tiene 13 migraciones y los grants; no se requieren
  migraciones adicionales para esta fase.
