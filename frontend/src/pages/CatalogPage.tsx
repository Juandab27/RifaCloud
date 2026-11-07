import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import EmptyState from '../components/ui/EmptyState'
import ErrorState from '../components/ui/ErrorState'
import Icon from '../components/ui/Icon'
import PageHeader from '../components/ui/PageHeader'
import PageSkeleton from '../components/ui/PageSkeleton'
import StatusPill from '../components/ui/StatusPill'
import { formatCents, formatDate } from '../lib/format'
import type { PaginatedResponse, Raffle } from '../types'

export default function CatalogPage() {
  const [raffles, setRaffles] = useState<Raffle[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)

    try {
      const response = await api.get<PaginatedResponse<Raffle>>('/raffles')
      setRaffles(response.data.data)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const initialLoading = loading && raffles.length === 0

  return (
    <section aria-busy={loading}>
      <PageHeader
        eyebrow="Catálogo"
        title="Rifas disponibles"
        description="Consulta las rifas publicadas, su precio y sus números."
        actions={
          <Button variant="secondary" size="sm" onClick={() => void load()} loading={loading}>
            Actualizar
          </Button>
        }
      />

      {error && raffles.length === 0 && (
        <ErrorState
          title="No pudimos cargar el catálogo"
          message={error}
          onRetry={() => void load()}
          retrying={loading}
          secondaryAction={
            <Link className="button button-ghost" to="/">
              Volver al inicio
            </Link>
          }
        />
      )}

      {error && raffles.length > 0 && <Alert tone="danger">{error}</Alert>}

      {initialLoading && <PageSkeleton variant="catalog" label="Cargando rifas…" count={3} />}

      {!loading && !error && raffles.length === 0 && (
        <EmptyState
          icon={<Icon name="ticket" size={30} />}
          title="Todavía no hay rifas publicadas"
          description="Cuando se publique una rifa aparecerá aquí con su precio y su rango de números."
          action={
            <Button variant="secondary" onClick={() => void load()}>
              Actualizar
            </Button>
          }
        />
      )}

      {raffles.length > 0 && (
        <div className="grid" aria-busy={loading}>
          {raffles.map((raffle) => (
            <Card as="article" key={raffle.id} className="raffle-card">
              <div className="raffle-card-top">
                <h2 className="raffle-card-title">{raffle.title}</h2>
                <StatusPill status={raffle.status} />
              </div>

              {raffle.description && (
                <p className="raffle-card-description">{raffle.description}</p>
              )}

              <dl className="raffle-card-meta">
                <div>
                  <dt>Precio por número</dt>
                  <dd className="raffle-price">{formatCents(raffle.price_per_number)}</dd>
                </div>
                <div>
                  <dt>Números</dt>
                  <dd>
                    {raffle.min_number} – {raffle.max_number}
                  </dd>
                </div>
                <div>
                  <dt>Total</dt>
                  <dd>{raffle.total_numbers}</dd>
                </div>
              </dl>

              {raffle.ends_at && (
                <p className="raffle-card-closes">
                  <Icon name="info" size={14} />
                  Cierra el {formatDate(raffle.ends_at)}
                </p>
              )}

              <Link
                className="button raffle-card-cta"
                to={`/raffles/${raffle.id}`}
                aria-label={`Ver números de ${raffle.title}`}
              >
                Ver números
              </Link>
            </Card>
          ))}
        </div>
      )}
    </section>
  )
}
