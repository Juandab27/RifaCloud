import axios from 'axios'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { formatCents } from '../lib/format'
import type { NumberAvailabilityResponse, Order, Raffle } from '../types'

export default function RaffleDetailPage() {
  const { raffleId } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [raffle, setRaffle] = useState<Raffle | null>(null)
  const [numbers, setNumbers] = useState<NumberAvailabilityResponse | null>(null)
  const [page, setPage] = useState(1)
  const [reloadToken, setReloadToken] = useState(0)
  const [selectedNumbers, setSelectedNumbers] = useState<number[]>([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
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
  }, [raffleId, page, reloadToken])

  function toggleNumber(number: number) {
    if (!user) {
      setError('Debes iniciar sesión para reservar números.')
      return
    }

    if (
      raffle &&
      !selectedNumbers.includes(number) &&
      selectedNumbers.length >= raffle.max_numbers_per_order
    ) {
      setError(`Puedes reservar hasta ${raffle.max_numbers_per_order} números por orden.`)
      return
    }

    setError(null)
    setSelectedNumbers((current) =>
      current.includes(number)
        ? current.filter((selected) => selected !== number)
        : [...current, number].sort((left, right) => left - right),
    )
  }

  async function handleReserve() {
    if (!raffle || selectedNumbers.length === 0 || submitting) {
      return
    }

    if (!user) {
      setError('Debes iniciar sesión para reservar números.')
      return
    }

    setSubmitting(true)
    setError(null)

    try {
      const response = await api.post<{ data: Order }>('/orders', {
        raffle_id: raffle.id,
        numbers: selectedNumbers,
      })

      navigate(`/orders/${response.data.data.id}`)
    } catch (caught) {
      if (axios.isAxiosError(caught) && caught.response?.status === 409) {
        const data = caught.response.data as { unavailable_numbers?: number[] }
        const unavailable = data.unavailable_numbers ?? []

        if (unavailable.length > 0) {
          setSelectedNumbers((current) =>
            current.filter((number) => !unavailable.includes(number)),
          )
        }

        setReloadToken((current) => current + 1)
      }

      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  if (error && !raffle) {
    return (
      <section className="card narrow">
        <p className="alert">{error}</p>
        <Link to="/">Volver al catálogo</Link>
      </section>
    )
  }

  const totalAmount = raffle ? raffle.price_per_number * selectedNumbers.length : 0

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

      {error && <p className="alert">{error}</p>}

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
            {numbers.data.map((item) => {
              const selected = selectedNumbers.includes(item.number)

              return (
                <button
                  key={item.id}
                  type="button"
                  className={`number ${item.status.toLowerCase()}${selected ? ' selected' : ''}`}
                  disabled={item.status !== 'AVAILABLE'}
                  aria-pressed={selected}
                  aria-label={`Número ${item.number}`}
                  onClick={() => toggleNumber(item.number)}
                >
                  {item.number}
                </button>
              )
            })}
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

          <div className="selection-bar">
            <div>
              <strong>
                {selectedNumbers.length} {selectedNumbers.length === 1 ? 'número' : 'números'}
              </strong>{' '}
              <span className="muted">· Total {formatCents(totalAmount)}</span>
              <p className="muted selection-detail">
                {selectedNumbers.length === 0
                  ? 'Selecciona uno o más números disponibles.'
                  : `Seleccionados: ${selectedNumbers.join(', ')}`}
              </p>
              <p className="muted selection-detail">
                La reserva es temporal ({raffle?.reservation_minutes ?? 10} minutos); los números
                se liberan si no se completa el pago. Máximo {raffle?.max_numbers_per_order ?? 50}{' '}
                números por orden.
              </p>
            </div>

            <div className="selection-actions">
              {selectedNumbers.length > 0 && (
                <button
                  type="button"
                  className="link-button"
                  onClick={() => setSelectedNumbers([])}
                >
                  Limpiar
                </button>
              )}

              {user ? (
                <button
                  type="button"
                  disabled={selectedNumbers.length === 0 || submitting}
                  onClick={handleReserve}
                >
                  {submitting ? 'Reservando…' : 'Reservar números'}
                </button>
              ) : (
                <Link className="button" to="/login">
                  Inicia sesión para reservar
                </Link>
              )}
            </div>
          </div>
        </>
      )}
    </section>
  )
}
