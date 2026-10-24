import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { formatCents } from '../lib/format'
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
      <header className="page-header">
        <div>
          <h1>Rifas disponibles</h1>
          <p className="muted">Consulta las rifas publicadas y sus números.</p>
        </div>
      </header>

      {error && <p className="alert">{error}</p>}
      {loading && <p className="muted">Cargando rifas…</p>}

      {!loading && !error && raffles.length === 0 && (
        <p className="muted">Todavía no hay rifas publicadas.</p>
      )}

      <div className="grid">
        {raffles.map((raffle) => (
          <article key={raffle.id} className="card">
            <h2>{raffle.title}</h2>
            {raffle.description && <p>{raffle.description}</p>}
            <dl className="facts">
              <div>
                <dt>Precio por número</dt>
                <dd>{formatCents(raffle.price_per_number)}</dd>
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
            <Link className="button" to={`/raffles/${raffle.id}`}>
              Ver números
            </Link>
          </article>
        ))}
      </div>
    </section>
  )
}
