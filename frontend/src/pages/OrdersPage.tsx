import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import EmptyState from '../components/ui/EmptyState'
import PageHeader from '../components/ui/PageHeader'
import Skeleton from '../components/ui/Skeleton'
import StatusPill from '../components/ui/StatusPill'
import { formatCents, formatDate } from '../lib/format'
import {
  NUMBER_STATUS_LABELS,
  ORDER_STATUS_HINT,
  ORDER_STATUS_LABELS,
  numbersSummary,
} from '../lib/orders'
import type { Order, PaginatedResponse } from '../types'

const PER_PAGE = 10

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
    />
  )

  if (loading && orders.length === 0) {
    return (
      <>
        {header}
        <p className="ui-sr-only" role="status">
          Cargando compras…
        </p>
        <div className="order-loading" aria-hidden="true">
          <Skeleton height={120} radius={16} />
          <Skeleton height={120} radius={16} />
          <Skeleton lines={3} />
        </div>
      </>
    )
  }

  if (error && orders.length === 0) {
    return (
      <>
        {header}
        <Card narrow>
          <Alert tone="danger">{error}</Alert>
          <div className="payment-actions">
            <Button onClick={() => void load(1, false)}>Reintentar</Button>
            <Link className="button button-ghost" to="/">
              Volver al catálogo
            </Link>
          </div>
        </Card>
      </>
    )
  }

  if (orders.length === 0) {
    return (
      <>
        {header}
        <EmptyState
          title="Aún no tienes compras"
          description="Cuando reserves y pagues una rifa, la orden aparecerá aquí con sus números y estado."
          action={
            <Link className="button" to="/">
              Explorar rifas
            </Link>
          }
        />
      </>
    )
  }

  return (
    <section>
      {header}

      {error && <Alert tone="danger">{error}</Alert>}

      <div className="orders-list">
        {orders.map((order) => (
          <Card as="article" key={order.id} className="order-summary">
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
              </div>

              <Link className="button button-ghost" to={`/orders/${order.id}`}>
                Ver detalle
              </Link>
            </header>

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
                <dt>Estado</dt>
                <dd>{ORDER_STATUS_LABELS[order.status]}</dd>
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
            <p className="muted">{ORDER_STATUS_HINT[order.status]}</p>
          </Card>
        ))}
      </div>

      {meta && meta.current_page < meta.last_page && (
        <div className="payment-actions">
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
