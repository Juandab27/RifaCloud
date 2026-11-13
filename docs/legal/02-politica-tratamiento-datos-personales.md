# 02 — Política de Tratamiento de Datos Personales (borrador)

**Proyecto:** RifaCloud
**Versión:** 2026-11-11
**Estado:** Borrador para revisión jurídica humana
**Base normativa de referencia:** Ley 1581 de 2012 y Decreto 1074 de 2015
**Ámbito:** prototipo académico; no operación comercial autorizada

> **Advertencia**
>
> Este documento no es asesoría jurídica definitiva. No afirma que RifaCloud
> cumpla la Ley 1581 de 2012 ni que esté autorizado para operar rifas. Los
> marcadores entre corchetes deben completarse con información verificable. No
> se debe inventar una empresa, NIT, dirección, correo o responsable.

## 1. Identificación del Responsable del Tratamiento

| Campo | Valor |
|---|---|
| Nombre o razón social | `[RESPONSABLE PENDIENTE]` |
| NIT o identificación | `[NIT PENDIENTE]` |
| Domicilio | `[DOMICILIO PENDIENTE]` |
| Dirección física o electrónica | `[DIRECCIÓN PENDIENTE]` |
| Correo electrónico | `[CORREO PENDIENTE]` |
| Teléfono | `[TELÉFONO PENDIENTE]` |
| Persona o área de protección de datos | `[RESPONSABLE DE PROTECCIÓN DE DATOS PENDIENTE]` |

Mientras estos datos no estén definidos, RifaCloud es un prototipo académico y
no debe recibir datos personales de personas reales fuera de un entorno
controlado.

## 2. Encargados del Tratamiento

| Encargado | Actividad | Datos | Estado |
|---|---|---|---|
| Mercado Pago | Procesamiento de pagos en sandbox | Identificadores, estado, monto, URL | Contrato y condiciones pendientes |
| Proveedor de correo `[PENDIENTE]` | Entrega de notificaciones | Correo, nombre, orden | Pendiente (P2) |
| Infraestructura `[PENDIENTE]` | Hosting, base de datos y respaldos | Todos los datos | Pendiente (P3) |
| Equipo de desarrollo y administración | Soporte y operación | Acceso técnico | Acuerdos y roles pendientes |

Los encargados tratarán los datos únicamente conforme a las instrucciones del
Responsable y a las obligaciones de seguridad y confidencialidad aplicables.

## 3. Marco normativo

Esta política se elabora tomando como referencia:

- Constitución Política, artículo 15 (intimidad y hábeas data).
- Ley Estatutaria 1581 de 2012, régimen general de protección de datos
  personales.
- Decreto 1074 de 2015, Decreto Único Reglamentario del Sector Comercio,
  Industria y Turismo, que compila el Decreto 1377 de 2013 y el Decreto 886 de
  2014 (autorización, aviso, políticas, derechos y Registro Nacional de Bases de
  Datos).
- Ley 643 de 2001 y Decreto 1486 de 2024, en lo relativo a la naturaleza de las
  rifas y a las obligaciones de una operación autorizada.
- Ley 1480 de 2011, Estatuto del Consumidor, en lo relativo a información y
  protección del consumidor.

La vigencia y el alcance de estas normas deben revalidarse con fuentes
oficiales antes de publicar una versión definitiva.

## 4. Definiciones

- **Autorización:** consentimiento previo, expreso e informado del titular.
- **Base de datos:** conjunto organizado de datos personales objeto de
  tratamiento.
- **Dato personal:** información vinculada o asociable a una persona natural
  determinada o determinable.
- **Dato sensible:** dato que afecta la intimidad o cuyo uso indebido puede
  generar discriminación.
- **Encargado:** persona que trata datos por cuenta del Responsable.
- **Responsable:** persona que decide sobre la base de datos y el tratamiento.
- **Titular:** persona natural cuyos datos son tratados.
- **Tratamiento:** recolección, almacenamiento, uso, circulación o supresión.
- **Transferencia:** envío de datos a un receptor que actúa como Responsable.
- **Transmisión:** comunicación de datos a un encargado para que trate por
  cuenta del Responsable.

## 5. Principios

RifaCloud aplicará, en la medida de sus posibilidades y del estado del
prototipo, los principios de legalidad, finalidad, libertad, veracidad o
calidad, transparencia, acceso y circulación restringida, seguridad y
confidencialidad.

La aplicación de estos principios no se presume por la existencia de este
documento: requiere controles verificables y revisión jurídica.

## 6. Datos tratados y finalidades

| Categoría | Datos | Finalidad | Obligatorio |
|---|---|---|---|
| Identificación y contacto | Nombre, correo, teléfono | Cuenta, autenticación, contacto y notificaciones | Sí |
| Credenciales | Hash de contraseña | Autenticación | Sí |
| Sesión | Identificador, IP, agente de usuario, actividad | Seguridad y continuidad de la sesión | Sí, por funcionamiento técnico |
| Control de abuso | Claves de límite de intentos y bloqueos de caché | Limitar registro/login y coordinar workers | Sí, por funcionamiento técnico |
| Compras de demostración | Orden, rifa, números, montos, estados | Reserva, compra simulada, historial y conciliación | Sí, para usar la función |
| Pagos de prueba | Identificadores, estado, monto, URL | Verificación y conciliación | Sí, para usar la función |
| Webhooks | Identificador, tipo, carga sanitizada | Idempotencia y auditoría | Sí, por funcionamiento técnico |
| Notificaciones | Correo, nombre, contenido de la orden | Confirmaciones e incidencias | Sí, para la operación |
| Consentimiento | Tipo, versión, decisión, fecha, usuario | Evidencia de autorización | Sí para privacidad y términos; opcional para marketing |
| Resultados | Número, administrador, evidencia | Resultado de demostración | No aplica al comprador |
| Logs | Ruta, fecha, IP, agente de usuario, errores | Seguridad y diagnóstico | Sí, por funcionamiento técnico |

Las finalidades se limitan a las descritas. No se usarán para publicidad de
terceros ni para decisiones automatizadas con efectos jurídicos.

## 7. Autorización

La autorización se solicita al momento del registro mediante dos casillas
obligatorias e independientes:

1. Política de Tratamiento de Datos Personales y aviso de privacidad.
2. Términos y Condiciones del prototipo.

Sin ambas no se crea la cuenta. La aceptación se registra con el tipo de
documento, la versión, la fecha y el usuario en `user_consents`.

El consentimiento para comunicaciones promocionales es una tercera casilla,
opcional, desmarcada por defecto y no condiciona el registro. Su decisión se
registra por separado.

La autorización puede manifestarse por escrito, de forma oral o mediante
conductas inequívocas; el silencio no equivale a autorización. En el prototipo
se usa una manifestación electrónica expresa.

## 8. Datos sensibles y menores de edad

RifaCloud no solicita datos sensibles. Si llegara a solicitarlos, informaría
que la respuesta es facultativa y obtendría autorización expresa, conforme al
artículo 6 de la Ley 1581 de 2012.

El tratamiento de datos de niños, niñas y adolescentes está proscrito salvo las
excepciones legales. El prototipo no está dirigido a menores, pero no cuenta
con verificación de edad. La Ley 643 de 2001 prohíbe ofrecer o vender juegos de
suerte y azar a menores. Cerrar esa brecha es un requisito para cualquier
operación real.

## 9. Derechos del titular

El titular tiene derecho a:

1. Conocer, actualizar y rectificar sus datos.
2. Solicitar prueba de la autorización.
3. Ser informado sobre el uso dado a sus datos.
4. Presentar quejas ante la Superintendencia de Industria y Comercio.
5. Revocar la autorización o solicitar la supresión cuando no exista un deber
   legal o contractual de conservación.
6. Acceder gratuitamente a sus datos.

## 10. Procedimiento para consultas y reclamos

El canal definitivo está pendiente: `[CANAL DE ATENCIÓN PENDIENTE]`.

Procedimiento previsto:

1. **Recepción:** el titular presenta la solicitud con identificación suficiente
   y descripción de los hechos o datos involucrados.
2. **Subsanación:** si está incompleta, se le requiere dentro de los cinco (5)
   días siguientes. Transcurridos dos (2) meses sin respuesta, se entiende
   desistimiento.
3. **Consulta:** se atiende en máximo diez (10) días hábiles. Si no es posible,
   se informa el motivo y la fecha, que no puede superar cinco (5) días hábiles
   adicionales.
4. **Reclamo:** se atiende en máximo quince (15) días hábiles. Si no es posible,
   se informa el motivo y la fecha, que no puede superar ocho (8) días hábiles
   adicionales.
5. **Constancia:** se registra la solicitud, la decisión y, cuando proceda, la
   actualización, rectificación o supresión.

Mientras el canal no exista, no se debe prometer atención formal ni usar el
correo de una persona del equipo como canal oficial.

## 11. Conservación

Los datos se conservarán durante el tiempo razonable y necesario para las
finalidades y para atender obligaciones legales, contractuales, contables,
fiscales o de defensa. Las órdenes, pagos y webhooks se conservan para
conciliación y auditoría.

El prototipo no tiene una tabla de retención aprobada ni un proceso automático
de eliminación. Antes de una operación real se debe aprobar:

- plazo por categoría;
- destino al vencer (eliminación, anonimización o archivo);
- excepciones legales;
- tratamiento de la evidencia de consentimiento;
- procedimiento de borrado seguro y respaldos.

## 12. Seguridad

Medidas presentes en el prototipo:

- hash de contraseñas;
- sesiones autenticadas y CSRF;
- autorización de administrador en backend;
- base de datos no expuesta públicamente;
- secretos en variables de entorno;
- separación de usuarios MySQL;
- bloqueos y restricciones para evitar doble venta;
- verificación del pago en servidor;
- webhooks idempotentes;
- tiempos de espera y reintentos limitados.

En el entorno local, el mailer `log` escribe el contenido del correo en los
registros; no debe usarse con datos reales. Los registros deben rotarse y
restringirse.

Medidas pendientes:

- cifrado en reposo y gestión de llaves;
- monitoreo y alertas;
- plan de respuesta a incidentes y notificación de brechas;
- registro de accesos administrativos;
- pruebas de seguridad;
- contratos de encargo;
- retención y borrado;
- verificación de edad e identidad.

## 13. Transferencias y transmisiones

No se ha definido si existirán transferencias o transmisiones
internacionales. Antes de contratar un proveedor se debe verificar:

- ubicación del proveedor y de sus subencargados;
- contrato de transmisión y obligaciones de seguridad;
- nivel adecuado de protección o autorización del titular, cuando aplique;
- declaración de conformidad de la Superintendencia de Industria y Comercio,
  cuando corresponda.

No se afirma que exista una transferencia autorizada.

## 14. Cookies y sesión

Se usan cookies técnicas de sesión y CSRF. No se identificaron herramientas de
analítica, publicidad o seguimiento de terceros. Si se incorporan, se informará
y se obtendrá el consentimiento cuando sea necesario.

## 15. Registro Nacional de Bases de Datos

La inscripción en el RNBD se evaluará cuando exista un Responsable definido y se
determine si se superan los umbrales del artículo 2.2.2.26.1.2 del Decreto 1074
de 2015 (por ejemplo, sociedades y entidades sin ánimo de lucro con activos
totales superiores a 100.000 UVT). No se afirma que el registro esté realizado.

## 16. Vigencia y cambios

Esta es la versión **2026-11-11** de un borrador. La versión definitiva debe
indicar fecha de entrada en vigencia y período de vigencia de la base de datos
(`[PERÍODO DE VIGENCIA PENDIENTE]`). Los cambios sustanciales se comunicarán
antes de implementarse. Si cambia la finalidad, se solicitará una nueva
autorización.

## 17. Contacto

- Responsable: `[RESPONSABLE PENDIENTE]`
- Correo: `[CORREO PENDIENTE]`
- Teléfono: `[TELÉFONO PENDIENTE]`
- Protección de datos: `[RESPONSABLE DE PROTECCIÓN DE DATOS PENDIENTE]`
- Canal de consultas y reclamos: `[CANAL DE ATENCIÓN PENDIENTE]`

## 18. Limitación

Este documento es un borrador académico. No constituye asesoría jurídica, no
acredita cumplimiento y no autoriza la operación de rifas reales. Debe ser
revisado, completado y aprobado por un abogado antes de cualquier publicación o
uso con datos de personas reales.
