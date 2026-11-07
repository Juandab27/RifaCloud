import assert from 'node:assert/strict'
import {
  canReserveRaffle,
  getRafflePublicState,
  type RaffleSchedule,
} from '../src/lib/raffle.ts'

/**
 * Pruebas dirigidas del estado temporal de una rifa.
 *
 * No usan navegador ni base de datos: el estado es una función pura del
 * estado del backend y de la ventana starts_at/ends_at.
 */

let checks = 0

function check(actual: unknown, expected: unknown, message: string): void {
  assert.deepEqual(actual, expected, message)
  checks += 1
}

function raffle(overrides: Partial<RaffleSchedule> = {}): RaffleSchedule {
  return {
    status: 'PUBLISHED',
    starts_at: null,
    ends_at: null,
    ...overrides,
  }
}

const now = new Date('2026-11-07T12:00:00.000Z')

// Sin calendario: disponible.
check(getRafflePublicState(raffle(), now), 'OPEN', 'sin fechas está disponible')
check(canReserveRaffle('OPEN'), true, 'solo OPEN permite reservar')
check(canReserveRaffle('UPCOMING'), false, 'UPCOMING no permite reservar')
check(canReserveRaffle('CLOSED'), false, 'CLOSED no permite reservar')
check(canReserveRaffle('FINISHED'), false, 'FINISHED no permite reservar')

// Antes del inicio: próximamente.
check(
  getRafflePublicState(
    raffle({ starts_at: '2026-11-08T12:00:00.000Z', ends_at: '2026-11-10T12:00:00.000Z' }),
    now,
  ),
  'UPCOMING',
  'una rifa que empieza después es próximamente',
)

// En el instante exacto del inicio: el servidor ya permite reservar.
check(
  getRafflePublicState(raffle({ starts_at: '2026-11-07T12:00:00.000Z' }), now),
  'OPEN',
  'en el instante de inicio la venta está abierta',
)

// Dentro de la ventana: disponible.
check(
  getRafflePublicState(
    raffle({ starts_at: '2026-11-06T12:00:00.000Z', ends_at: '2026-11-08T12:00:00.000Z' }),
    now,
  ),
  'OPEN',
  'dentro de la ventana está disponible',
)

// Fin de la ventana: cerrada (incluso en el instante exacto).
check(
  getRafflePublicState(raffle({ ends_at: '2026-11-07T12:00:00.000Z' }), now),
  'CLOSED',
  'en el instante de cierre la venta está cerrada',
)
check(
  getRafflePublicState(raffle({ ends_at: '2026-11-06T12:00:00.000Z' }), now),
  'CLOSED',
  'después del cierre la venta está cerrada',
)

// Estados terminales del backend.
check(
  getRafflePublicState(raffle({ status: 'FINISHED' }), now),
  'FINISHED',
  'FINISHED prevalece sobre cualquier fecha',
)
check(
  getRafflePublicState(raffle({ status: 'CLOSED' }), now),
  'CLOSED',
  'CLOSED refleja el cierre administrativo',
)
check(
  getRafflePublicState(raffle({ status: 'DRAFT' }), now),
  'DRAFT',
  'DRAFT nunca se presenta como disponible',
)

// Fechas inválidas: no se inventan estados ni se rompe la vista.
check(
  getRafflePublicState(raffle({ starts_at: 'no-es-fecha', ends_at: 'tampoco' }), now),
  'OPEN',
  'fechas inválidas no bloquean la venta en el cliente',
)

const beforeStart = getRafflePublicState(
  raffle({ starts_at: '2026-11-08T12:00:00.000Z' }),
  now,
)
const afterEnd = getRafflePublicState(raffle({ ends_at: '2026-11-06T12:00:00.000Z' }), now)

check(beforeStart === 'OPEN', false, 'la rifa próxima no se marca disponible')
check(afterEnd === 'OPEN', false, 'la rifa vencida no se marca disponible')

console.log(`raffle-state: ${checks} checks passed`)
