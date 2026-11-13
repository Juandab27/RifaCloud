import assert from 'node:assert/strict'
import {
  formatCop,
  formatDate,
  toBogotaDateTimeInput,
  toUtcIsoFromBogotaInput,
} from '../src/lib/format.ts'

/**
 * Pruebas dirigidas del formato monetario COP y de la conversión de fechas
 * entre America/Bogota y UTC. No tocan base de datos ni servicios.
 */

let checks = 0

function check(actual: unknown, expected: unknown, message: string): void {
  assert.deepEqual(actual, expected, message)
  checks += 1
}

check(formatCop(100), '$ 100 COP', '100 pesos se muestran sin conversión')
check(formatCop(1000), '$ 1.000 COP', '1.000 pesos usan separador de miles')
check(formatCop(50000), '$ 50.000 COP', '50.000 pesos usan formato colombiano')
check(formatCop(100, 'USD'), '$ 100 USD', 'el código de moneda se muestra explícito')
check(formatCop(Number.NaN), '—', 'un importe inválido no inventa un valor')

check(
  toBogotaDateTimeInput('2026-11-20T15:00:00.000Z'),
  '2026-11-20T10:00',
  'UTC se convierte a la hora local de Colombia',
)
check(
  toBogotaDateTimeInput('2026-11-20T03:00:00.000Z'),
  '2026-11-19T22:00',
  'la conversión respeta el cambio de día',
)
check(
  toBogotaDateTimeInput('2026-11-20T05:00:00.000Z'),
  '2026-11-20T00:00',
  'la medianoche se representa como 00:00, no 24:00',
)
check(
  toUtcIsoFromBogotaInput('2026-11-20T10:00'),
  '2026-11-20T15:00:00.000Z',
  'la hora de Colombia se envía como el instante UTC correcto',
)
check(toUtcIsoFromBogotaInput(''), null, 'un campo vacío no produce fecha')
check(
  toBogotaDateTimeInput(toUtcIsoFromBogotaInput('2026-11-20T10:00')),
  '2026-11-20T10:00',
  'ida y vuelta conserva la hora seleccionada',
)

check(formatDate('2026-11-20T15:00:00.000Z').includes('10:00'), true, 'la UI muestra la hora de Colombia')

console.log(`format-cop: ${checks} checks passed`)
