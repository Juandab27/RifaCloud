# RifaCloud — Material de sustentación

**Preparado:** 12 de noviembre de 2026  
**Alcance:** documentación educativa basada en el repositorio; no cambia código,
configuración, datos ni infraestructura.

## Orden de lectura recomendado

| # | Documento | Para qué |
|---:|---|---|
| 1 | [ARQUITECTURA_EXPLICADA.md](ARQUITECTURA_EXPLICADA.md) | Entender qué es RifaCloud, la evolución monolito → cuatro servicios, responsabilidades y deuda técnica |
| 2 | [FLUJOS_TECNICOS.md](FLUJOS_TECNICOS.md) | Seguir registro, catálogo, reserva, pago, webhook, expiración, historial, resultado y dashboard con diagramas |
| 3 | [DOCKER_E_INFRAESTRUCTURA.md](DOCKER_E_INFRAESTRUCTURA.md) | Explicar contenedores, red, puertos, healthchecks, volúmenes y AWS preparado |
| 4 | [PRUEBAS_Y_EVIDENCIAS.md](PRUEBAS_Y_EVIDENCIAS.md) | Defender qué se probó, con qué resultados documentados y qué límites existen |
| 5 | [GUIA_PARA_COMPANEROS.md](GUIA_PARA_COMPANEROS.md) | Repartir la exposición, ensayar el guion y responder 30 preguntas difíciles |
| 6 | [DEMO_PASO_A_PASO.md](DEMO_PASO_A_PASO.md) | Ejecutar una demo segura sobre `rifacloud_test` y tener plan B |

## Resumen de bolsillo

```text
RifaCloud = prototipo académico de rifas.

Arquitectura actual:
  Nginx + app (monolito/rollback) + scheduler
  + auth-service + raffles-service + orders-service + payments-service
  + MySQL 8.4 compartido (una instancia/esquema)
  + usuarios MySQL por servicio

Invariantes:
  - Reserva atómica: locks + UNIQUE(raffle_id, number).
  - Pago verificado server-side; la URL no aprueba.
  - Webhook idempotente: firma + evento único + comando convergente.
  - UNKNOWN no libera números; aprobación tardía → reembolso manual.
  - Resultado manual: rifa CLOSED, número SOLD, una vez, con evidencia.

Estado real:
  - S11 aplicado a rifacloud el 2026-11-12 (migración + grant + 3 filas verificadas).
  - AWS preparado, NO desplegado.
  - Mercado Pago sandbox implementado, webhook real NO verificado.
  - SMTP real NO configurado; mailer log.
  - Una sola instancia; sin HA.
```

## Reglas para no perder credibilidad

1. No decir “cuatro bases de datos”.
2. No decir “microservicios totalmente independientes”.
3. No decir “Mercado Pago probado con pagos reales”.
4. No decir “desplegado en AWS”.
5. No decir “cumplimos Coljuegos”.
6. No inventar resultados, capturas ni métricas.
7. Si no se sabe, derivar a otro expositor o al archivo del repositorio.

## Archivos fuente principales

- `AGENTS.md` (estado actual, reglas y actualización S11 del 2026-11-12)
- `README.md`
- `docs/sdd/01-product-requirements.md`
- `docs/sdd/02-technical-design.md`
- `docs/sdd/03-data-model.md`
- `docs/sdd/04-implementation-plan.md`
- `docs/sdd/05-acceptance-criteria.md`
- `docs/sdd/06-open-questions.md`
- `docs/sdd/07-resilience-and-failure-handling.md`
- `docs/sdd/08-adr-001-four-service-architecture.md`
- `docs/legal/`
- `docs/testing/`
- `docker-compose*.yml`, `docker/nginx/default.conf`, `services/*`, `scripts/`
