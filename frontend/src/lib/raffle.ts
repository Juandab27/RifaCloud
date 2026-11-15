import type { RaffleStatus } from '../types'

/**
 * Estado público de una rifa.
 *
 * El backend expone RaffleStatus (DRAFT/PUBLISHED/CLOSED/FINISHED), pero una
 * rifa PUBLISHED también puede estar fuera de su ventana de venta y una rifa
 * CLOSED con sorteo automático está esperando la fecha del sorteo. Este estado
 * derivado es solo de presentación: el servidor sigue siendo la autoridad.
 */
export type RafflePublicState =
  | 'DRAFT'
  | 'UPCOMING'
  | 'OPEN'
  | 'DRAW_PENDING'
  | 'CLOSED'
  | 'FINISHED'

export interface RaffleSchedule {
  status: RaffleStatus
  draw_mode?: 'MANUAL' | 'AUTOMATIC' | null
  starts_at: string | null
  ends_at: string | null
  draw_at?: string | null
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
    return raffle.draw_mode === 'AUTOMATIC' && raffle.draw_at
      ? 'DRAW_PENDING'
      : 'CLOSED'
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
  DRAW_PENDING: 'Sorteo pendiente',
  CLOSED: 'Cerrada',
  FINISHED: 'Finalizada',
}

export type RaffleStateTone = 'success' | 'info' | 'warning' | 'neutral'

export const RAFFLE_STATE_TONES: Record<RafflePublicState, RaffleStateTone> = {
  DRAFT: 'warning',
  UPCOMING: 'info',
  OPEN: 'success',
  DRAW_PENDING: 'info',
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
    case 'DRAW_PENDING':
      return 'La venta terminó y el sorteo está programado para la fecha indicada.'
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
