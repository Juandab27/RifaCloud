# Plan de pruebas manuales

## 1. Preparar el entorno

```powershell
# Primer inicio
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1

# Si las imágenes ya están construidas
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1 -SkipBuild

# Credenciales e IDs actuales
Get-Content docs/testing/.local/test-users.json
Get-Content docs/testing/.local/test-data.json
```

El stack queda sobre `rifacloud_test`, con gateway simulado y reservas de
60 minutos. La base `rifacloud` no se modifica.

URLs base:

- Catálogo: <http://localhost/>
- Login: <http://localhost/login>
- Mis compras: <http://localhost/orders>
- Administración de rifas: <http://localhost/admin/raffles>
- Órdenes e incidencias: <http://localhost/admin/orders>
- Dashboard: <http://localhost/admin/dashboard>

Para abrir una rifa concreta:

```powershell
$data = Get-Content docs/testing/.local/test-data.json -Raw | ConvertFrom-Json
"http://localhost/raffles/$($data.ids.raffles.open)"
"http://localhost/raffles/$($data.ids.raffles.closed)"
"http://localhost/raffles/$($data.ids.raffles.finished)"
```

## 2. Comprobaciones sin sesión

1. Abre el catálogo.
2. Deben verse cuatro rifas: disponible, próximamente, cerrada por fecha y
   finalizada con resultado.
3. La rifa `[DEMO] Rifa cerrada con ventas para publicar resultado` no debe
   aparecer en el catálogo público.
4. La rifa finalizada debe mostrar ganador, fecha y tarjeta de resultado.
5. Entra en la rifa próximamente: no debe ofrecer reservar números.
6. Entra en la rifa con ventana terminada: debe mostrarse cerrada y no ofrecer
   reserva.
7. Abre `/orders` sin sesión: debe redirigir a login.
8. Abre `http://localhost/api/admin/raffles/metrics` sin sesión: debe responder
   401.

## 3. Roles y sesión

| Usuario | Comprobación |
|---|---|
| Comprador 1 | Entra, ve “Mis compras”, no ve Administración |
| Comprador 2 | Entra, ve solo su orden pendiente |
| ADMIN | Ve Dashboard y Administración |

1. Inicia sesión con el comprador 1.
2. Abre `/orders`: deben aparecer 6 órdenes demo (2 pagadas, pendiente,
   expirada, rechazada y en revisión).
3. Abre el detalle de la orden pagada y verifica números `SOLD` y fecha de pago.
4. Abre la orden rechazada: debe explicar el rechazo y ofrecer **Elegir números
   de nuevo**.
5. Abre la orden expirada: debe explicar el vencimiento y ofrecer volver a la
   rifa.
6. Abre la orden en revisión: debe indicar que el resultado es incierto y que
   los números siguen reservados.
7. Cierra sesión e inicia con el comprador 2.
8. En `/orders` solo debe aparecer `DEMO-PENDING-B2`.
9. Intenta abrir por URL una orden del comprador 1: debe recibir 404/estado de
   no encontrada, nunca el detalle ajeno.

## 4. Reserva y pago simulado

1. Con el comprador 1, abre la rifa disponible.
2. Selecciona un número libre (por ejemplo, 1 si sigue disponible).
3. Pulsa **Reservar números**.
4. Debe crearse una orden `PENDING_PAYMENT`, con temporizador de 60 minutos y
   los números en `RESERVED`.
5. En el detalle, pulsa **Iniciar pago**.
   - El gateway simulado por defecto (`PAYMENT_FAKE_OUTCOME=APPROVED`) crea un
     pago simulado.
6. Pulsa **Verificar pago**.
7. La orden debe pasar a `PAID` y los números a `SOLD`, una sola vez.
8. Actualiza la página: el estado debe mantenerse.

Para simular un rechazo con el gateway fake:

```powershell
$env:PAYMENT_FAKE_OUTCOME = 'REJECTED'
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --force-recreate payments-service
```

Después crea otra reserva y repite el checkout. La orden debe quedar
`REJECTED`, los números liberados y la UI debe ofrecer una nueva oportunidad.
Para volver al resultado aprobado, repite con `APPROVED` o vuelve a preparar
el entorno.

## 5. Dashboard administrativo

1. Inicia sesión como ADMIN.
2. Abre <http://localhost/admin/dashboard>.
3. Antes de hacer cambios debe mostrar:
   - Rifas publicadas: **3**
   - Órdenes pagadas: **2**
   - Números vendidos: **5**
   - Incidencias pendientes: **1**
4. Pulsa **Actualizar**: los valores deben mantenerse.
5. Abre **Órdenes e incidencias** y revisa la pestaña de incidencias: debe
   aparecer la orden `DEMO-REVIEW-B1`.
6. Si detienes un servicio de prueba, el indicador correspondiente debe mostrar
   **No disponible**, no un cero. Restaura el servicio después.

Ejemplo de comprobación de degradación:

```powershell
docker compose -f docker-compose.yml -f docker-compose.e2e.yml stop orders-service
# Abre el dashboard y comprueba "No disponible"
docker compose -f docker-compose.yml -f docker-compose.e2e.yml start orders-service
```

## 6. Publicación de resultado

1. Como ADMIN, abre <http://localhost/admin/raffles>.
2. Localiza `[DEMO] Rifa cerrada con ventas para publicar resultado`.
3. Pulsa **Publicar resultado**.
4. Usa un número vendido: **1**, **2** o **3** (no cualquier número libre).
5. Escribe una descripción de evidencia y, opcionalmente, una URL.
6. Publica.
7. La rifa debe pasar a `FINISHED`; el resultado debe verse en el catálogo y en
   el detalle público.
8. Comprueba que no se puede publicar un segundo resultado para la misma rifa.

Este paso modifica los datos demo. Vuelve a ejecutar el seeder para restaurar
el escenario inicial.

## 7. Incidencias administrativas

1. Como ADMIN, abre **Órdenes e incidencias**.
2. Cambia a la pestaña de incidencias.
3. Debe aparecer `DEMO-REVIEW-B1` con pago `UNKNOWN`.
4. La acción de resolver consulta el estado real del proveedor. Con el gateway
   fake el resultado puede cambiar; no se debe inventar una aprobación.
5. Si resuelves la incidencia, el dashboard debe actualizar sus valores al
   pulsar **Actualizar**.

## 8. Móvil

1. Abre el catálogo en una ventana de 390 × 844.
2. El menú debe abrirse y cerrarse correctamente.
3. No debe haber desplazamiento horizontal.
4. El dashboard debe mostrar cuatro tarjetas en una columna.
5. El detalle con resultado y la recuperación de pago rechazado deben seguir
   siendo legibles.

## 9. Limpieza

```powershell
# Elimina fixtures; restaura el stack normal solo si rifacloud ya está migrado a COP
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1

# Opcional: elimina también los usuarios demo si no tienen órdenes
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1 -PurgeUsers
```

No uses `migrate:fresh`, `db:wipe` ni borres `mysql_data` para limpiar este
entorno.

## 10. Checklist mínimo

- [ ] Catálogo con cuatro estados y sin la rifa CLOSED.
- [ ] Login de los tres usuarios.
- [ ] Comprador 2 no ve órdenes del comprador 1.
- [ ] Reserva nueva con temporizador.
- [ ] Pago simulado aprobado y números vendidos una sola vez.
- [ ] Orden rechazada con mensaje y recuperación.
- [ ] Orden expirada con números liberados.
- [ ] Incidencia `UNKNOWN` visible para ADMIN.
- [ ] Dashboard con 3/2/5/1 antes de cambios.
- [ ] Publicación de resultado en la rifa cerrada.
- [ ] Resultado visible públicamente.
- [ ] Sin desbordamiento horizontal a 390 px.
- [ ] Limpieza y restauración del stack normal.
