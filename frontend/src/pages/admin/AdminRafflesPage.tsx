import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import { formatCents } from '../../lib/format'
import type { PaginatedResponse, Raffle, RaffleStatus } from '../../types'

const statusLabels: Record<RaffleStatus, string> = {
  DRAFT: 'Borrador',
  PUBLISHED: 'Publicada',
  CLOSED: 'Cerrada',
  FINISHED: 'Finalizada',
}

export default function AdminRafflesPage() {
  const [raffles, setRaffles] = useState<Raffle[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  const refresh = useCallback(async () => {
    setLoading(true)

    try {
      const response = await api.get<PaginatedResponse<Raffle>>('/admin/raffles')
      setRaffles(response.data.data)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function transition(id: number, action: 'publish' | 'close') {
    setBusyId(id)

    try {
      await api.post(`/admin/raffles/${id}/${action}`)
      await refresh()
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <section>
      <header className="page-header">
        <div>
          <h1>Administración de rifas</h1>
          <p className="muted">Crea, publica y cierra rifas.</p>
        </div>
        <Link className="button" to="/admin/raffles/new">
          Nueva rifa
        </Link>
      </header>

      {error && <p className="alert">{error}</p>}
      {loading && <p className="muted">Cargando rifas…</p>}

      {!loading && raffles.length === 0 && <p className="muted">No hay rifas creadas.</p>}

      {raffles.length > 0 && (
        <div className="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Título</th>
                <th>Estado</th>
                <th>Precio</th>
                <th>Rango</th>
                <th>Números</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {raffles.map((raffle) => (
                <tr key={raffle.id}>
                  <td>{raffle.title}</td>
                  <td>
                    <span className={`status ${raffle.status.toLowerCase()}`}>
                      {statusLabels[raffle.status]}
                    </span>
                  </td>
                  <td>{formatCents(raffle.price_per_number)}</td>
                  <td>
                    {raffle.min_number} – {raffle.max_number}
                  </td>
                  <td>{raffle.numbers_count ?? raffle.total_numbers}</td>
                  <td className="actions">
                    {raffle.status === 'DRAFT' && (
                      <button
                        type="button"
                        disabled={busyId === raffle.id}
                        onClick={() => void transition(raffle.id, 'publish')}
                      >
                        Publicar
                      </button>
                    )}
                    {raffle.status === 'PUBLISHED' && (
                      <button
                        type="button"
                        disabled={busyId === raffle.id}
                        onClick={() => void transition(raffle.id, 'close')}
                      >
                        Cerrar
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
