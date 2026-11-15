# Revisión de los documentos públicos — 2026-11-15

**Uso:** documento interno de revisión. No es una política publicada ni asesoría jurídica.
**Estado:** revisión editorial de los textos públicos del prototipo.
**Contacto del proyecto:** Equipo de RifaCloud — `rifacloud27@gmail.com`.

## 1. Qué se actualizó

- Se reescribieron los Términos y Condiciones y la Política de Tratamiento de
  Datos Personales en un lenguaje más claro y breve.
- Se retiraron de las páginas públicas las etiquetas «Borrador»,
  «Documento preliminar», los marcadores entre corchetes y las notas internas
  de desarrollo.
- Se eliminó el consentimiento promocional de la interfaz y del flujo de
  registro. El API conserva el campo `marketing_consent` por compatibilidad y
  el frontend envía `false`.
- Se identificó al **Equipo de RifaCloud** y a `rifacloud27@gmail.com` como
  equipo y canal de contacto, sin afirmar que exista una persona jurídica.
- Se reconoció el uso de AWS para alojamiento y respaldos, y de Mercado Pago
  Checkout Pro en modo sandbox para pagos de demostración.
- Se retiró «Alcance regulatorio» del pie y del registro. La ruta anterior se
  conserva por compatibilidad, sin promoción pública.
- La versión pública vigente es `2026-11-15`. Los borradores internos de
  `docs/legal/` conservan su versión histórica y siguen siendo material de
  trabajo, no textos publicados.

## 2. Límites de esta revisión

Los textos públicos no afirman cumplimiento normativo total, autorización de
Coljuegos, constitución de empresa, NIT, domicilio, contratos con proveedores ni
garantías absolutas de seguridad. No se inventaron datos del responsable.

## 3. Asuntos que requieren verificación jurídica antes de una operación real

1. Identificación legal del responsable del tratamiento: persona natural o
   jurídica, NIT/documento, domicilio y designación formal.
2. Registro Nacional de Bases de Datos (RNBD), si aplica según los umbrales
   legales.
3. Contratos de encargo y condiciones con AWS y Mercado Pago, incluida la
   ubicación del tratamiento y las transferencias internacionales.
4. Política de retención por categoría, backups y eliminación segura.
5. Verificación de edad e identidad, especialmente por la prohibición de
   participar en juegos de suerte y azar a menores.
6. Autorización de Coljuegos, operador autorizado, registro de la rifa, plan de
   premios y derechos de explotación para cualquier operación comercial.
7. Información al consumidor, canal de reclamaciones y condiciones comerciales
   aplicables (Ley 1480 de 2011), si el prototipo dejara de ser demostrativo.
8. Procedimiento interno para consultas, reclamos, revocatoria y supresión:
   registro, trazabilidad y plazos de respuesta.
9. Seguridad: gestión de llaves, cifrado en reposo, monitoreo, respuesta a
   incidentes y pruebas de seguridad.
10. Mecanismo de actualización de la versión legal y nueva aceptación para
    usuarios existentes cuando cambie el contenido de forma sustancial.
11. Uso de cookies y tecnologías de seguimiento: el prototipo solo declara
    cookies técnicas de sesión y CSRF; cualquier analítica futura requiere
    revisión y, cuando corresponda, consentimiento.
12. Revisión de que los textos internos de `docs/legal/` y la documentación SDD
    no contradigan los textos publicados.

## 4. Nota de despliegue

La versión pública `2026-11-15` debe coincidir con `LEGAL_CONSENT_VERSION` en el
entorno del backend antes de publicar el frontend actualizado. Si el entorno de
producción conserva `2026-11-14`, el registro rechazará la nueva versión. El
cambio de variable de entorno debe coordinarse con el despliegue, sin editar
`.env` en el repositorio.

## 5. Conclusión

La actualización mejora la claridad y evita presentar el prototipo como una
operación comercial autorizada. No sustituye la revisión jurídica humana ni
acredita el cumplimiento total de la Ley 1581 de 2012 o de la normativa de
juegos de suerte y azar.
