import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { formatCents } from '../lib/format'
import Alert from '../components/ui/Alert'
import Card from '../components/ui/Card'
import EmptyState from '../components/ui/EmptyState'
import PageHeader from '../components/ui/PageHeader'
import Skeleton from '../components/ui/Skeleton'
import StatusPill from '../components/ui/StatusPill'
import type { PaginatedResponse, Raffle } from '../types'

export default function CatalogPage() {
  const [raffles, setRaffles] = useState<Raffle[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true

    api
      .get<PaginatedResponse<Raffle>>('/raffles')
      .then((response) => {
        if (active) {
          setRaffles(response.data.data)
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
  }, [])

  return (
    <section>
      <PageHeader
        eyebrow="Catálogo"
        title="Rifas disponibles"
        description="Consulta las rifas publicadas y sus números."
      />

      {error && <Alert tone="danger">{error}</Alert>}

      {loading && (
        <>
          <p className="ui-sr-only" role="status">
            Cargando rifas…
          </p>
          <div className="grid" aria-hidden="true">
            {[0, 1, 2].map((placeholder) => (
              <Card key={placeholder} className="raffle-card raffle-card-skeleton">
                <Skeleton width="70%" height={22} />
                <Skeleton lines={2} />
                <Skeleton width="45%" height={30} />
                <Skeleton height={42} radius={10} />
              </Card>
            ))}
          </div>
        </>
      )}

      {!loading && !error && raffles.length === 0 && (
        <EmptyState
          title="Todavía no hay rifas publicadas."
          description="Cuando se publique una rifa aparecerá aquí con su precio y su rango de números."
        />
      )}

      {!loading && raffles.length > 0 && (
        <div className="grid">
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
