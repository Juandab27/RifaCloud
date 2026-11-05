import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import EmptyState from '../../components/ui/EmptyState'
import PageHeader from '../../components/ui/PageHeader'
import Skeleton from '../../components/ui/Skeleton'
import StatusPill from '../../components/ui/StatusPill'
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

  async function remove(id: number) {
    if (
      !window.confirm(
        '¿Eliminar esta rifa? Solo se pueden eliminar borradores sin números reservados ni vendidos.',
      )
    ) {
      return
    }

    setBusyId(id)

    try {
      await api.delete(`/admin/raffles/${id}`)
      await refresh()
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <section>
      <PageHeader
        eyebrow="Administración"
        title="Administración de rifas"
        description="Crea, edita, publica y cierra rifas. Solo los borradores se pueden editar o eliminar."
        actions={
          <Link className="button" to="/admin/raffles/new">
            Nueva rifa
          </Link>
        }
      />

      {error && <Alert tone="danger">{error}</Alert>}

      {loading && raffles.length === 0 && (
        <>
          <p className="ui-sr-only" role="status">
            Cargando rifas…
          </p>
          <div className="admin-skeleton" aria-hidden="true">
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={72} radius={16} />
            ))}
          </div>
        </>
      )}

      {!loading && raffles.length === 0 && (
        <EmptyState
          title="No hay rifas creadas."
          description="Crea la primera rifa para publicar números y empezar a recibir reservas."
          action={
            <Link className="button" to="/admin/raffles/new">
              Nueva rifa
            </Link>
          }
        />
      )}

      {raffles.length > 0 && (
        <div className="table-wrapper admin-table">
          <table>
            <caption className="ui-sr-only">Rifas creadas</caption>
            <thead>
              <tr>
                <th scope="col">Rifa</th>
                <th scope="col">Estado</th>
                <th scope="col">Precio</th>
                <th scope="col">Rango</th>
                <th scope="col">Números</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {raffles.map((raffle) => (
                <tr key={raffle.id}>
                  <td className="admin-cell-title" data-label="Rifa">
                    {raffle.title}
                  </td>
                  <td data-label="Estado">
                    <StatusPill
                      status={raffle.status}
                      label={statusLabels[raffle.status]}
                    />
                  </td>
                  <td data-label="Precio">{formatCents(raffle.price_per_number)}</td>
                  <td data-label="Rango">
                    {raffle.min_number} – {raffle.max_number}
                  </td>
                  <td data-label="Números">{raffle.numbers_count ?? raffle.total_numbers}</td>
                  <td className="actions" data-label="Acciones">
                    {raffle.status === 'DRAFT' && (
                      <>
                        <Link className="button btn-secondary" to={`/admin/raffles/${raffle.id}/edit`}>
                          Editar
                        </Link>
                        <Button
                          size="sm"
                          disabled={busyId === raffle.id}
                          onClick={() => void transition(raffle.id, 'publish')}
                        >
                          Publicar
                        </Button>
                        <Button
                          variant="danger"
                          size="sm"
                          disabled={busyId === raffle.id}
                          onClick={() => void remove(raffle.id)}
                        >
                          Eliminar
                        </Button>
                      </>
                    )}
                    {raffle.status === 'PUBLISHED' && (
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={busyId === raffle.id}
                        onClick={() => void transition(raffle.id, 'close')}
                      >
                        Cerrar
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {loading && raffles.length > 0 && (
        <p className="ui-sr-only" role="status">
          Actualizando rifas…
        </p>
      )}
    </section>
  )
}
