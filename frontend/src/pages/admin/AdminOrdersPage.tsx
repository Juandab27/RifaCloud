import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import { useToast } from '../../components/ToastProvider'
import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import EmptyState from '../../components/ui/EmptyState'
import PageHeader from '../../components/ui/PageHeader'
import Skeleton from '../../components/ui/Skeleton'
import StatusPill from '../../components/ui/StatusPill'
import { formatCents, formatDate } from '../../lib/format'
import {
  ORDER_STATUS_LABELS,
  PAYMENT_STATUS_LABELS,
} from '../../lib/orders'
import type {
  AdminOrder,
  OrderStatus,
  PaginatedResponse,
  PaymentIncident,
  PaymentState,
} from '../../types'

type Tab = 'orders' | 'incidents'

const PER_PAGE = 15

const ORDER_STATUSES: OrderStatus[] = [
  'PENDING_PAYMENT',
  'PAID',
  'REJECTED',
  'EXPIRED',
  'PAYMENT_REVIEW',
  'REQUIRES_REFUND',
]

const INCIDENT_STATUSES: PaymentState[] = ['UNKNOWN', 'REFUND_REQUIRED']

export default function AdminOrdersPage() {
  const toast = useToast()
  const [tab, setTab] = useState<Tab>('orders')
  const [orders, setOrders] = useState<AdminOrder[]>([])
  const [incidents, setIncidents] = useState<PaymentIncident[]>([])
  const [meta, setMeta] = useState<PaginatedResponse<AdminOrder>['meta'] | null>(null)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyIncident, setBusyIncident] = useState<number | null>(null)

  const loadOrders = useCallback(async () => {
    setLoading(true)

    try {
      const response = await api.get<PaginatedResponse<AdminOrder>>('/admin/orders', {
        params: { page, per_page: PER_PAGE, status: status || undefined },
      })
      setOrders(response.data.data)
      setMeta(response.data.meta)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [page, status])

  const loadIncidents = useCallback(async () => {
    setLoading(true)

    try {
      const response = await api.get<PaginatedResponse<PaymentIncident>>(
        '/admin/payments/incidents',
        {
          params: { page, per_page: PER_PAGE, status: status || undefined },
        },
      )
      setIncidents(response.data.data)
      setMeta(response.data.meta)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [page, status])

  const load = useCallback(async () => {
    if (tab === 'orders') {
      await loadOrders()
    } else {
      await loadIncidents()
    }
  }, [tab, loadOrders, loadIncidents])

  useEffect(() => {
    void load()
  }, [load])

  function changeTab(next: Tab) {
    setTab(next)
    setPage(1)
    setStatus('')
    setError(null)
  }

  async function resolveIncident(incident: PaymentIncident) {
    if (
      !window.confirm(
        `¿Resolver la incidencia de pago de la orden ${incident.order_number}? Se consultará el estado real en el proveedor.`,
      )
    ) {
      return
    }

    setBusyIncident(incident.id)

    try {
      await api.post(
        `/admin/orders/${incident.order_id}/payments/${incident.id}/resolve`,
      )
      toast.success('Incidencia resuelta con el estado verificado del proveedor.')
      await loadIncidents()
    } catch (caught) {
      const message = apiErrorMessage(caught)
      setError(message)
      toast.error(message)
    } finally {
      setBusyIncident(null)
    }
  }

  const rows = tab === 'orders' ? orders : incidents
  const isEmpty = !loading && rows.length === 0

  return (
    <section>
      <PageHeader
        eyebrow="Administración"
        title="Órdenes e incidencias"
        description="Consulta todas las órdenes y resuelve incidencias de pago con las acciones existentes."
        actions={
          <div className="page-header-actions">
            <Link className="button button-ghost" to="/admin/dashboard">
              Dashboard
            </Link>
            <Link className="button button-ghost" to="/admin/raffles">
              Administrar rifas
            </Link>
          </div>
        }
      />

      <div className="admin-tabs" role="group" aria-label="Sección de administración">
        <Button
          variant={tab === 'orders' ? 'primary' : 'secondary'}
          size="sm"
          onClick={() => changeTab('orders')}
          aria-pressed={tab === 'orders'}
        >
          Órdenes
        </Button>
        <Button
          variant={tab === 'incidents' ? 'primary' : 'secondary'}
          size="sm"
          onClick={() => changeTab('incidents')}
          aria-pressed={tab === 'incidents'}
        >
          Incidencias de pago
        </Button>
      </div>

      <div className="admin-filters">
        <label className="admin-filter">
          <span>Filtrar por estado</span>
          <select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            <option value="">Todos</option>
            {(tab === 'orders' ? ORDER_STATUSES : INCIDENT_STATUSES).map((value) => (
              <option key={value} value={value}>
                {tab === 'orders'
                  ? ORDER_STATUS_LABELS[value as OrderStatus]
                  : PAYMENT_STATUS_LABELS[value as PaymentState]}
              </option>
            ))}
          </select>
        </label>
      </div>

      {error && <Alert tone="danger">{error}</Alert>}

      {loading && rows.length === 0 && (
        <>
          <p className="ui-sr-only" role="status">
            Cargando información administrativa…
          </p>
          <div className="admin-skeleton" aria-hidden="true">
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={72} radius={16} />
            ))}
          </div>
        </>
      )}

      {isEmpty && (
        <EmptyState
          title={tab === 'orders' ? 'No hay órdenes para este filtro.' : 'No hay incidencias pendientes.'}
          description={
            tab === 'orders'
              ? 'Prueba con otro estado o limpia el filtro.'
              : 'Los pagos en revisión o con reembolso pendiente aparecerán aquí.'
          }
        />
      )}

      {rows.length > 0 && tab === 'orders' && (
        <div className="table-wrapper admin-table">
          <table>
            <caption className="ui-sr-only">Órdenes de todos los compradores</caption>
            <thead>
              <tr>
                <th scope="col">Orden</th>
                <th scope="col">Rifa</th>
                <th scope="col">Comprador</th>
                <th scope="col">Total</th>
                <th scope="col">Estado</th>
                <th scope="col">Pago</th>
                <th scope="col">Creada</th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => (
                <tr key={order.id}>
                  <td className="admin-cell-title" data-label="Orden">
                    {order.order_number}
                  </td>
                  <td data-label="Rifa">{order.raffle?.title ?? '—'}</td>
                  <td data-label="Comprador">
                    <span className="admin-cell-stack">
                      <strong>{order.customer.name}</strong>
                      <span className="muted">{order.customer.email}</span>
                    </span>
                  </td>
                  <td data-label="Total">{formatCents(order.total_amount)}</td>
                  <td data-label="Estado">
                    <StatusPill
                      status={order.status}
                      label={ORDER_STATUS_LABELS[order.status]}
                    />
                  </td>
                  <td data-label="Pago">
                    {order.latest_payment_status
                      ? PAYMENT_STATUS_LABELS[order.latest_payment_status]
                      : 'Sin iniciar'}
                  </td>
                  <td data-label="Creada">{formatDate(order.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {rows.length > 0 && tab === 'incidents' && (
        <div className="table-wrapper admin-table">
          <table>
            <caption className="ui-sr-only">Incidencias de pago</caption>
            <thead>
              <tr>
                <th scope="col">Orden</th>
                <th scope="col">Rifa</th>
                <th scope="col">Comprador</th>
                <th scope="col">Importe</th>
                <th scope="col">Incidencia</th>
                <th scope="col">Detectada</th>
                <th scope="col">Acción</th>
              </tr>
            </thead>
            <tbody>
              {incidents.map((incident) => (
                <tr key={incident.id}>
                  <td className="admin-cell-title" data-label="Orden">
                    {incident.order_number}
                  </td>
                  <td data-label="Rifa">{incident.raffle.title}</td>
                  <td data-label="Comprador">
                    <span className="admin-cell-stack">
                      <strong>{incident.customer.name}</strong>
                      <span className="muted">{incident.customer.email}</span>
                    </span>
                  </td>
                  <td data-label="Importe">
                    {formatCents(incident.amount)} {incident.currency}
                  </td>
                  <td data-label="Incidencia">
                    <StatusPill
                      status={incident.status}
                      label={PAYMENT_STATUS_LABELS[incident.status]}
                    />
                  </td>
                  <td data-label="Detectada">{formatDate(incident.created_at)}</td>
                  <td className="actions" data-label="Acción">
                    <Button
                      size="sm"
                      loading={busyIncident === incident.id}
                      onClick={() => void resolveIncident(incident)}
                    >
                      Resolver con proveedor
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="pagination admin-pagination">
          <Button
            variant="secondary"
            size="sm"
            disabled={meta.current_page <= 1 || loading}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Anterior
          </Button>
          <span className="muted">
            Página {meta.current_page} de {meta.last_page} · {meta.total} registros
          </span>
          <Button
            variant="secondary"
            size="sm"
            disabled={meta.current_page >= meta.last_page || loading}
            onClick={() => setPage((current) => current + 1)}
          >
            Siguiente
          </Button>
        </div>
      )}

      {loading && rows.length > 0 && (
        <p className="ui-sr-only" role="status">
          Actualizando información administrativa…
        </p>
      )}
    </section>
  )
}
