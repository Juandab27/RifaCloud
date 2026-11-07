import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import Icon, { type IconName } from '../../components/ui/Icon'
import PageHeader from '../../components/ui/PageHeader'
import Skeleton from '../../components/ui/Skeleton'
import type {
  OrderMetrics,
  PaymentIncidentMetrics,
  RaffleMetrics,
} from '../../types'

interface MetricState<T> {
  data: T | null
  loading: boolean
  error: string | null
}

const initialMetric = <T,>(): MetricState<T> => ({
  data: null,
  loading: true,
  error: null,
})

async function fetchMetric<T>(path: string): Promise<MetricState<T>> {
  try {
    const response = await api.get<{ data: T }>(path)

    return { data: response.data.data, loading: false, error: null }
  } catch (caught) {
    return { data: null, loading: false, error: apiErrorMessage(caught) }
  }
}

interface MetricCardProps {
  label: string
  hint: string
  value: number | null
  loading: boolean
  error: string | null
  icon: IconName
  alert?: boolean
}

function MetricCard({
  label,
  hint,
  value,
  loading,
  error,
  icon,
  alert = false,
}: MetricCardProps) {
  const unavailable = error !== null
  const display = unavailable ? 'No disponible' : value === null ? '—' : value

  return (
    <Card
      as="article"
      className={[
        'metric-card',
        unavailable ? 'metric-card--unavailable' : '',
        alert && !unavailable ? 'metric-card--alert' : '',
      ]
        .filter(Boolean)
        .join(' ')}
      aria-busy={loading}
    >
      <div className="metric-card-head">
        <span className="metric-icon" aria-hidden="true">
          <Icon name={icon} size={20} />
        </span>
        {unavailable ? (
          <Badge tone="warning">No disponible</Badge>
        ) : loading && value === null ? (
          <Badge tone="neutral">Cargando</Badge>
        ) : null}
      </div>

      <p className="metric-label">{label}</p>

      {loading && value === null ? (
        <Skeleton width="45%" height={38} />
      ) : (
        <p className={['metric-value', unavailable ? 'is-unavailable' : ''].filter(Boolean).join(' ')}>
          {display}
        </p>
      )}

      <p className="metric-hint">{unavailable ? error : hint}</p>
    </Card>
  )
}

export default function AdminDashboardPage() {
  const [raffles, setRaffles] = useState<MetricState<RaffleMetrics>>(
    initialMetric<RaffleMetrics>,
  )
  const [orders, setOrders] = useState<MetricState<OrderMetrics>>(
    initialMetric<OrderMetrics>,
  )
  const [payments, setPayments] = useState<MetricState<PaymentIncidentMetrics>>(
    initialMetric<PaymentIncidentMetrics>,
  )

  const load = useCallback(async () => {
    setRaffles((current) => ({ ...current, loading: true, error: null }))
    setOrders((current) => ({ ...current, loading: true, error: null }))
    setPayments((current) => ({ ...current, loading: true, error: null }))

    const [nextRaffles, nextOrders, nextPayments] = await Promise.all([
      fetchMetric<RaffleMetrics>('/admin/raffles/metrics'),
      fetchMetric<OrderMetrics>('/admin/orders/metrics'),
      fetchMetric<PaymentIncidentMetrics>('/admin/payments/incidents/metrics'),
    ])

    setRaffles(nextRaffles)
    setOrders(nextOrders)
    setPayments(nextPayments)
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const states = [raffles, orders, payments]
  const refreshing = states.some((state) => state.loading)
  const allFailed = states.every((state) => state.error !== null)
  const failedServices = [
    raffles.error ? 'raffles-service' : null,
    orders.error ? 'orders-service' : null,
    payments.error ? 'payments-service' : null,
  ].filter((name): name is string => name !== null)

  const allZero =
    raffles.data !== null &&
    orders.data !== null &&
    payments.data !== null &&
    raffles.data.published_raffles === 0 &&
    orders.data.paid_orders === 0 &&
    orders.data.sold_numbers === 0 &&
    payments.data.pending_incidents === 0

  const header = (
    <PageHeader
      eyebrow="Administración"
      title="Dashboard"
      description="Indicadores operativos de rifas, ventas e incidencias pendientes."
      actions={
        <div className="page-header-actions">
          <Link className="button button-ghost" to="/admin/raffles">
            Administrar rifas
          </Link>
          <Link className="button button-ghost" to="/admin/orders">
            Órdenes e incidencias
          </Link>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => void load()}
            loading={refreshing}
          >
            Actualizar
          </Button>
        </div>
      }
    />
  )

  if (allFailed) {
    return (
      <>
        {header}
        <ErrorState
          title="No pudimos cargar el dashboard"
          message={
            raffles.error ??
            orders.error ??
            payments.error ??
            'Los servicios administrativos no están disponibles.'
          }
          onRetry={() => void load()}
          retrying={refreshing}
          secondaryAction={
            <Link className="button button-ghost" to="/admin/raffles">
              Volver a administración
            </Link>
          }
        />
      </>
    )
  }

  return (
    <section aria-busy={refreshing}>
      {header}

      {failedServices.length > 0 && (
        <Alert tone="warning" heading="Algunos indicadores no están disponibles">
          No respondió {failedServices.join(', ')}. Los valores mostrados son los confirmados
          por los demás servicios; no se muestran ceros inventados. Puedes reintentar con
          «Actualizar».
        </Alert>
      )}

      <div className="metric-grid" aria-live="polite">
        <MetricCard
          label="Rifas publicadas"
          hint="Rifas en estado PUBLISHED."
          value={raffles.data?.published_raffles ?? null}
          loading={raffles.loading}
          error={raffles.error}
          icon="ticket"
        />
        <MetricCard
          label="Órdenes pagadas"
          hint="Órdenes en estado PAID; no incluye pagos pendientes ni rechazados."
          value={orders.data?.paid_orders ?? null}
          loading={orders.loading}
          error={orders.error}
          icon="check"
        />
        <MetricCard
          label="Números vendidos"
          hint="Números en estado SOLD, solo tras un pago confirmado."
          value={orders.data?.sold_numbers ?? null}
          loading={orders.loading}
          error={orders.error}
          icon="bag"
        />
        <MetricCard
          label="Incidencias pendientes"
          hint="Pagos UNKNOWN o REFUND_REQUIRED que requieren revisión manual."
          value={payments.data?.pending_incidents ?? null}
          loading={payments.loading}
          error={payments.error}
          icon="warning"
          alert={(payments.data?.pending_incidents ?? 0) > 0}
        />
      </div>

      {allZero && (
        <EmptyState
          icon={<Icon name="info" size={30} />}
          title="Todavía no hay actividad"
          description="Cuando existan rifas publicadas, ventas confirmadas o incidencias, aparecerán aquí. Un cero mostrado siempre proviene de una respuesta confirmada del servicio propietario."
          action={
            <Link className="button" to="/admin/raffles">
              Crear o publicar una rifa
            </Link>
          }
        />
      )}

      <p className="muted dashboard-note">
        Cada indicador se calcula por consulta agregada en su servicio propietario. No se
        cargan órdenes ni números completos en el navegador.
      </p>
    </section>
  )
}
