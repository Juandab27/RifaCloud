import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { formatCents } from '../lib/format'
import type { NumberAvailabilityResponse, Raffle } from '../types'

export default function RaffleDetailPage() {
  const { raffleId } = useParams()
  const [raffle, setRaffle] = useState<Raffle | null>(null)
  const [numbers, setNumbers] = useState<NumberAvailabilityResponse | null>(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true

    api
      .get<{ data: Raffle }>(`/raffles/${raffleId}`)
      .then((response) => {
        if (active) {
          setRaffle(response.data.data)
        }
      })
      .catch((caught) => {
        if (active) {
          setError(apiErrorMessage(caught))
        }
      })

    return () => {
      active = false
    }
  }, [raffleId])

  useEffect(() => {
    let active = true

    setLoading(true)

    api
      .get<NumberAvailabilityResponse>(`/raffles/${raffleId}/numbers`, {
        params: { page, per_page: 100 },
      })
      .then((response) => {
        if (active) {
          setNumbers(response.data)
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
  }, [raffleId, page])

  if (error) {
    return (
      <section className="card narrow">
        <p className="alert">{error}</p>
        <Link to="/">Volver al catálogo</Link>
      </section>
    )
  }

  return (
    <section>
      <p>
        <Link to="/">← Volver al catálogo</Link>
      </p>

      {raffle && (
        <header className="page-header">
          <div>
            <h1>{raffle.title}</h1>
            {raffle.description && <p>{raffle.description}</p>}
            <p className="muted">
              {formatCents(raffle.price_per_number)} por número · Rango {raffle.min_number}–
              {raffle.max_number}
            </p>
          </div>
        </header>
      )}

      {numbers && (
        <p className="legend">
          <span className="badge available">Disponibles: {numbers.meta.counts.available}</span>
          <span className="badge reserved">Reservados: {numbers.meta.counts.reserved}</span>
          <span className="badge sold">Vendidos: {numbers.meta.counts.sold}</span>
        </p>
      )}

      {loading && <p className="muted">Cargando números…</p>}

      {numbers && (
        <>
          <div className="number-grid">
            {numbers.data.map((item) => (
              <span key={item.id} className={`number ${item.status.toLowerCase()}`}>
                {item.number}
              </span>
            ))}
          </div>

          <div className="pagination">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((current) => Math.max(current - 1, 1))}
            >
              Anterior
            </button>
            <span className="muted">
              Página {numbers.meta.current_page} de {numbers.meta.last_page}
            </span>
            <button
              type="button"
              disabled={page >= numbers.meta.last_page}
              onClick={() => setPage((current) => current + 1)}
            >
              Siguiente
            </button>
          </div>
        </>
      )}
    </section>
  )
}
