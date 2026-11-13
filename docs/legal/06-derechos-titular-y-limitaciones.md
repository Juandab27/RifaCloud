# 06 — Derechos del titular: mecanismos y limitaciones

**Proyecto:** RifaCloud
**Versión:** 2026-11-11
**Estado:** Diagnóstico del prototipo y propuesta para revisión jurídica

> Este documento describe lo que el prototipo permite hoy. No presenta como
> implementado lo que solo está propuesto.

## 1. Derechos que deben poder ejercerse

Conforme a la Ley 1581 de 2012 y al Decreto 1074 de 2015, el titular debe poder:

1. **Conocer** qué datos existen y cómo se usan.
2. **Actualizar** datos desactualizados.
3. **Rectificar** datos inexactos o incompletos.
4. **Suprimir** datos cuando no exista deber legal o contractual de conservarlos.
5. **Revocar** la autorización.
6. **Solicitar prueba** de la autorización.
7. **Presentar quejas** ante la Superintendencia de Industria y Comercio.
8. **Acceder gratuitamente** a sus datos.

## 2. Qué está implementado

### 2.1 Consulta del perfil

`GET /api/me` devuelve, para el usuario autenticado:

- `id`
- `name`
- `email`
- `phone`
- `role`

La consulta requiere sesión. Un visitante anónimo recibe `401`; un error de
disponibilidad se trata como `503`, no como cierre de sesión.

### 2.2 Consulta de compras

`GET /api/orders` y `GET /api/orders/{id}` permiten al usuario autenticado ver
sus propias órdenes. La consulta está limitada al propietario en el backend: un
usuario no puede leer la orden de otro (`404`). Esto permite conocer los datos
transaccionales asociados a la cuenta.

### 2.3 Evidencia de consentimiento

El registro exige dos aceptaciones obligatorias y una decisión promocional
opcional. La tabla `user_consents` guarda:

| Campo | Contenido |
|---|---|
| `user_id` | Usuario que aceptó |
| `type` | `PRIVACY`, `TERMS` o `MARKETING` |
| `document_version` | Versión exacta del documento |
| `accepted` | Decisión (verdadero/falso) |
| `accepted_at` | Fecha y hora |
| `source` | Origen (`REGISTRATION`) |

No se almacenan IP ni agente de usuario en esta tabla, para minimizar datos.

La grabación es idempotente por la clave única `(user_id, type, document_version)`.
Si en el futuro se permite cambiar una decisión sin cambiar la versión del
documento, se necesitará un nuevo evento o una actualización explícita; hoy no
existe esa interfaz y no se debe reutilizar el registro como si probara una
decisión posterior.

### 2.4 Separación del consentimiento promocional

La casilla promocional está desmarcada por defecto, es opcional y se registra
como fila independiente. No se condiciona el registro a aceptarla. En el
prototipo no se envían campañas promocionales.

## 3. Qué no está implementado

| Función | Estado | Consecuencia |
|---|---|---|
| Actualizar nombre, correo o teléfono desde la interfaz | No existe | El titular no puede corregir sus datos sin intervención técnica |
| Eliminar la cuenta | No existe | No hay autoservicio de supresión |
| Revocar el consentimiento desde la interfaz | No existe | Requiere un procedimiento manual futuro |
| Descargar o exportar datos | No existe | No hay portabilidad ni copia autoservicio |
| Ver la versión aceptada en la interfaz | No existe | La evidencia está en base de datos, no en pantalla |
| Canal formal de consultas y reclamos | No definido | No se puede prometer atención con plazos legales |
| Verificación de identidad del solicitante | No existe | Un procedimiento manual necesitaría pruebas de identidad |
| Registro de “reclamo en trámite” | No existe | No hay flujo de reclamación implementado |
| Eliminación segura y respaldos | No existe | No hay política de retención aprobada |
| Registro Nacional de Bases de Datos | No evaluado | No se afirma inscripción |

## 4. Limitaciones específicas del prototipo

### 4.1 Sin canal oficial

No hay correo, formulario o área designada. Usar un correo personal del equipo
como canal oficial sería una decisión no autorizada y no verificable. El texto
público usa `[CANAL DE ATENCIÓN PENDIENTE]`.

### 4.2 Sin verificación de identidad

Para atender una solicitud de acceso, corrección o supresión se debe verificar
que quien la pide es el titular o su representante. El prototipo no tiene un
flujo para hacerlo. Una respuesta basada solo en el correo electrónico sería
débil.

### 4.3 Sin eliminación por obligaciones de conservación

Las órdenes, pagos y webhooks se conservan para conciliación y auditoría. La
supresión de la cuenta no puede borrar automáticamente esos registros si existe
un deber legal o contractual. Se necesita una regla de retención y un
procedimiento que distinga:

- datos de cuenta que pueden suprimirse;
- evidencia de consentimiento que debe conservarse;
- datos transaccionales con plazo legal o contractual;
- datos agregados o anonimizados.

### 4.4 Sin revocatoria automática del marketing

El prototipo no envía marketing. Si en el futuro lo hace, debe existir un enlace
o mecanismo de baja y un registro de la revocatoria. La fila `MARKETING` actual
solo prueba la decisión inicial.

### 4.5 Sin actualización de documentos para usuarios existentes

La versión `2026-11-11` se exige en nuevos registros. Los usuarios creados antes
de S11 no tienen filas en `user_consents` porque la tabla no existía; no se
inventó un consentimiento retroactivo. Un re-consentimiento o backfill requiere
decisión jurídica y un flujo de comunicación. Si los documentos cambian, los
usuarios existentes tampoco son re-consentidos automáticamente.

### 4.6 Sin eliminación en cascada segura

La tabla `user_consents` usa `restrictOnDelete` sobre `users`. Si se implementa
la eliminación de usuarios, primero debe decidirse qué evidencia se conserva y
cómo se anonimiza. No se debe borrar la prueba de autorización sin análisis.

## 5. Procedimiento propuesto (no implementado)

Este procedimiento sirve como borrador para la futura política:

1. **Canal:** habilitar un correo o formulario oficial
   (`[CANAL DE ATENCIÓN PENDIENTE]`) con constancia de recibo.
2. **Identificación:** solicitar datos suficientes para verificar identidad y,
   cuando aplique, representación.
3. **Registro:** asignar un número de caso y dejar constancia de la fecha.
4. **Subsanación:** si falta información, requerir dentro de los cinco (5) días
   siguientes.
5. **Consulta:** responder en máximo diez (10) días hábiles, prorrogables por
   cinco (5) días hábiles.
6. **Reclamo:** responder en máximo quince (15) días hábiles, prorrogables por
   ocho (8) días hábiles.
7. **Decisión:** actualizar, rectificar, suprimir o explicar la negativa con
   fundamento legal.
8. **Evidencia:** conservar la solicitud, la verificación, la decisión y la
   comunicación.
9. **Escalamiento:** informar que el titular puede acudir a la Superintendencia
   de Industria y Comercio después de agotar el trámite.

Los términos son los de referencia legal; deben confirmarse con la revisión
jurídica y con la norma vigente al momento de implementar.

## 6. Cómo verificar la evidencia hoy

En un entorno controlado, un administrador autorizado puede consultar:

```sql
SELECT user_id, type, document_version, accepted, accepted_at, source
FROM user_consents
ORDER BY user_id, type;
```

Esto no es un canal para titulares. Es una consulta interna de verificación. No
se debe exponer la tabla ni usar datos de producción en pruebas.

## 7. Pruebas realizadas

- `Tests\Feature\Auth\ConsentTest` verifica:
  - rechazo del registro sin las dos aceptaciones obligatorias;
  - rechazo de una versión de documento no vigente;
  - registro de `PRIVACY`, `TERMS` y `MARKETING` por separado;
  - versión, fecha y usuario en la evidencia;
  - decisión promocional verdadera o falsa sin mezclarse con las obligatorias.
- La prueba de navegador `legal.spec.ts` verifica:
  - ausencia de franja global y presencia de enlaces legales en el pie;
  - acceso público a privacidad, términos y alcance regulatorio;
  - casillas obligatorias y promocional separada;
  - envío de `consent_version` y de las tres decisiones;
  - aclaración contextual del checkout que distingue gateway simulado de
    Mercado Pago sandbox;
  - adaptación a móvil sin desbordamiento.
- `scripts/verify-auth-service-integration.ps1` verifica, contra
  `rifacloud_test`, que `auth-service` registra las filas `PRIVACY`, `TERMS` y
  `MARKETING` con la versión `2026-11-11`, la decisión y la fecha (19/19
  comprobaciones).
- En `rifacloud`, un registro temporal por API verificó las mismas tres filas
  (`PRIVACY`=1, `TERMS`=1, `MARKETING`=0) y luego se eliminaron usuario,
  sesiones y evidencia. La base principal quedó sin datos de prueba.

## 8. Recomendaciones antes de datos reales

1. Designar Responsable y responsable de protección de datos.
2. Publicar un canal oficial y un formulario con constancia.
3. Implementar consulta, corrección, supresión y revocatoria con verificación de
   identidad.
4. Aprobar la tabla de retención y el tratamiento de la evidencia.
5. Definir el flujo de reclamaciones y los plazos.
6. Evaluar el RNBD y, si aplica, inscribir las bases.
7. Documentar contratos con encargados y transferencias.
8. Probar el procedimiento con datos ficticios antes de usarlo con personas
   reales.

## 9. Limitación

Este documento es un diagnóstico académico. No es asesoría jurídica ni una
certificación de cumplimiento. Las funciones descritas como pendientes no deben
presentarse como disponibles.
