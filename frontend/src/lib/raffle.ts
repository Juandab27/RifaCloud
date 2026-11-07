import type { RaffleStatus } from '../types'

/**
 * Estado público de una rifa.
 *
 * El backend expone RaffleStatus (DRAFT/PUBLISHED/CLOSED/FINISHED), pero una
 * rifa PUBLISHED también puede estar fuera de su ventana de venta. Este estado
 * derivado es solo de presentación: el servidor sigue siendo la autoridad y
 * rechaza cualquier reserva fuera de la ventana.
 */
export type RafflePublicState = 'DRAFT' | 'UPCOMING' | 'OPEN' | 'CLOSED' | 'FINISHED'

export interface RaffleSchedule {
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
}

function timestamp(value: string | null): number | null {
  if (!value) {
    return null
  }

  const parsed = Date.parse(value)

  return Number.isNaN(parsed) ? null : parsed
}

/**
 * Deriva el estado visible de la rifa sin modificar el estado persistido ni
 * los resultados oficiales.
 *
 * - FINISHED siempre gana: la rifa ya tiene resultado publicado.
 * - CLOSED refleja el cierre administrativo (no se muestra en el catálogo
 *   público, según AC-006; se mantiene por si la vista recibe ese estado).
 * - PUBLISHED se afina con starts_at/ends_at: próximamente, disponible o
 *   cerrada por fin de la ventana.
 */
export function getRafflePublicState(
  raffle: RaffleSchedule,
  now: Date = new Date(),
): RafflePublicState {
  if (raffle.status === 'DRAFT') {
    return 'DRAFT'
  }

  if (raffle.status === 'FINISHED') {
    return 'FINISHED'
  }

  if (raffle.status === 'CLOSED') {
    return 'CLOSED'
  }

  const nowMs = now.getTime()
  const startsAt = timestamp(raffle.starts_at)
  const endsAt = timestamp(raffle.ends_at)

  if (startsAt !== null && nowMs < startsAt) {
    return 'UPCOMING'
  }

  // El servidor considera vencida la ventana cuando endsAt < now; en el
  // cliente cerramos también en el instante exacto para no ofrecer una
  // reserva que llegaría tarde.
  if (endsAt !== null && nowMs >= endsAt) {
    return 'CLOSED'
  }

  return 'OPEN'
}

export function canReserveRaffle(state: RafflePublicState): boolean {
  return state === 'OPEN'
}

export const RAFFLE_STATE_LABELS: Record<RafflePublicState, string> = {
  DRAFT: 'Borrador',
  UPCOMING: 'Próximamente',
  OPEN: 'Disponible',
  CLOSED: 'Cerrada',
  FINISHED: 'Finalizada',
}

export type RaffleStateTone = 'success' | 'info' | 'warning' | 'neutral'

export const RAFFLE_STATE_TONES: Record<RafflePublicState, RaffleStateTone> = {
  DRAFT: 'warning',
  UPCOMING: 'info',
  OPEN: 'success',
  CLOSED: 'neutral',
  FINISHED: 'neutral',
}

/** Mensaje claro y reutilizable para cada estado temporal. */
export function raffleStateMessage(
  state: RafflePublicState,
  raffle: RaffleSchedule,
): string {
  switch (state) {
    case 'UPCOMING':
      return 'La venta todavía no ha comenzado.'
    case 'OPEN':
      return 'La venta está abierta y puedes reservar números disponibles.'
    case 'CLOSED':
      return raffle.status === 'CLOSED'
        ? 'La rifa fue cerrada por la administración.'
        : 'La ventana de venta está cerrada.'
    case 'FINISHED':
      return 'La rifa finalizó y su resultado oficial ya está publicado.'
    default:
      return 'La rifa todavía no está publicada.'
  }
}
