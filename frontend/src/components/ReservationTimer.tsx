import { useCountdown, type CountdownState, type CountdownTone } from '../lib/useCountdown'

const STATE_LABEL: Record<CountdownTone, string> = {
  calm: 'Activa',
  warning: 'Poco tiempo',
  urgent: 'Últimos segundos',
  expired: 'Vencida',
}

const NOTE: Record<CountdownTone, string> = {
  calm: 'Si la reserva vence sin un pago confirmado, los números vuelven a estar disponibles.',
  warning: 'Queda poco tiempo. Completa el pago para conservar tus números.',
  urgent: 'Quedan menos de un minuto. Completa el pago ahora para no perder la reserva.',
  expired:
    'La reserva venció. Estamos consultando el estado real de la orden; los números pueden volver a estar disponibles.',
}

export interface ReservationTimerProps {
  countdown: CountdownState
  variant?: 'panel' | 'inline'
}

/**
 * Visualización del tiempo restante. El color y el texto cambian según la
 * urgencia, pero nunca deciden la expiración: solo reflejan las fechas de la
 * orden devueltas por la API.
 */
export function ReservationTimer({ countdown, variant = 'panel' }: ReservationTimerProps) {
  if (variant === 'inline') {
    return (
      <p className={`reservation-inline is-${countdown.tone}`}>
        <span className="reservation-dot" aria-hidden="true" />
        <span>{countdown.expired ? 'Reserva vencida' : `Reserva: ${countdown.clock}`}</span>
        {countdown.expired && <span className="reservation-inline-note">actualizando…</span>}
      </p>
    )
  }

  return (
    <div className={`reservation-timer is-${countdown.tone}`}>
      <div className="reservation-timer-head">
        <p className="reservation-timer-label">Tiempo restante de la reserva</p>
        <span className="reservation-timer-state">{STATE_LABEL[countdown.tone]}</span>
      </div>

      <p className="reservation-timer-value" role="timer" aria-live="off">
        {countdown.expired ? '00:00' : countdown.clock}
      </p>

      {countdown.progress !== null && (
        <div className="reservation-meter" aria-hidden="true">
          <span style={{ width: `${Math.round(countdown.progress * 100)}%` }} />
        </div>
      )}

      <p className="reservation-timer-note">{NOTE[countdown.tone]}</p>
    </div>
  )
}

export interface OrderReservationTimerProps {
  expiresAt: string | null
  createdAt?: string | null
}

/** Variante compacta con su propio reloj (una por tarjeta de «Mis compras»). */
export function OrderReservationTimer({
  expiresAt,
  createdAt = null,
}: OrderReservationTimerProps) {
  const countdown = useCountdown(expiresAt, createdAt)

  if (!countdown) {
    return null
  }

  return <ReservationTimer countdown={countdown} variant="inline" />
}
