# 01 — Inventario de tratamiento de datos personales

**Proyecto:** RifaCloud
**Versión:** 2026-11-11
**Estado:** Borrador interno para revisión jurídica
**Alcance:** prototipo académico y estado real del repositorio a 2026-11-11

> Este inventario describe lo que el prototipo hace hoy, no lo que una
> plataforma comercial debería hacer. No afirma cumplimiento ni autorización.

## 1. Método

Se revisaron:

- el modelo de datos (`docs/sdd/03-data-model.md` y migraciones de `src/`);
- el registro y la autenticación (`auth-service` y módulo `Auth` del monolito);
- los servicios `raffles`, `orders`, `payments` y el planificador;
- las notificaciones por correo;
- la interfaz React y el almacenamiento del navegador;
- la configuración de sesiones, registros y despliegue.

No se identificaron herramientas de analítica, publicidad, mapas de calor ni
seguimiento de terceros en el frontend. El prototipo no solicita documento de
identidad, datos biométricos, datos de salud ni datos financieros completos.

## 2. Roles del tratamiento

| Rol | Definición legal | Estado en RifaCloud |
|---|---|---|
| **Responsable** | Persona natural o jurídica que decide sobre la base de datos y el tratamiento | **No definido.** Se usa `[RESPONSABLE PENDIENTE]`; no se inventa empresa, NIT, domicilio ni contacto |
| **Encargado** | Persona que trata datos por cuenta del Responsable | Proveedores de pagos, correo e infraestructura; contratos y roles pendientes |
| **Titular** | Persona natural cuyos datos son tratados | Usuarios registrados y administradores |
| **Autoridad de protección de datos** | Superintendencia de Industria y Comercio | Referencia legal; no se ha presentado registro ni solicitud |
| **Autoridad de juegos de suerte y azar** | Coljuegos y autoridades territoriales competentes | RifaCloud no tiene autorización ni vínculo contractual con ellas |

Los microservicios son componentes técnicos del mismo tratamiento. `auth-service`
no es un Responsable separado: es el servicio que administra usuarios, sesiones
y la evidencia de consentimiento dentro de la arquitectura.

## 3. Categorías de datos y ubicación

### 3.1 Datos proporcionados por el usuario

| Dato | Categoría | Obligatorio | Tabla / campo | Finalidad |
|---|---|---|---|---|
| Nombre | Identificación | Sí | `users.name` | Cuenta, comunicaciones, identificación básica |
| Correo electrónico | Contacto / identificador | Sí | `users.email` (único) | Autenticación, notificaciones, contacto |
| Teléfono | Contacto | Sí | `users.phone` | Contacto y soporte del prototipo |
| Contraseña | Credencial | Sí | `users.password` (hash) | Autenticación; nunca se almacena en claro |
| Aceptación de privacidad y términos | Evidencia legal | Sí | `user_consents` (`type`, `document_version`, `accepted`, `accepted_at`) | Probar autorización informada |
| Decisión promocional | Evidencia legal | No | `user_consents` (`type = MARKETING`) | Separar el consentimiento opcional |

### 3.2 Datos generados por el uso

| Dato | Categoría | Dónde | Finalidad |
|---|---|---|---|
| Identificador de sesión, usuario, IP, agente de usuario, actividad | Técnico / seguridad | `sessions` | Mantener la sesión, detectar abuso y aplicar expiración |
| Claves de límite de tasa y bloqueos de caché | Técnico / seguridad | `cache`, `cache_locks` | Limitar intentos de registro/login y coordinar workers; la clave puede incluir correo o IP según el throttling |
| Orden, rifa, números, montos, estados, fechas | Transaccional | `orders`, `order_items`, `raffle_numbers` | Reserva, compra de demostración, historial y conciliación |
| Identificadores y estado de pago, monto, moneda, URL de checkout | Transaccional / proveedor | `payments` | Verificación del pago del gateway simulado o del sandbox del proveedor |
| Identificador y tipo de evento de webhook, carga sanitizada | Técnico / auditoría | `payment_webhook_events` | Idempotencia y conciliación |
| Correo destinatario, asunto, cuerpo con nombre y orden | Contacto / comunicaciones | `notifications` | Confirmaciones e incidencias |
| Número ganador, administrador que publica, evidencia | Resultado / auditoría | `raffle_results` | Resultado de demostración |
| Registros de acceso y errores | Técnico | Logs de Nginx, PHP, planificador | Seguridad, diagnóstico y disponibilidad |

> **Nota local:** con `MAIL_MAILER=log`, el contenido del correo (incluido el
> nombre y el correo del destinatario) se escribe en los registros en lugar de
> enviarse. No se deben usar datos reales en ese modo. Un proveedor SMTP real
> (P2) cambia el destino, no la necesidad de minimizar el contenido. En
> producción, la rotación y el acceso a los logs deben limitarse.

### 3.3 Datos de administradores

Los administradores tienen los mismos datos de cuenta (`users`) y además
aparecen como `raffles.created_by` y `raffle_results.published_by`. Su correo y
nombre pueden verse en el panel administrativo. El acceso está restringido por
rol `ADMIN` validado en el servidor.

### 3.4 Datos que el prototipo no recopila intencionalmente

- Documento de identidad, pasaporte o fecha de nacimiento.
- Datos biométricos, de salud, orientación sexual, religión o política.
- Datos completos de tarjetas o cuentas bancarias.
- Ubicación precisa, contactos del teléfono o libreta de direcciones.
- Datos de menores de edad de forma deliberada.

El prototipo tampoco implementa verificación de edad ni de identidad. Esa
ausencia es un riesgo declarado para cualquier uso real.

## 4. Flujo del tratamiento

```text
Registro
  ├─ nombre, correo, teléfono, contraseña
  ├─ aceptación obligatoria de privacidad y términos
  └─ decisión opcional de marketing
        ↓
auth-service / monolito
  ├─ users (cuenta y hash de contraseña)
  ├─ user_consents (tipo, versión, fecha, usuario)
  └─ sessions (sesión autenticada)
        ↓
Catálogo y compra de demostración
  ├─ raffles / raffle_numbers (lectura y reserva)
  ├─ orders / order_items (orden y números)
  └─ payments / payment_webhook_events (gateway simulado o sandbox)
        ↓
Notificaciones y administración
  ├─ notifications (correo y cuerpo)
  ├─ raffle_results (resultado manual)
  └─ logs técnicos
```

## 5. Finalidades y base de legitimación

| Finalidad | Base principal | Observación |
|---|---|---|
| Crear y autenticar la cuenta | Autorización del titular y ejecución de la relación | El registro exige aceptar la política |
| Gestionar la compra de demostración | Ejecución de la relación y autorización | No hay venta real ni pago real |
| Verificar pagos y conciliar | Ejecución de la relación, seguridad y auditoría | El proveedor procesa el pago |
| Enviar confirmaciones e incidencias | Ejecución de la relación | El correo es necesario para la operación |
| Seguridad y prevención de abuso | Interés legítimo y obligaciones de seguridad | Debe documentarse el juicio de proporcionalidad |
| Cumplir obligaciones legales | Obligación legal | Cuando resulte aplicable |
| Comunicaciones promocionales | Autorización opcional separada | No se envían en el prototipo |

La categoría “interés legítimo” no está desarrollada en la Ley 1581 con la
misma estructura que el RGPD europeo; cualquier uso debe analizarse con la
norma colombiana y la revisión jurídica. Este documento no crea una base legal
nueva.

## 6. Comunicaciones y terceros

| Destinatario | Datos | Rol | Estado contractual | Observación |
|---|---|---|---|---|
| Mercado Pago (Checkout Pro sandbox) | Identificadores de pago, estado, monto, URL | Encargado o proveedor | Pendiente | No se ha validado producción ni transferencia internacional |
| Proveedor SMTP / Mailtrap (P2) | Correo, nombre, contenido de la orden | Encargado | Pendiente | En local se usa el mailer `log`; no hay correo real |
| Infraestructura AWS / hosting (P3) | Todos los datos alojados | Encargado | Pendiente | No se creó ningún recurso AWS |
| Equipo de desarrollo y administración | Acceso técnico | Personal autorizado | Pendiente de acuerdos | Debe limitarse por rol y registrar accesos |
| Autoridades públicas | Datos requeridos por ley | Responsable / tercero | No aplica todavía | Solo ante requerimiento legal |

No se venden datos personales. No se comparten con anunciantes. No se ha
definido una transferencia internacional ni se ha suscrito un contrato de
transmisión.

## 7. Conservación

| Categoría | Estado actual | Propuesta pendiente |
|---|---|---|
| Cuenta (`users`) | Se conserva mientras exista la cuenta; no hay eliminación implementada | Definir plazo y procedimiento de supresión |
| Consentimiento (`user_consents`) | Se conserva como evidencia; no hay purga | Conservar mientras se trate el dato y durante el plazo de prueba exigible |
| Órdenes y pagos | Se conservan para conciliación y auditoría | Definir plazo legal/contractual y archivo |
| Webhooks | Se conservan para idempotencia | Definir plazo y sanitización |
| Notificaciones | Se conservan con estado y cuerpo | Definir plazo y minimización del cuerpo |
| Logs | Retención dependiente del contenedor/host | Configurar rotación y plazo |
| Datos de demo | Pueden reiniciarse | No usar como archivo personal |

El prototipo no tiene una tabla de retención aprobada ni un proceso automático
de eliminación. Es una limitación, no una autorización para conservar todo
indefinidamente.

## 8. Seguridad observada

Controles presentes:

- contraseñas con hash (bcrypt de Laravel);
- sesiones autenticadas y CSRF;
- autorización de administrador en el backend;
- base de datos sin puerto público;
- secretos en variables de entorno;
- separación de usuarios MySQL por servicio;
- bloqueo de filas y restricciones únicas para evitar doble venta;
- webhooks idempotentes y verificación del pago en el servidor;
- tiempos de espera y reintentos limitados.

Brechas o controles no verificados:

- cifrado en reposo y gestión formal de llaves;
- monitoreo y alertas centralizadas;
- plan de respuesta a incidentes y notificación de brechas;
- registro de accesos administrativos;
- pruebas de penetración;
- contratos de encargo y cláusulas de seguridad;
- política de retención y borrado;
- verificación de edad e identidad.

## 9. Riesgos identificados

| Riesgo | Impacto | Estado | Mitigación propuesta |
|---|---|---|---|
| No hay Responsable ni canal de derechos definido | Alto | Abierto | Designar responsable y publicar canal antes de datos reales |
| `user_consents` en `rifacloud` | Medio | Resuelto (2026-11-12) | Migración y grant aplicados; registro temporal verificado y eliminado |
| No hay eliminación/corrección de cuenta | Alto | Abierto | Implementar procedimiento y endpoints con verificación de identidad |
| No hay verificación de edad | Alto para rifas reales | Abierto | Diseñar control y validar con abogado |
| Proveedores sin contrato de encargo | Alto | Abierto | Suscribir contratos y evaluar transferencias |
| Retención indefinida | Medio | Abierto | Aprobar tabla de retención |
| Logs con IP y agente de usuario | Medio | Parcial | Rotación, acceso restringido y minimización |
| Correos con nombre y orden | Medio | Parcial | Limitar contenido y asegurar el proveedor |
| Resultado manual sin GNA | Alto para rifas reales | Abierto | Integrar mecanismo autorizado y evidencia verificable |

## 10. Conclusión

El prototipo trata datos identificables y transaccionales suficientes para
requerir una política, aviso, autorización y controles de derechos. La
implementación S11 cubre la autorización informada y su evidencia, pero no
completa el ciclo de vida ni la designación de responsables. Cualquier uso con
personas reales exige cerrar los riesgos de las secciones 8 y 9 y obtener
revisión jurídica.
