import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { OrderReservationTimer } from '../components/ReservationTimer'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import EmptyState from '../components/ui/EmptyState'
import ErrorState from '../components/ui/ErrorState'
import Icon from '../components/ui/Icon'
import PageHeader from '../components/ui/PageHeader'
import PageSkeleton from '../components/ui/PageSkeleton'
import StatusPill from '../components/ui/StatusPill'
import { formatCop, formatDate } from '../lib/format'
import {
  NUMBER_STATUS_LABELS,
  ORDER_STATUS_HINT,
  ORDER_STATUS_LABELS,
  PAYMENT_STATUS_LABELS,
  numbersSummary,
} from '../lib/orders'
import type { Order, PaginatedResponse } from '../types'

const PER_PAGE = 10

function paymentLabel(order: Order): string {
  const payments = order.payments ?? []

  if (payments.length === 0) {
    return 'Sin iniciar'
  }

  return PAYMENT_STATUS_LABELS[payments[payments.length - 1].status]
}

/**
 * "Mis compras": the authenticated user's order history.
 *
 * Everything comes from GET /api/orders (owner-scoped) and the status shown is
 * the real backend state. The page never infers a purchase from URL parameters
 * and never marks a number as acquired unless its status is SOLD.
 */
export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([])
  const [meta, setMeta] = useState<PaginatedResponse<Order>['meta'] | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async (page: number, append: boolean) => {
    if (append) {
      setLoadingMore(true)
    } else {
      setLoading(true)
    }

    try {
      const response = await api.get<PaginatedResponse<Order>>('/orders', {
        params: { page, per_page: PER_PAGE },
      })

      setOrders((current) =>
        append ? [...current, ...response.data.data] : response.data.data,
      )
      setMeta(response.data.meta)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
      setLoadingMore(false)
    }
  }, [])

  useEffect(() => {
    void load(1, false)
  }, [load])

  const header = (
    <PageHeader
      eyebrow="Cuenta"
      title="Mis compras"
      description={
        meta
          ? `${meta.total} ${meta.total === 1 ? 'orden registrada' : 'órdenes registradas'}`
          : 'Consulta tus órdenes, números y estados de pago.'
      }
      actions={
        <Button
          variant="secondary"
          size="sm"
          onClick={() => void load(1, false)}
          loading={loading && orders.length > 0}
        >
          Actualizar
        </Button>
      }
    />
  )

  if (loading && orders.length === 0) {
    return (
      <>
        {header}
        <PageSkeleton variant="list" label="Cargando compras…" count={2} />
      </>
    )
  }

  if (error && orders.length === 0) {
    return (
      <>
        {header}
        <ErrorState
          title="No pudimos cargar tus compras"
          message={error}
          onRetry={() => void load(1, false)}
          retrying={loading}
          secondaryAction={
            <Link className="button button-ghost" to="/">
              Volver al catálogo
            </Link>
          }
        />
      </>
    )
  }

  if (orders.length === 0) {
    return (
      <>
        {header}
        <EmptyState
          icon={<Icon name="bag" size={30} />}
          title="Aún no tienes compras"
          description="Cuando reserves y pagues una rifa, la orden aparecerá aquí con sus números y estado."
          action={
            <div className="empty-actions">
              <Link className="button" to="/">
                Explorar rifas
              </Link>
              <Button variant="secondary" onClick={() => void load(1, false)}>
                Actualizar
              </Button>
            </div>
          }
        />
      </>
    )
  }

  return (
    <section aria-busy={loading}>
      {header}

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="orders-list">
        {orders.map((order) => (
          <Card
            as="article"
            key={order.id}
            className={`order-summary order-history-card order-history-card--${order.status.toLowerCase()}`}
          >
            <header className="order-hero">
              <div>
                <p className="page-header-eyebrow">
                  Orden {order.order_number} · #{order.id}
                </p>
                <h2>{order.raffle?.title ?? 'Rifa'}</h2>
                <div className="order-hero-status">
                  <StatusPill
                    status={order.status}
                    label={ORDER_STATUS_LABELS[order.status]}
                  />
                  <span className="muted">
                    {order.paid_at
                      ? `Pagada ${formatDate(order.paid_at)}`
                      : `Creada ${formatDate(order.created_at)}`}
                  </span>
                </div>

                {order.status === 'PENDING_PAYMENT' && (
                  <OrderReservationTimer
                    expiresAt={order.expires_at}
                    createdAt={order.created_at}
                  />
                )}
              </div>

              <Link className="button button-ghost order-history-cta" to={`/orders/${order.id}`}>
                Ver detalle
              </Link>
            </header>

            <dl className="facts">
              <div>
                <dt>Total</dt>
                <dd className="raffle-price">{formatCop(order.total_amount)}</dd>
              </div>
              <div>
                <dt>Números</dt>
                <dd>{order.numbers?.length ?? 0}</dd>
              </div>
              <div>
                <dt>Pago</dt>
                <dd className="order-payment-state">{paymentLabel(order)}</dd>
              </div>
            </dl>

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
            <p className="muted order-hint">{ORDER_STATUS_HINT[order.status]}</p>
          </Card>
        ))}
      </div>

      {meta && meta.current_page < meta.last_page && (
        <div className="orders-more">
          <p className="muted">
            Mostrando {orders.length} de {meta.total} órdenes
          </p>
          <Button
            variant="secondary"
            loading={loadingMore}
            onClick={() => void load(meta.current_page + 1, true)}
          >
            {loadingMore ? 'Cargando…' : 'Cargar más compras'}
          </Button>
        </div>
      )}
    </section>
  )
}
