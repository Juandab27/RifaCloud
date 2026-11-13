# Datos de prueba

Todos los datos viven exclusivamente en `rifacloud_test` y se generan con
`scripts/prepare-demo-environment.ps1`.

Los IDs numéricos cambian al volver a sembrar. Consúltalos en:

```powershell
Get-Content docs/testing/.local/test-data.json
```

Las tablas siguientes usan los títulos y números de orden estables.

## Rifas

| Clave | Título | Estado backend | Estado público | Ventana | Números | Propósito |
|---|---|---|---|---|---|---|
| `open` | `[DEMO] Rifa disponible con números libres` | `PUBLISHED` | Disponible | desde hace 2 días hasta dentro de 7 días | 1–30 | Reservar, pagar con gateway simulado y ver números libres |
| `upcoming` | `[DEMO] Rifa próximamente` | `PUBLISHED` | Próximamente | comienza dentro de 2 días | 1–10 | Comprobar que no se ofrece reservar antes del inicio |
| `ended` | `[DEMO] Rifa con ventana de venta terminada` | `PUBLISHED` | Cerrada | terminó ayer | 1–10 | Comprobar el estado cerrado derivado de fechas |
| `closed` | `[DEMO] Rifa cerrada con ventas para publicar resultado` | `CLOSED` | No visible en catálogo (AC-006) | terminó hace 5 días | 1–10; 1–3 `SOLD` | Publicar el resultado desde administración usando 1, 2 o 3 |
| `finished` | `[DEMO] Rifa finalizada con resultado y evidencia` | `FINISHED` | Finalizada | terminó hace 15 días | 4–5 `SOLD`; ganador 4 | Ver resultado, fecha y evidencia públicos |

La rifa `closed` no aparece en el catálogo público por diseño. El
administrador la ve en <http://localhost/admin/raffles> y puede publicar su
resultado. Al publicarlo pasa a `FINISHED` y se vuelve visible públicamente.

## Órdenes

| Orden | Comprador | Estado | Pago | Números | UI esperada |
|---|---|---|---|---|---|
| `DEMO-PAID-CLOSED` | Comprador 1 | `PAID` | `APPROVED` | 1, 2, 3 de `closed` (`SOLD`) | Compra aprobada, números vendidos |
| `DEMO-PAID-FINISHED` | Comprador 1 | `PAID` | `APPROVED` | 4, 5 de `finished` (`SOLD`) | Compra aprobada dentro de una rifa finalizada |
| `DEMO-PENDING-B1` | Comprador 1 | `PENDING_PAYMENT` | Sin pago | 29, 30 de `open` (`RESERVED`) | Temporizador y opción de iniciar pago simulado |
| `DEMO-PENDING-B2` | Comprador 2 | `PENDING_PAYMENT` | Sin pago | 24 de `open` (`RESERVED`) | Reserva exclusiva del comprador 2 |
| `DEMO-EXPIRED-B1` | Comprador 1 | `EXPIRED` | Sin pago | 28 de `open` (liberado) | Mensaje de reserva vencida y CTA para volver a la rifa |
| `DEMO-REJECTED-B1` | Comprador 1 | `REJECTED` | `REJECTED` | 27 de `open` (liberado) | Mensaje de pago rechazado y recuperación |
| `DEMO-REVIEW-B1` | Comprador 1 | `PAYMENT_REVIEW` | `UNKNOWN` | 25, 26 de `open` (`RESERVED`) | Incidencia pendiente; los números siguen reservados |

## Mapa de números de la rifa `open`

| Números | Estado | Explicación |
|---|---|---|
| 1–23 | `AVAILABLE` | Libres para reservar manualmente |
| 24 | `RESERVED` | Reserva del comprador 2 |
| 25–26 | `RESERVED` | Orden en revisión por pago `UNKNOWN` |
| 27 | `AVAILABLE` | Pago rechazado; número liberado |
| 28 | `AVAILABLE` | Reserva expirada; número liberado |
| 29–30 | `RESERVED` | Reserva pendiente del comprador 1 |

## Dashboard esperado al sembrar

| Indicador | Valor |
|---|---|
| Rifas publicadas (`PUBLISHED`) | 3 |
| Órdenes pagadas (`PAID`) | 2 |
| Números vendidos (`SOLD`) | 5 |
| Incidencias pendientes (`UNKNOWN` + `REFUND_REQUIRED`) | 1 |

Las acciones manuales (nueva compra, publicación de resultado, resolución de
incidencia) cambian estos valores. Vuelve a ejecutar el seeder para restaurar
el escenario inicial.

## Cómo se generaron los estados

El seeder crea **snapshots consistentes** para estados que de otro modo
requerirían esperar una expiración o un resultado del proveedor. No ejecuta ni
altera transiciones financieras de la aplicación.

Invariantes respetadas:

- `PAID` ↔ números `SOLD` ↔ pago `APPROVED`.
- `PENDING_PAYMENT` / `PAYMENT_REVIEW` ↔ números `RESERVED`.
- `EXPIRED` / `REJECTED` ↔ números liberados (`AVAILABLE`), conservando
  `order_items` históricos.
- `PAYMENT_REVIEW` tiene un pago `UNKNOWN` y no se libera por el simple paso
  del tiempo.
- La rifa `closed` tiene números `SOLD` y **no** tiene resultado: el
  administrador debe publicarlo con el flujo real.
- La rifa `finished` tiene un resultado cuyo ganador es un número `SOLD`.

No se insertan combinaciones incoherentes ni se modifican las reglas de
reservas, pagos o resultados.

## Idempotencia y limpieza

- Ejecutar el seeder dos veces deja los mismos 3 usuarios, 5 rifas, 7 órdenes
  y 4 pagos demo; no duplica filas.
- Solo elimina títulos `[DEMO]`, órdenes `DEMO-` y datos asociados.
- No toca filas ajenas a esos prefijos.
- `cleanup-demo-environment.ps1` elimina los fixtures y restaura el stack
  normal. Los usuarios demo se conservan salvo `-PurgeUsers`.
- Si un usuario demo creó una orden manual en una rifa no demo, la limpieza la
  conserva y no purga ese usuario; el script lo informa.
