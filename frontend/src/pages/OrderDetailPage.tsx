import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { formatCents, formatDate } from '../lib/format'
import type { Order, OrderStatus, Payment, PaymentState } from '../types'

const STATUS_LABELS: Record<OrderStatus, string> = {
  PENDING_PAYMENT: 'Pago pendiente',
  PAID: 'Pagada',
  REJECTED: 'Rechazada',
  EXPIRED: 'Expirada',
  PAYMENT_REVIEW: 'En revisión de pago',
  REQUIRES_REFUND: 'Requiere reembolso',
}

const PAYMENT_STATUS_LABELS: Record<PaymentState, string> = {
  PENDING: 'Pago pendiente',
  APPROVED: 'Pago aprobado',
  REJECTED: 'Pago rechazado',
  UNKNOWN: 'Resultado desconocido',
  REFUND_REQUIRED: 'Reembolso requerido',
}

function useRemainingTime(expiresAt: string | null): string | null {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!expiresAt) {
      return
    }

    const timer = window.setInterval(() => setNow(Date.now()), 1000)

    return () => window.clearInterval(timer)
  }, [expiresAt])

  if (!expiresAt) {
    return null
  }

  const difference = new Date(expiresAt).getTime() - now

  if (difference <= 0) {
    return 'Reserva vencida'
  }

  const totalSeconds = Math.floor(difference / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60

  return `${minutes}:${seconds.toString().padStart(2, '0')}`
}

export default function OrderDetailPage() {
  const { orderId } = useParams()
  const [order, setOrder] = useState<Order | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [paymentError, setPaymentError] = useState<string | null>(null)
  const [paymentSubmitting, setPaymentSubmitting] = useState(false)
  const [reloadToken, setReloadToken] = useState(0)
  const remaining = useRemainingTime(order?.expires_at ?? null)

  useEffect(() => {
    let active = true

    setLoading(true)

    api
      .get<{ data: Order }>(`/orders/${orderId}`)
      .then((response) => {
        if (active) {
          setOrder(response.data.data)
          setError(null)
        }
      })
      .catch((caught) => {
        if (active) {
          setError(apiErrorMessage(caught))
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [orderId, reloadToken])

  if (loading && !order) {
    return <p className="muted">Cargando orden…</p>
  }

  if (!order) {
    return (
      <section className="card narrow">
        <p className="alert">{error ?? 'No se encontró la orden.'}</p>
        <Link to="/">Volver al catálogo</Link>
      </section>
    )
  }

  const latestPayment: Payment | null =
    order.payments && order.payments.length > 0
      ? order.payments[order.payments.length - 1]
      : null

  const gatewayIsSimulated = order.simulated_payments === true
  const paymentIsSimulated = latestPayment?.simulated ?? gatewayIsSimulated
  const showSimulatedNotice = gatewayIsSimulated || latestPayment?.simulated === true

  const canStartPayment = order.status === 'PENDING_PAYMENT' && latestPayment === null
  const canVerifyPayment =
    latestPayment !== null &&
    (latestPayment.status === 'PENDING' || latestPayment.status === 'UNKNOWN') &&
    order.status === 'PENDING_PAYMENT'

  const startPayment = async () => {
    setPaymentSubmitting(true)
    setPaymentError(null)

    try {
      const response = await api.post<{ data: Order }>(`/orders/${order.id}/payments`)
      setOrder(response.data.data)
    } catch (caught) {
      setPaymentError(apiErrorMessage(caught))
    } finally {
      setPaymentSubmitting(false)
    }
  }

  const verifyPayment = async () => {
    if (!latestPayment) {
      return
    }

    setPaymentSubmitting(true)
    setPaymentError(null)

    try {
      // The browser never declares the result: the backend verifies it with
      // the payment provider and applies the state transition server-side.
      const response = await api.post<{ data: Order }>(
        `/orders/${order.id}/payments/${latestPayment.id}/verify`,
      )
      setOrder(response.data.data)
    } catch (caught) {
      setPaymentError(apiErrorMessage(caught))
    } finally {
      setPaymentSubmitting(false)
    }
  }

  return (
    <section>
      <p>
        {order.raffle ? (
          <Link to={`/raffles/${order.raffle.id}`}>← Volver a {order.raffle.title}</Link>
        ) : (
          <Link to="/">← Volver al catálogo</Link>
        )}
      </p>

      {error && <p className="alert">{error}</p>}

      <header className="page-header">
        <div>
          <h1>Orden {order.order_number}</h1>
          <p className="muted">
            <span className={`status ${order.status.toLowerCase()}`}>
              {STATUS_LABELS[order.status]}
            </span>
          </p>
        </div>
        <button type="button" className="link-button" onClick={() => setReloadToken((value) => value + 1)}>
          Actualizar estado
        </button>
      </header>

      <dl className="facts">
        <div>
          <dt>Total</dt>
          <dd>{formatCents(order.total_amount)}</dd>
        </div>
        <div>
          <dt>Números</dt>
          <dd>{order.numbers?.length ?? 0}</dd>
        </div>
        <div>
          <dt>Creada</dt>
          <dd>{formatDate(order.created_at)}</dd>
        </div>
      </dl>

      {order.status === 'PENDING_PAYMENT' && (
        <section className="card reservation-countdown">
          <p className="muted">Tiempo restante de la reserva</p>
          <p className="countdown">{remaining ?? '—'}</p>
          <p className="muted">
            Si la reserva vence sin completar el pago, los números vuelven a estar disponibles.
          </p>
        </section>
      )}

      <section className="card payment-panel">
        <h2>Pago</h2>

        {showSimulatedNotice && (
          <p className="simulated-notice">
            <span className="status simulated">SIMULADO</span>
            Este entorno usa el gateway de pruebas. No se procesa dinero real.
          </p>
        )}

        {latestPayment ? (
          <>
            <p>
              <span className={`status ${latestPayment.status.toLowerCase()}`}>
                {PAYMENT_STATUS_LABELS[latestPayment.status]}
              </span>
            </p>
            <p className="muted">
              {paymentIsSimulated
                ? 'Simulación local (sin cobro real)'
                : latestPayment.provider === 'MERCADOPAGO'
                  ? 'Mercado Pago'
                  : latestPayment.provider}
              {' · '}
              {formatCents(latestPayment.amount)} {latestPayment.currency}
            </p>
            {latestPayment.verified_at && (
              <p className="muted">Última verificación: {formatDate(latestPayment.verified_at)}</p>
            )}

            {latestPayment.status === 'PENDING' && (
              <p className="muted">
                El pago fue iniciado pero todavía no está confirmado. Verifica el resultado
                con el proveedor.
              </p>
            )}

            {latestPayment.status === 'UNKNOWN' && (
              <p className="alert">
                El resultado del pago es incierto. Los números siguen reservados y no se
                iniciará otro cobro.{' '}
                {order.status === 'PAYMENT_REVIEW'
                  ? 'Un administrador debe resolver esta incidencia.'
                  : 'La orden requiere revisión.'}
              </p>
            )}

            {latestPayment.status === 'APPROVED' && (
              <p className="muted">
                {paymentIsSimulated
                  ? 'La simulación aprobó el pago y los números quedaron vendidos. No se procesó dinero real.'
                  : 'El pago fue aprobado y los números quedaron vendidos.'}
              </p>
            )}

            {latestPayment.status === 'REJECTED' && (
              <p className="muted">El pago fue rechazado y los números fueron liberados.</p>
            )}

            {latestPayment.status === 'REFUND_REQUIRED' && (
              <p className="alert">
                El pago fue confirmado pero los números no pueden asignarse. La orden
                requiere un reembolso manual.
              </p>
            )}
          </>
        ) : (
          <p className="muted">
            Todavía no se inició el pago. La orden mantiene los números reservados hasta
            que venza el plazo.
          </p>
        )}

        {paymentError && <p className="alert">{paymentError}</p>}

        {(canStartPayment || canVerifyPayment) && (
          <div className="payment-actions">
            {canStartPayment && (
              <button type="button" onClick={startPayment} disabled={paymentSubmitting}>
                {paymentSubmitting ? 'Iniciando…' : 'Iniciar pago'}
              </button>
            )}
            {canVerifyPayment && (
              <button type="button" onClick={verifyPayment} disabled={paymentSubmitting}>
                {paymentSubmitting
                  ? 'Consultando…'
                  : latestPayment?.status === 'UNKNOWN'
                    ? 'Consultar estado'
                    : 'Verificar pago'}
              </button>
            )}
          </div>
        )}

        <p className="muted payment-note">
          La confirmación se obtiene y valida en el servidor. Una redirección del navegador
          no marca la orden como pagada.
        </p>
      </section>

      <h2>{order.status === 'PENDING_PAYMENT' ? 'Números reservados' : 'Números de la orden'}</h2>
      <div className="number-grid">
        {order.numbers?.map((item) => (
          <span key={item.id} className={`number ${item.status.toLowerCase()}`}>
            {item.number}
          </span>
        ))}
      </div>

      {order.expires_at && (
        <p className="muted">Vence: {formatDate(order.expires_at)}</p>
      )}
    </section>
  )
}
