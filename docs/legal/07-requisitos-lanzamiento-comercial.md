# 07 — Requisitos para un eventual lanzamiento comercial

**Proyecto:** RifaCloud
**Versión:** 2026-11-11
**Estado:** Lista de verificación; no implica que los requisitos estén cumplidos

> Nada de esta lista autoriza a RifaCloud a operar rifas reales. Es una guía
> para revisión jurídica, operativa y técnica antes de un lanzamiento.

## 1. Identidad legal y gobierno

- [ ] Definir la persona natural o jurídica que será Responsable del Tratamiento.
- [ ] Definir NIT, domicilio, correo, teléfono y representante legal.
- [ ] Designar responsable de protección de datos y canal de atención.
- [ ] Aprobar políticas internas, roles y matriz de responsabilidades.
- [ ] Definir quién responde por premios, reembolsos y reclamaciones.
- [ ] Documentar la titularidad del software y los acuerdos con la universidad.
- [ ] Aprobar un presupuesto para operación, garantías, seguros y soporte.

## 2. Habilitación para operar rifas

- [ ] Contratar con un operador autorizado o definir por escrito el rol de
      RifaCloud (proveedor tecnológico, gestor u otro).
- [ ] Verificar la autorización del operador y su alcance.
- [ ] Registrar cada rifa ante el operador.
- [ ] Presentar la solicitud ante la autoridad competente con al menos 30 días
      calendario.
- [ ] Aprobar el plan de premios (mínimo 40 % del valor de la emisión).
- [ ] Acreditar propiedad, avalúo o facturas de los premios.
- [ ] Constituir garantía de cumplimiento por el valor del plan de premios.
- [ ] Definir el mecanismo de sorteo: lotería tradicional autorizada o GNA
      validado.
- [ ] Integrar el registro de boletas y los reportes previos al sorteo.
- [ ] Implementar el pago del 14 % de derechos de explotación y su ajuste.
- [ ] Definir el procedimiento de entrega y verificación del premio.
- [ ] Prohibir rifas permanentes y respetar el máximo de dos fechas al mes.
- [ ] Prohibir premios en dinero y bienes usados.
- [ ] Implementar boletas electrónicas o terminales de venta según la regla
      aplicable.

## 3. Transparencia del sorteo

- [ ] Publicar reglas completas, probabilidad y condiciones.
- [ ] Informar fecha, hora, lugar y mecanismo del sorteo.
- [ ] Publicar la autorización y el operador responsable.
- [ ] Implementar un GNA certificado o la integración con la lotería.
- [ ] Generar acta y evidencia verificable del resultado.
- [ ] Definir el procedimiento de aplazamiento y comunicación.
- [ ] Publicar el resultado y el procedimiento de reclamo.
- [ ] Conservar evidencia para auditoría y autoridades.

## 4. Protección del consumidor

- [ ] Publicar identidad, domicilio y contacto del proveedor.
- [ ] Redactar términos y condiciones revisados por abogado.
- [ ] Informar precio, premio, restricciones y caducidad.
- [ ] Revisar publicidad para evitar engaño o omisión de riesgos.
- [ ] Habilitar canal de peticiones, quejas y reclamos.
- [ ] Definir tiempos de respuesta y escalamiento.
- [ ] Revisar cláusulas abusivas y derechos irrenunciables.
- [ ] Adaptar el flujo a la Ley 1480 de 2011 y a la reglamentación aplicable.
- [ ] Definir accesibilidad y canales para personas con discapacidad.

## 5. Edad, identidad y juego responsable

- [ ] Definir la edad mínima aplicable y la base legal.
- [ ] Implementar verificación de edad antes de comprar.
- [ ] Verificar identidad del ganador antes de entregar el premio.
- [ ] Definir restricciones para personas inhabilitadas.
- [ ] Evaluar medidas de juego responsable y autoexclusión.
- [ ] Documentar el tratamiento de datos de verificación.
- [ ] Revisar el régimen de prevención de lavado de activos aplicable al sector.

## 6. Protección de datos personales

- [ ] Aprobar la Política de Tratamiento y el aviso definitivos.
- [ ] Designar Responsable y encargados con contratos de transmisión.
- [ ] Evaluar si aplica el Registro Nacional de Bases de Datos e inscribirlo.
- [ ] Aprobar la tabla de retención y el borrado seguro.
- [ ] Implementar consulta, corrección, supresión y revocatoria.
- [ ] Publicar un canal oficial con constancia de recibo.
- [ ] Evaluar transferencias internacionales y garantías.
- [ ] Implementar cifrado en reposo, gestión de llaves y monitoreo.
- [ ] Aprobar un plan de respuesta a incidentes y notificación de brechas.
- [ ] Capacitar al personal y registrar accesos administrativos.
- [ ] Realizar pruebas de seguridad y auditoría independiente.

## 7. Pagos, contabilidad y fiscalidad

- [ ] Pasar de sandbox a credenciales de producción con contrato.
- [ ] Validar el webhook, la firma y la conciliación en producción.
- [ ] Definir reembolsos, contracargos y casos de pago incierto.
- [ ] Integrar contabilidad, impuestos y reportes.
- [ ] Definir el tratamiento de datos financieros y su minimización.
- [ ] Verificar los requisitos de la pasarela y de la autoridad tributaria.
- [ ] Documentar el flujo de dinero y la titularidad de los fondos.

## 8. Operación, seguridad y continuidad

- [ ] Desplegar con TLS, respaldos cifrados y restauración probada.
- [ ] Configurar monitoreo, alertas y registro centralizado.
- [ ] Definir niveles de servicio y soporte.
- [ ] Aprobar plan de continuidad y recuperación.
- [ ] Revisar la arquitectura de microservicios y sus contratos.
- [ ] Probar la disponibilidad de cada servicio y la degradación controlada.
- [ ] Gestionar secretos y rotación de credenciales.
- [ ] Realizar pruebas de carga y capacidad.
- [ ] Documentar el runbook de incidentes.

## 9. Contenido y comunicación

- [ ] Reemplazar el aviso de prototipo solo después de la autorización.
- [ ] Retirar textos que puedan sugerir una operación autorizada.
- [ ] Publicar términos, privacidad y alcance regulatorio definitivos.
- [ ] Revisar la publicidad y los materiales de redes sociales.
- [ ] Definir el canal de prensa y comunicación de crisis.
- [ ] Conservar la trazabilidad de aprobaciones y versiones.

## 10. Estado actual resumido

| Bloque | Estado |
|---|---|
| Identidad legal y responsable | No definido |
| Autorización de rifas | No existe |
| Operador o gestor | No existe |
| GNA o lotería integrada | No existe |
| Boletas y reportes | No existen |
| Derechos de explotación | No aplica |
| Protección al consumidor | Parcial (aviso de prototipo) |
| Política y aviso de datos | Borrador |
| Consentimiento informado | Implementado y verificado en `rifacloud` y `rifacloud_test` |
| Canal de derechos | No definido |
| RNBD | No evaluado |
| Pagos reales | No habilitados |
| TLS, respaldos y monitoreo | Parcial / pendiente |
| Revisión jurídica | Pendiente |

## 11. Criterio de salida

No se debe declarar “listo para operar” hasta que:

1. un abogado haya revisado y aprobado los documentos;
2. exista un operador autorizado y una rifa autorizada;
3. el sorteo y la entrega de premios sean verificables;
4. los pagos reales y la conciliación estén probados;
5. protección al consumidor y datos personales tengan canales operativos;
6. las brechas de seguridad y retención estén cerradas o aceptadas por escrito;
7. la institución y el equipo hayan aprobado el lanzamiento.

## 12. Limitación

Esta lista es un borrador académico. No es asesoría jurídica, no acredita
cumplimiento y no autoriza la operación de rifas. RifaCloud no afirma tener
autorización de Coljuegos.
