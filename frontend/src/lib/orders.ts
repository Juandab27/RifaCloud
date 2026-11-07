import type { NumberStatus, OrderNumber, OrderStatus, PaymentState } from '../types'

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  PENDING_PAYMENT: 'Pago pendiente',
  PAID: 'Pagada',
  REJECTED: 'Rechazada',
  EXPIRED: 'Expirada',
  PAYMENT_REVIEW: 'En revisión de pago',
  REQUIRES_REFUND: 'Requiere reembolso',
}

export const ORDER_STATUS_HINT: Record<OrderStatus, string> = {
  PENDING_PAYMENT:
    'Los números siguen reservados hasta que venza el plazo o se confirme el pago.',
  PAID: 'El pago fue aprobado y los números quedaron vendidos.',
  REJECTED: 'El pago fue rechazado y los números fueron liberados.',
  EXPIRED: 'La reserva venció sin pago confirmado; los números volvieron a estar disponibles.',
  PAYMENT_REVIEW:
    'El resultado del pago es incierto. Un administrador debe resolver la incidencia; los números siguen reservados.',
  REQUIRES_REFUND:
    'El pago se confirmó, pero los números no pudieron asignarse. La orden requiere gestión de reembolso por un administrador.',
}

export const PAYMENT_STATUS_LABELS: Record<PaymentState, string> = {
  PENDING: 'Pago pendiente',
  APPROVED: 'Pago aprobado',
  REJECTED: 'Pago rechazado',
  UNKNOWN: 'Resultado desconocido',
  REFUND_REQUIRED: 'Reembolso requerido',
}

export const NUMBER_STATUS_LABELS: Record<NumberStatus, string> = {
  AVAILABLE: 'Disponible',
  RESERVED: 'Reservado',
  SOLD: 'Vendido',
}

export interface NumberCounts {
  sold: number
  reserved: number
  available: number
}

export function countNumbers(numbers: OrderNumber[] | undefined): NumberCounts {
  const counts: NumberCounts = { sold: 0, reserved: 0, available: 0 }

  for (const item of numbers ?? []) {
    if (item.status === 'SOLD') {
      counts.sold += 1
    } else if (item.status === 'RESERVED') {
      counts.reserved += 1
    } else {
      counts.available += 1
    }
  }

  return counts
}

/**
 * Human summary that never presents a reserved/released number as purchased.
 */
export function numbersSummary(numbers: OrderNumber[] | undefined): string {
  const counts = countNumbers(numbers)
  const parts: string[] = []

  if (counts.sold > 0) {
    parts.push(`${counts.sold} vendido${counts.sold === 1 ? '' : 's'}`)
  }

  if (counts.reserved > 0) {
    parts.push(`${counts.reserved} reservado${counts.reserved === 1 ? '' : 's'}`)
  }

  if (counts.available > 0) {
    parts.push(`${counts.available} disponible${counts.available === 1 ? '' : 's'}`)
  }

  return parts.length > 0 ? parts.join(' · ') : 'Sin números'
}

/**
 * The heading depends on the order state so an expired/rejected order never
 * looks like a completed purchase.
 */
export function numbersHeading(status: OrderStatus): string {
  switch (status) {
    case 'PAID':
      return 'Números adquiridos'
    case 'PENDING_PAYMENT':
      return 'Números reservados'
    case 'PAYMENT_REVIEW':
      return 'Números reservados (en revisión)'
    case 'REQUIRES_REFUND':
      return 'Números de la orden (reembolso pendiente)'
    default:
      return 'Números de la orden'
  }
}
