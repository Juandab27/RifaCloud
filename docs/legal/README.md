# Documentación legal y de privacidad — S11

**Proyecto:** RifaCloud
**Estado:** Borradores académicos para revisión jurídica humana
**Versión de los documentos:** `2026-11-11`
**Fecha de actualización del índice:** 2026-11-12

> **Aviso importante**
>
> Estos documentos son borradores elaborados para un prototipo universitario.
> No constituyen asesoría jurídica definitiva, no acreditan el cumplimiento
> normativo y no autorizan a RifaCloud para operar rifas reales. RifaCloud no
> afirma tener autorización de Coljuegos ni de ninguna otra autoridad.
>
> Los textos entre corchetes son marcadores pendientes. No se debe inventar una
> razón social, NIT, domicilio, correo, teléfono o responsable. Antes de
> cualquier publicación o uso comercial, un abogado debe completar y validar
> cada documento con las fuentes oficiales vigentes.

## 1. Documentos

| Documento | Contenido | Uso previsto |
|---|---|---|
| [01-inventario-tratamiento-datos.md](01-inventario-tratamiento-datos.md) | Qué datos personales se recopilan, dónde se guardan, cómo se usan y con quién se comunican | Base interna para la política y el análisis de riesgos |
| [02-politica-tratamiento-datos-personales.md](02-politica-tratamiento-datos-personales.md) | Borrador de Política de Tratamiento de Datos Personales | Publicación futura y revisión legal |
| [03-aviso-privacidad.md](03-aviso-privacidad.md) | Borrador de aviso de privacidad breve | Acompañar la recolección de datos |
| [04-terminos-condiciones-prototipo.md](04-terminos-condiciones-prototipo.md) | Términos y Condiciones del prototipo | Publicación futura y revisión legal |
| [05-alcance-regulatorio-rifas-colombia.md](05-alcance-regulatorio-rifas-colombia.md) | Alcance de la Ley 643 de 2001 y el Decreto 1486 de 2024 | Evitar presentar el prototipo como operación autorizada |
| [06-derechos-titular-y-limitaciones.md](06-derechos-titular-y-limitaciones.md) | Consulta, corrección, eliminación y revocatoria: lo implementado y lo pendiente | Plan de atención al titular |
| [07-requisitos-lanzamiento-comercial.md](07-requisitos-lanzamiento-comercial.md) | Requisitos pendientes para operar rifas comerciales reales | Lista de verificación antes de un lanzamiento |
| [08-fuentes-oficiales.md](08-fuentes-oficiales.md) | Normas, enlaces oficiales y fecha de consulta | Trazabilidad y revalidación |

## 2. Relación con el SDD

- Los requisitos funcionales y no funcionales siguen siendo los del
  `docs/sdd/`. Este trabajo no cambia reservas, pagos, reglas de sorteo ni la
  arquitectura.
- La autorización informada se implementa como un control de registro y se
  registra en la tabla `user_consents` (migración aditiva
  `2026_11_11_000011_create_user_consents_table.php`).
- La migración se aplicó a `rifacloud_test` y, el 2026-11-12 y con autorización
  explícita, a la base principal `rifacloud`. El grant de `user_consents` para
  `rifacloud_auth` quedó aplicado y verificado.
- Un registro temporal por API verificó las filas `PRIVACY`, `TERMS` y
  `MARKETING`; después se eliminaron el usuario, sus sesiones y la evidencia.
  La base principal quedó sin datos de prueba.
- Las preguntas legales siguen abiertas en `docs/sdd/06-open-questions.md`
  (Q-01, Q-02 y Q-21 a Q-25). Estos borradores reducen el vacío de
  contenido, pero no cierran esas preguntas ni sustituyen la revisión jurídica.

## 3. Marcadores que deben completarse

| Marcador | Dónde aparece | Quién debe definirlo |
|---|---|---|
| `[RESPONSABLE PENDIENTE]` | Política, aviso, términos | Futuro operador o institución |
| `[NIT PENDIENTE]` | Política | Futuro responsable |
| `[DOMICILIO PENDIENTE]` | Política | Futuro responsable |
| `[DIRECCIÓN PENDIENTE]` | Política | Futuro responsable |
| `[CORREO PENDIENTE]` / `[TELÉFONO PENDIENTE]` | Política, aviso, términos | Futuro responsable |
| `[CANAL DE ATENCIÓN PENDIENTE]` | Política, derechos del titular | Futuro responsable |
| `[RESPONSABLE DE PROTECCIÓN DE DATOS PENDIENTE]` | Política | Futuro responsable |
| `[PROVEEDOR DE CORREO PENDIENTE]` | Política, inventario | Futuro responsable |
| `[INFRAESTRUCTURA PENDIENTE]` | Política, inventario | Futuro responsable |

## 4. Estado de evidencias

- **Implementado y probado en `rifacloud_test` y `rifacloud`:** consentimiento
  obligatorio de privacidad y términos, consentimiento promocional opcional
  separado, registro de tipo, versión, fecha y usuario en `user_consents` (4/4
  pruebas de feature, 6/6 pruebas de navegador, 19/19 comprobaciones de
  auth-service y un registro temporal controlado en la base principal con
  limpieza posterior).
- **Pendiente:** definir el canal de derechos, designar responsable, revisar
  jurídicamente los textos y decidir el re-consentimiento/backfill de los
  usuarios creados antes de S11. La migración y los permisos de `auth-service`
  ya están aplicados y verificados.
- **No implementado:** eliminación de cuenta, corrección de perfil, revocatoria
  desde la interfaz, exportación de datos, verificación de edad e identidad,
  Registro Nacional de Bases de Datos y contratos con encargados.

## 5. Regla de uso

Mientras estos documentos sean borradores:

1. No presentarlos como política definitiva ni como prueba de cumplimiento.
2. No afirmar que RifaCloud está autorizado por Coljuegos.
3. No recibir pagos reales ni promocionar rifas reales.
4. No usar datos de personas reales fuera del entorno académico controlado.
5. Mantener visible el aviso de prototipo en la interfaz.
