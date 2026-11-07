import { useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import axios from 'axios'
import { api, apiErrorMessage } from '../api/client'
import OrderProgress from '../components/OrderProgress'
import { ReservationTimer } from '../components/ReservationTimer'
import { useToast } from '../components/ToastProvider'
import Alert from '../components/ui/Alert'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import ErrorState from '../components/ui/ErrorState'
import PageSkeleton from '../components/ui/PageSkeleton'
import Spinner from '../components/ui/Spinner'
import StatusPill from '../components/ui/StatusPill'
import { formatCents, formatDate } from '../lib/format'
import {
  NUMBER_STATUS_LABELS,
  ORDER_STATUS_HINT,
  ORDER_STATUS_LABELS,
  PAYMENT_STATUS_LABELS,
  countNumbers,
  numbersHeading,
  numbersSummary,
} from '../lib/orders'
import { useCountdown } from '../lib/useCountdown'
import type { Order, Payment } from '../types'

function checkoutReturnAlert(
  order: Order,
  payment: Payment | null,
): { tone: 'info' | 'success' | 'warning' | 'danger'; text: string } {
  if (order.status === 'PAID' || payment?.status === 'APPROVED') {
    return {
      tone: 'success',
      text: 'Pago aprobado: los números quedaron vendidos.',
    }
  }

  if (order.status === 'REQUIRES_REFUND' || payment?.status === 'REFUND_REQUIRED') {
    return {
      tone: 'danger',
      text: 'El pago se confirmó, pero la orden requiere un reembolso manual. Un administrador debe gestionarlo.',
    }
  }

  if (order.status === 'PAYMENT_REVIEW' || payment?.status === 'UNKNOWN') {
    return {
      tone: 'warning',
      text: 'El resultado del pago es incierto y la orden está en revisión. Los números siguen reservados.',
    }
  }

  if (order.status === 'EXPIRED') {
    return {
      tone: 'warning',
      text: 'La reserva venció sin un pago confirmado; los números volvieron a estar disponibles. Puedes iniciar una nueva reserva.',
    }
  }

  if (order.status === 'REJECTED' || payment?.status === 'REJECTED') {
    return {
      tone: 'warning',
      text: 'El proveedor rechazó el pago y los números fueron liberados. Puedes elegir otros números disponibles o intentar con otro medio de pago.',
    }
  }

  if (payment?.status === 'PENDING') {
    return {
      tone: 'info',
      text: 'Todavía no hay confirmación del proveedor. Puedes consultar el estado nuevamente en unos segundos.',
    }
  }

  return {
    tone: 'info',
    text: 'Volviste de Mercado Pago. La confirmación se obtiene en el servidor; la URL de retorno no marca la orden como pagada.',
  }
}

export default function OrderDetailPage() {
  const { orderId } = useParams()
  const [searchParams] = useSearchParams()
  const toast = useToast()
  const [order, setOrder] = useState<Order | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [paymentError, setPaymentError] = useState<string | null>(null)
  const [paymentSubmitting, setPaymentSubmitting] = useState(false)
  const [reloadToken, setReloadToken] = useState(0)
  const countdown = useCountdown(order?.expires_at ?? null, order?.created_at ?? null)
  // The return URL is only a hint. It never marks the order as paid: the
  // backend verifies the payment with the provider and applies the state.
  const returnedFromCheckout = searchParams.get('pago') === 'retorno'
  const reservationExpired = countdown?.expired ?? false
  const autoVerifiedPayment = useRef<string | null>(null)
  const [returnCheck, setReturnCheck] = useState<'checking' | 'done' | 'idle'>('idle')
  const refreshing = loading && order !== null

  // On a Mercado Pago return, ask the backend to verify the payment once. The
  // response is the authoritative state; a 409/503 keeps the current order on
  // screen instead of clearing the session or faking an approval.
  useEffect(() => {
    if (!returnedFromCheckout || order === null) {
      return
    }

    const payments = order.payments ?? []
    const latest = payments.length > 0 ? payments[payments.length - 1] : null

    if (latest === null || !['PENDING', 'UNKNOWN'].includes(latest.status)) {
      return
    }

    if (!['PENDING_PAYMENT', 'EXPIRED', 'REJECTED'].includes(order.status)) {
      return
    }

    const key = `${order.id}:${latest.id}`

    if (autoVerifiedPayment.current === key) {
      return
    }

    autoVerifiedPayment.current = key
    let active = true
    setReturnCheck('checking')

    api
      .post<{ data: Order }>(`/orders/${order.id}/payments/${latest.id}/verify`)
      .then((response) => {
        if (active) {
          const nextOrder = response.data.data
          setOrder(nextOrder)
          setPaymentError(null)

          if (nextOrder.status === 'PAID') {
            toast.success('Pago confirmado: los números quedaron vendidos.')
          }
        }
      })
      .catch(async (caught) => {
        if (!active) {
          return
        }

        if (axios.isAxiosError(caught) && caught.response?.status === 409) {
          // The order moved to review (or is no longer verifiable). Read the
          // current backend state instead of showing a conflict error.
          try {
            const response = await api.get<{ data: Order }>(`/orders/${order.id}`)

            if (active) {
              setOrder(response.data.data)
            }
          } catch {
            // Keep the current state; the manual refresh button remains.
          }

          return
        }

        if (
          !axios.isAxiosError(caught) ||
          caught.response === undefined ||
          caught.response.status >= 500
        ) {
          setPaymentError(
            'No pudimos confirmar el pago en este momento. Tu sesión y tu reserva siguen intactas; puedes reintentar.',
          )
        }
      })
      .finally(() => {
        if (active) {
          setReturnCheck('done')
        }
      })

    return () => {
      active = false
    }
  }, [returnedFromCheckout, order, toast])

  // The countdown is cosmetic; the backend owns expiry. Once it reaches zero,
  // poll the order until the scheduler has released (or kept) the numbers.
  useEffect(() => {
    if (order === null || order.status !== 'PENDING_PAYMENT' || !reservationExpired) {
      return
    }

    let active = true
    let attempts = 0

    const timer = window.setInterval(() => {
      attempts += 1

      api
        .get<{ data: Order }>(`/orders/${order.id}`)
        .then((response) => {
          if (!active) {
            return
          }

          setOrder(response.data.data)

          if (response.data.data.status !== 'PENDING_PAYMENT' || attempts >= 12) {
            window.clearInterval(timer)
          }
        })
        .catch(() => {
          if (!active || attempts >= 12) {
            window.clearInterval(timer)
          }
        })
    }, 10000)

    return () => {
      active = false
      window.clearInterval(timer)
    }
  }, [order?.id, order?.status, reservationExpired])

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
    return <PageSkeleton variant="detail" label="Cargando orden…" />
  }

  if (!order) {
    return (
      <ErrorState
        title="No pudimos cargar la orden"
        message={error ?? 'No se encontró la orden.'}
        onRetry={() => setReloadToken((value) => value + 1)}
        retrying={loading}
        secondaryAction={
          <Link className="button button-ghost" to="/orders">
            Volver a Mis compras
          </Link>
        }
      />
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
    (order.status === 'PENDING_PAYMENT' || order.status === 'EXPIRED'
      ? ['PENDING', 'UNKNOWN', 'REJECTED'].includes(latestPayment.status)
      : (order.status === 'REJECTED' && latestPayment.status === 'REJECTED'))
  const resumeCheckoutUrl = latestPayment?.checkout_url ?? null
  const canResumeCheckout =
    resumeCheckoutUrl !== null &&
    latestPayment?.status === 'PENDING' &&
    latestPayment.simulated !== true &&
    order.status === 'PENDING_PAYMENT'
  const returnAlert = checkoutReturnAlert(order, latestPayment)
  const numberCounts = countNumbers(order.numbers)
  const canRetryPurchase = order.status === 'REJECTED' || order.status === 'EXPIRED'

  const startPayment = async () => {
    setPaymentSubmitting(true)
    setPaymentError(null)

    try {
      const response = await api.post<{ data: Order }>(`/orders/${order.id}/payments`)
      const updatedOrder = response.data.data
      const createdPayment = updatedOrder.payments?.[0]

      setOrder(updatedOrder)

      // Only the backend-created checkout URL is used. The fake gateway stays
      // in the current page and exposes its verify button instead.
      if (createdPayment?.checkout_url && createdPayment.simulated !== true) {
        window.location.assign(createdPayment.checkout_url)
      }
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
      const nextOrder = response.data.data
      setOrder(nextOrder)

      switch (nextOrder.status) {
        case 'PAID':
          toast.success('Pago confirmado: los números quedaron vendidos.')
          break
        case 'PAYMENT_REVIEW':
          toast.warning('El pago quedó en revisión. Los números siguen reservados.')
          break
        case 'REJECTED':
          toast.warning('El pago fue rechazado y los números fueron liberados.')
          break
        case 'REQUIRES_REFUND':
          toast.error('El pago requiere reembolso. Un administrador debe gestionarlo.')
          break
        default:
          toast.info('Todavía no hay confirmación del pago. Puedes volver a consultar.')
      }
    } catch (caught) {
      setPaymentError(apiErrorMessage(caught))
    } finally {
      setPaymentSubmitting(false)
    }
  }

  return (
    <section>
      <p className="back-link">
        <Link to="/orders">← Mis compras</Link>
        {order.raffle && (
          <Link to={`/raffles/${order.raffle.id}`}> · Volver a {order.raffle.title}</Link>
        )}
      </p>

      {error && <Alert tone="danger">{error}</Alert>}

      {returnedFromCheckout && (
        <Alert tone={returnAlert.tone}>
          {returnCheck === 'checking' ? (
            <span className="return-check">
              <Spinner size="sm" />
              Consultando el estado real del pago con el proveedor…
            </span>
          ) : (
            returnAlert.text
          )}
        </Alert>
      )}

      <header className="order-hero">
        <div>
          <p className="page-header-eyebrow">Orden</p>
          <h1>Orden {order.order_number}</h1>
          <div className="order-hero-status">
            <StatusPill status={order.status} label={ORDER_STATUS_LABELS[order.status]} />
            <span className="muted order-status-note">{ORDER_STATUS_HINT[order.status]}</span>
          </div>
        </div>

        <Button
          variant="secondary"
          size="sm"
          onClick={() => setReloadToken((value) => value + 1)}
          loading={refreshing}
        >
          Actualizar estado
        </Button>
      </header>

      <Card className={`order-summary order-summary--${order.status.toLowerCase()}`}>
        <OrderProgress order={order} />

        <dl className="facts">
          <div>
            <dt>Total</dt>
            <dd className="raffle-price">{formatCents(order.total_amount)}</dd>
          </div>
          <div>
            <dt>Números</dt>
            <dd>{order.numbers?.length ?? 0}</dd>
          </div>
          <div>
            <dt>Creada</dt>
            <dd>{formatDate(order.created_at)}</dd>
          </div>
          {order.paid_at && (
            <div>
              <dt>Pagada</dt>
              <dd>{formatDate(order.paid_at)}</dd>
            </div>
          )}
        </dl>

        {order.status === 'PENDING_PAYMENT' && order.expires_at && (
          <p className="muted order-expires">Vence: {formatDate(order.expires_at)}</p>
        )}
      </Card>

      {order.status === 'PENDING_PAYMENT' && countdown && (
        <Card className="reservation-card">
          <ReservationTimer countdown={countdown} />
        </Card>
      )}

      <Card className={`payment-panel payment-panel--${order.status.toLowerCase()}`}>
        <header className="payment-panel-head">
          <h2>Pago</h2>
          {latestPayment ? (
            <StatusPill
              status={latestPayment.status}
              label={PAYMENT_STATUS_LABELS[latestPayment.status]}
            />
          ) : (
            <span className="muted">Sin pago iniciado</span>
          )}
        </header>

        {showSimulatedNotice && (
          <p className="simulated-notice">
            <Badge tone="accent">SIMULADO</Badge>
            Este entorno usa el gateway de pruebas. No se procesa dinero real.
          </p>
        )}

        {latestPayment ? (
          <>
            <dl className="payment-meta">
              <div>
                <dt>Medio</dt>
                <dd>
                  {paymentIsSimulated
                    ? 'Simulación local (sin cobro real)'
                    : latestPayment.provider === 'MERCADOPAGO'
                      ? 'Mercado Pago'
                      : latestPayment.provider}
                </dd>
              </div>
              <div>
                <dt>Importe</dt>
                <dd>
                  {formatCents(latestPayment.amount)} {latestPayment.currency}
                </dd>
              </div>
              {latestPayment.verified_at && (
                <div>
                  <dt>Última verificación</dt>
                  <dd>{formatDate(latestPayment.verified_at)}</dd>
                </div>
              )}
            </dl>

            {latestPayment.status === 'PENDING' && (
              <p className="muted">
                El pago fue iniciado pero todavía no está confirmado. Verifica el resultado con
                el proveedor.
              </p>
            )}

            {latestPayment.status === 'UNKNOWN' && (
              <Alert tone="warning">
                El resultado del pago es incierto. Los números siguen reservados y no se
                iniciará otro cobro.{' '}
                {order.status === 'PAYMENT_REVIEW'
                  ? 'Un administrador debe resolver esta incidencia.'
                  : 'La orden requiere revisión.'}
              </Alert>
            )}

            {latestPayment.status === 'APPROVED' && (
              <p className="muted">
                {paymentIsSimulated
                  ? 'La simulación aprobó el pago y los números quedaron vendidos. No se procesó dinero real.'
                  : 'El pago fue aprobado y los números quedaron vendidos.'}
              </p>
            )}

            {latestPayment.status === 'REJECTED' && (
              <p className="muted">
                El pago fue rechazado y los números fueron liberados. Puedes elegir números de
                nuevo desde la rifa o probar otro medio de pago.
              </p>
            )}

            {latestPayment.status === 'REFUND_REQUIRED' && (
              <Alert tone="danger">
                El pago fue confirmado pero los números no pueden asignarse. La orden requiere
                que un administrador gestione el reembolso; todavía no se ha completado.
              </Alert>
            )}
          </>
        ) : (
          <p className="muted">
            Todavía no se inició un pago para esta orden.
            {order.status === 'PENDING_PAYMENT' &&
              ' Los números siguen reservados hasta que venza el plazo.'}
          </p>
        )}

        {paymentError && <Alert tone="danger">{paymentError}</Alert>}

        {(canStartPayment || canVerifyPayment || canResumeCheckout) && (
          <div className="payment-actions">
            {canStartPayment && (
              <Button onClick={startPayment} loading={paymentSubmitting}>
                {paymentSubmitting ? 'Iniciando…' : 'Iniciar pago'}
              </Button>
            )}
            {canResumeCheckout && resumeCheckoutUrl && (
              <Button
                variant="secondary"
                onClick={() => window.location.assign(resumeCheckoutUrl)}
              >
                Continuar en Mercado Pago
              </Button>
            )}
            {canVerifyPayment && (
              <Button onClick={verifyPayment} loading={paymentSubmitting}>
                {paymentSubmitting
                  ? 'Consultando…'
                  : latestPayment?.status === 'PENDING'
                    ? 'Verificar pago'
                    : 'Consultar estado'}
              </Button>
            )}
          </div>
        )}

        <p className="muted payment-note">
          La confirmación se obtiene y valida en el servidor. Una redirección del navegador no
          marca la orden como pagada.
        </p>
      </Card>

      {canRetryPurchase && (
        <Card as="section" className="payment-recovery" aria-labelledby="payment-recovery-heading">
          <p className="page-header-eyebrow">Nueva oportunidad</p>
          <h2 id="payment-recovery-heading">
            {order.status === 'REJECTED' ? 'El pago no se completó' : 'La reserva venció'}
          </h2>
          <p className="muted">
            {order.status === 'REJECTED'
              ? 'El proveedor rechazó el pago y los números volvieron a estar disponibles. Puedes elegir otros números de esta rifa o probar con otra rifa.'
              : 'La reserva venció sin un pago confirmado y los números volvieron a estar disponibles. Puedes iniciar una nueva reserva cuando quieras.'}
          </p>
          <div className="payment-actions">
            {order.raffle && (
              <Link className="button" to={`/raffles/${order.raffle.id}`}>
                Elegir números de nuevo
              </Link>
            )}
            <Link className="button button-ghost" to="/">
              Ver otras rifas
            </Link>
          </div>
        </Card>
      )}

      <Card className="order-numbers">
        <header className="order-numbers-head">
          <h2>{numbersHeading(order.status)}</h2>
          <span className="muted">
            {order.numbers?.length ?? 0}{' '}
            {(order.numbers?.length ?? 0) === 1 ? 'número' : 'números'}
          </span>
        </header>

        {(order.numbers?.length ?? 0) > 0 && (
          <div className="order-numbers-legend">
            {numberCounts.sold > 0 && (
              <Badge tone="neutral">Vendidos: {numberCounts.sold}</Badge>
            )}
            {numberCounts.reserved > 0 && (
              <Badge tone="warning">Reservados: {numberCounts.reserved}</Badge>
            )}
            {numberCounts.available > 0 && (
              <Badge tone="success">Disponibles: {numberCounts.available}</Badge>
            )}
          </div>
        )}

        <div className="number-grid">
          {order.numbers?.map((item) => (
            <span
              key={item.id}
              className={`number ${item.status.toLowerCase()}`}
              title={`Número ${item.number}: ${NUMBER_STATUS_LABELS[item.status]}`}
              aria-label={`Número ${item.number}: ${NUMBER_STATUS_LABELS[item.status]}`}
            >
              {item.number}
            </span>
          ))}
        </div>

        <p className="muted">{numbersSummary(order.numbers)}</p>
      </Card>
    </section>
  )
}
