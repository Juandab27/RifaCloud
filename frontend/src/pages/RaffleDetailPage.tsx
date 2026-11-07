import axios from 'axios'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/ToastProvider'
import Alert from '../components/ui/Alert'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import ErrorState from '../components/ui/ErrorState'
import Skeleton from '../components/ui/Skeleton'
import StatusPill from '../components/ui/StatusPill'
import { formatCents } from '../lib/format'
import type { NumberAvailabilityResponse, Order, Raffle } from '../types'

const NUMBER_STATE_LABEL = {
  AVAILABLE: 'disponible',
  RESERVED: 'reservado',
  SOLD: 'vendido',
} as const

export default function RaffleDetailPage() {
  const { raffleId } = useParams()
  const { user } = useAuth()
  const toast = useToast()
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
  }, [raffleId, reloadToken])

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

  function retryLoad() {
    setError(null)
    setReloadToken((current) => current + 1)
  }

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

      toast.success(
        `Reserva creada. Tienes ${raffle.reservation_minutes} minutos para completar el pago.`,
      )
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
      <section>
        <p className="back-link">
          <Link to="/">← Volver al catálogo</Link>
        </p>
        <ErrorState
          title="No pudimos cargar la rifa"
          message={error}
          onRetry={retryLoad}
          retrying={loading}
          secondaryAction={
            <Link className="button button-ghost" to="/">
              Volver al catálogo
            </Link>
          }
        />
      </section>
    )
  }

  const totalAmount = raffle ? raffle.price_per_number * selectedNumbers.length : 0

  return (
    <section>
      <p className="back-link">
        <Link to="/">← Volver al catálogo</Link>
      </p>

      {!raffle && loading && (
        <>
          <p className="ui-sr-only" role="status">
            Cargando rifa…
          </p>
          <div className="raffle-hero" aria-hidden="true">
            <Skeleton width="52%" height={36} />
            <Skeleton lines={2} />
            <div className="page-skeleton-grid">
              <Skeleton height={44} radius={10} />
              <Skeleton height={44} radius={10} />
              <Skeleton height={44} radius={10} />
            </div>
          </div>
        </>
      )}

      {raffle && (
        <header className="raffle-hero">
          <div className="raffle-hero-head">
            <h1>{raffle.title}</h1>
            <StatusPill status={raffle.status} />
          </div>

          {raffle.description && (
            <p className="raffle-hero-description">{raffle.description}</p>
          )}

          <dl className="facts raffle-facts">
            <div>
              <dt>Precio por número</dt>
              <dd className="raffle-price">{formatCents(raffle.price_per_number)}</dd>
            </div>
            <div>
              <dt>Rango de números</dt>
              <dd>
                {raffle.min_number} – {raffle.max_number}
              </dd>
            </div>
            <div>
              <dt>Total de números</dt>
              <dd>{raffle.total_numbers}</dd>
            </div>
          </dl>
        </header>
      )}

      {error && <Alert tone="danger">{error}</Alert>}

      <section
        className="numbers-panel"
        aria-labelledby="numbers-heading"
        aria-busy={loading}
      >
        <header className="numbers-panel-head">
          <div>
            <h2 id="numbers-heading">Elige tus números</h2>
            <p className="muted">
              Toca los números disponibles. Cada estado se indica con su etiqueta y su forma,
              no solo con color.
            </p>
          </div>

          {numbers && (
            <div className="legend" aria-label="Resumen de disponibilidad">
              <Badge tone="success">Disponibles: {numbers.meta.counts.available}</Badge>
              <Badge tone="warning">Reservados: {numbers.meta.counts.reserved}</Badge>
              <Badge tone="neutral">Vendidos: {numbers.meta.counts.sold}</Badge>
            </div>
          )}
        </header>

        {loading && !numbers && (
          <>
            <p className="ui-sr-only" role="status">
              Cargando números…
            </p>
            <div className="number-grid" aria-hidden="true">
              {Array.from({ length: 24 }, (_, index) => (
                <Skeleton key={index} className="number-skeleton" height={44} />
              ))}
            </div>
          </>
        )}

        {numbers && (
          <>
            <div className="number-grid" role="group" aria-label="Números de la rifa">
              {numbers.data.map((item) => {
                const selected = selectedNumbers.includes(item.number)
                const stateLabel = selected
                  ? 'seleccionado'
                  : NUMBER_STATE_LABEL[item.status]

                return (
                  <button
                    key={item.id}
                    type="button"
                    className={`number ${item.status.toLowerCase()}${selected ? ' selected' : ''}`}
                    disabled={item.status !== 'AVAILABLE'}
                    aria-pressed={item.status === 'AVAILABLE' ? selected : undefined}
                    aria-label={`Número ${item.number}, ${stateLabel}`}
                    onClick={() => toggleNumber(item.number)}
                  >
                    {item.number}
                    {selected && (
                      <span className="number-check" aria-hidden="true">
                        ✓
                      </span>
                    )}
                  </button>
                )
              })}
            </div>

            <div className="pagination" aria-label="Paginación de números">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1 || loading}
                onClick={() => setPage((current) => Math.max(current - 1, 1))}
              >
                Anterior
              </Button>
              <span className="muted">
                Página {numbers.meta.current_page} de {numbers.meta.last_page}
              </span>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= numbers.meta.last_page || loading}
                onClick={() => setPage((current) => current + 1)}
              >
                Siguiente
              </Button>
            </div>

            <div className="selection-bar" role="region" aria-label="Resumen de selección">
              <div className="selection-info">
                <p className="selection-summary" aria-live="polite">
                  <strong>
                    {selectedNumbers.length}{' '}
                    {selectedNumbers.length === 1 ? 'número' : 'números'}
                  </strong>{' '}
                  <span className="muted">· Total {formatCents(totalAmount)}</span>
                </p>
                <p className="muted selection-detail">
                  {selectedNumbers.length === 0
                    ? 'Selecciona uno o más números disponibles.'
                    : `Seleccionados: ${selectedNumbers.join(', ')}`}
                </p>
                <p className="muted selection-detail">
                  La reserva es temporal ({raffle?.reservation_minutes ?? 10} minutos); los
                  números se liberan si no se completa el pago. Máximo{' '}
                  {raffle?.max_numbers_per_order ?? 50} números por orden.
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
                  <Button
                    onClick={handleReserve}
                    disabled={selectedNumbers.length === 0 || submitting}
                    loading={submitting}
                  >
                    {submitting ? 'Reservando…' : 'Reservar números'}
                  </Button>
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
    </section>
  )
}
