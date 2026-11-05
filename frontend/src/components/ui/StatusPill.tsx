import type { ReactNode } from 'react'
import type { NumberStatus, OrderStatus, PaymentState, RaffleStatus } from '../../types'

/** Unión de todos los estados que la API ya expone. No se renombra ninguno. */
export type StatusValue = OrderStatus | PaymentState | RaffleStatus | NumberStatus

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'review' | 'neutral'

const TONE_BY_STATUS: Record<StatusValue, StatusTone> = {
  // Números
  AVAILABLE: 'success',
  RESERVED: 'warning',
  SOLD: 'neutral',
  // Rifas
  DRAFT: 'warning',
  PUBLISHED: 'success',
  CLOSED: 'neutral',
  FINISHED: 'neutral',
  // Órdenes
  PENDING_PAYMENT: 'warning',
  PAID: 'success',
  REJECTED: 'danger',
  EXPIRED: 'neutral',
  PAYMENT_REVIEW: 'review',
  REQUIRES_REFUND: 'danger',
  // Pagos
  PENDING: 'warning',
  APPROVED: 'success',
  UNKNOWN: 'review',
  REFUND_REQUIRED: 'danger',
}

const DEFAULT_LABEL: Record<StatusValue, string> = {
  AVAILABLE: 'Disponible',
  RESERVED: 'Reservado',
  SOLD: 'Vendido',
  DRAFT: 'Borrador',
  PUBLISHED: 'Publicada',
  CLOSED: 'Cerrada',
  FINISHED: 'Finalizada',
  PENDING_PAYMENT: 'Pago pendiente',
  PAID: 'Pagada',
  REJECTED: 'Rechazada',
  EXPIRED: 'Expirada',
  PAYMENT_REVIEW: 'En revisión de pago',
  REQUIRES_REFUND: 'Requiere reembolso',
  PENDING: 'Pago pendiente',
  APPROVED: 'Pago aprobado',
  UNKNOWN: 'Resultado desconocido',
  REFUND_REQUIRED: 'Reembolso requerido',
}

export interface StatusPillProps {
  status: StatusValue
  /** Permite reutilizar los textos propios de cada página. */
  label?: ReactNode
  className?: string
  title?: string
}

/**
 * Píldora de estado. Conserva el modificador en minúsculas que ya consumen
 * las hojas de B0 (`.status.pending_payment`, `.status.refund_required`, …).
 */
export default function StatusPill({ status, label, className, title }: StatusPillProps) {
  const modifier = status.toLowerCase()
  const classes = ['status', modifier, `status--${TONE_BY_STATUS[status]}`, className]
    .filter(Boolean)
    .join(' ')

  return (
    <span className={classes} title={title} data-status={status}>
      {label ?? DEFAULT_LABEL[status]}
    </span>
  )
}
