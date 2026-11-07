import type { Order, Payment, PaymentState } from '../types'

type StepState = 'done' | 'current' | 'pending' | 'warning' | 'error'

interface Step {
  key: string
  label: string
  detail: string
  state: StepState
}

const PAYMENT_DETAIL: Record<PaymentState, string> = {
  PENDING: 'Pendiente',
  APPROVED: 'Aprobado',
  REJECTED: 'Rechazado',
  UNKNOWN: 'En revisión',
  REFUND_REQUIRED: 'Con incidencia',
}

function paymentStepState(payment: Payment | null): StepState {
  if (!payment) {
    return 'current'
  }

  switch (payment.status) {
    case 'APPROVED':
      return 'done'
    case 'PENDING':
      return 'current'
    case 'UNKNOWN':
      return 'warning'
    default:
      return 'error'
  }
}

function buildSteps(order: Order): Step[] {
  const payments = order.payments ?? []
  const latest = payments.length > 0 ? payments[payments.length - 1] : null

  const reservation: Step = {
    key: 'reservation',
    label: 'Reserva',
    detail: order.status === 'EXPIRED' ? 'Vencida' : 'Creada',
    state: order.status === 'EXPIRED' ? 'warning' : 'done',
  }

  const payment: Step = {
    key: 'payment',
    label: 'Pago',
    detail: latest ? PAYMENT_DETAIL[latest.status] : 'Sin iniciar',
    state: paymentStepState(latest),
  }

  let confirmation: Step

  switch (order.status) {
    case 'PAID':
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Números vendidos',
        state: 'done',
      }
      break
    case 'PAYMENT_REVIEW':
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Revisión manual',
        state: 'warning',
      }
      break
    case 'REQUIRES_REFUND':
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Reembolso pendiente',
        state: 'error',
      }
      break
    case 'REJECTED':
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Pago rechazado',
        state: 'error',
      }
      break
    case 'EXPIRED':
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Sin pago',
        state: 'pending',
      }
      break
    default:
      confirmation = {
        key: 'confirmation',
        label: 'Confirmación',
        detail: 'Pendiente',
        state: 'pending',
      }
  }

  return [reservation, payment, confirmation]
}

/**
 * Resumen visual del recorrido de la compra. Se deriva exclusivamente del
 * estado real de la orden y de su último pago; no inventa aprobaciones.
 */
export default function OrderProgress({ order }: { order: Order }) {
  const steps = buildSteps(order)

  return (
    <ol className="order-progress" aria-label="Progreso de la compra">
      {steps.map((step, index) => (
        <li
          key={step.key}
          className={`order-progress-step is-${step.state}`}
          aria-current={step.state === 'current' ? 'step' : undefined}
        >
          <span className="order-progress-dot" aria-hidden="true">
            {step.state === 'done' ? '✓' : step.state === 'error' ? '!' : index + 1}
          </span>
          <span className="order-progress-text">
            <span className="order-progress-label">{step.label}</span>
            <span className="order-progress-detail">{step.detail}</span>
          </span>
        </li>
      ))}
    </ol>
  )
}
