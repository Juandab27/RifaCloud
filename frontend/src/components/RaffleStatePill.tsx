import type { Raffle } from '../types'
import {
  RAFFLE_STATE_LABELS,
  RAFFLE_STATE_TONES,
  getRafflePublicState,
  raffleStateMessage,
} from '../lib/raffle'
import StatusPill from './ui/StatusPill'

export interface RaffleStatePillProps {
  raffle: Raffle
  /** Reloj compartido por la página para que todas las tarjetas coincidan. */
  now?: Date
}

/**
 * Píldora que muestra el estado temporal real de la rifa reutilizando
 * StatusPill. Conserva el estado del backend como data-status.
 */
export default function RaffleStatePill({ raffle, now }: RaffleStatePillProps) {
  const state = getRafflePublicState(raffle, now)

  return (
    <StatusPill
      status={raffle.status}
      label={RAFFLE_STATE_LABELS[state]}
      tone={RAFFLE_STATE_TONES[state]}
      title={raffleStateMessage(state, raffle)}
    />
  )
}
