import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import { useToast } from '../../components/ToastProvider'
import Alert from '../../components/ui/Alert'
import Badge, { type BadgeTone } from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import EmptyState from '../../components/ui/EmptyState'
import PageHeader from '../../components/ui/PageHeader'
import Skeleton from '../../components/ui/Skeleton'
import { formatDate } from '../../lib/format'
import type {
  AdminRaffleProposal,
  PaginatedResponse,
  ProposalMetrics,
  ProposalStatus,
} from '../../types'

const STATUS_LABELS: Record<ProposalStatus, string> = {
  PENDING: 'Pendiente',
  APPROVED: 'Aprobada',
  REJECTED: 'Rechazada',
}

const STATUS_TONES: Record<ProposalStatus, BadgeTone> = {
  PENDING: 'warning',
  APPROVED: 'success',
  REJECTED: 'danger',
}

export default function AdminProposalsPage() {
  const toast = useToast()
  const [proposals, setProposals] = useState<AdminRaffleProposal[]>([])
  const [meta, setMeta] = useState<PaginatedResponse<AdminRaffleProposal>['meta'] | null>(null)
  const [metrics, setMetrics] = useState<ProposalMetrics | null>(null)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [selected, setSelected] = useState<AdminRaffleProposal | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const loadList = useCallback(async () => {
    setLoading(true)

    try {
      const response = await api.get<PaginatedResponse<AdminRaffleProposal>>(
        '/admin/proposals',
        { params: { page, status: status || undefined } },
      )
      setProposals(response.data.data)
      setMeta(response.data.meta)
      setError(null)
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [page, status])

  const loadMetrics = useCallback(async () => {
    try {
      const response = await api.get<{ data: ProposalMetrics }>('/admin/proposals/metrics')
      setMetrics(response.data.data)
    } catch {
      // The counter is secondary; the list surfaces the real availability
      // problem when the service is down.
      setMetrics(null)
    }
  }, [])

  useEffect(() => {
    void loadList()
  }, [loadList])

  useEffect(() => {
    void loadMetrics()
  }, [loadMetrics])

  async function openDetail(id: number) {
    setDetailLoading(true)
    setError(null)

    try {
      const response = await api.get<{ data: AdminRaffleProposal }>(`/admin/proposals/${id}`)
      setSelected(response.data.data)
      setNote('')
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setDetailLoading(false)
    }
  }

  async function decide(action: 'approve' | 'reject') {
    if (!selected) {
      return
    }

    const label = action === 'approve' ? 'aprobar' : 'rechazar'

    if (
      !window.confirm(
        `¿Confirmas ${label} la propuesta «${selected.title}»? Esta decisión no crea una rifa automáticamente.`,
      )
    ) {
      return
    }

    setBusy(true)
    setError(null)

    try {
      const response = await api.post<{ data: AdminRaffleProposal }>(
        `/admin/proposals/${selected.id}/${action}`,
        { note: note.trim() === '' ? null : note },
      )
      setSelected(response.data.data)
      setNote('')
      toast.success(
        action === 'approve' ? 'Propuesta aprobada.' : 'Propuesta rechazada.',
      )
      await Promise.all([loadList(), loadMetrics()])
    } catch (caught) {
      const message = apiErrorMessage(caught)
      setError(message)
      toast.error(message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <PageHeader
        eyebrow="Administración"
        title="Propuestas de rifas"
        description="Revisa las ideas enviadas por usuarios registrados. Aprobar una propuesta no crea una rifa: solo registra la decisión."
        actions={
          <div className="page-header-actions">
            <Badge tone={(metrics?.pending ?? 0) > 0 ? 'warning' : 'neutral'}>
              {metrics === null ? 'Pendientes: —' : `${metrics.pending} pendientes`}
            </Badge>
            <Link className="button button-ghost" to="/admin/dashboard">
              Dashboard
            </Link>
            <Link className="button button-ghost" to="/admin/raffles">
              Administrar rifas
            </Link>
          </div>
        }
      />

      {error && <Alert tone="danger">{error}</Alert>}

      {selected && (
        <Card as="article" className="proposal-detail" aria-busy={detailLoading || busy}>
          <div className="page-header">
            <div>
              <p className="page-header-eyebrow">Propuesta #{selected.id}</p>
              <h2>{selected.title}</h2>
              <p className="muted">
                Enviada el {formatDate(selected.created_at)} · Aviso de privacidad versión{' '}
                {selected.privacy_version}
              </p>
            </div>
            <Badge tone={STATUS_TONES[selected.status]}>{STATUS_LABELS[selected.status]}</Badge>
          </div>

          <p className="proposal-description">{selected.description}</p>

          <dl className="facts">
            <div>
              <dt>Nombre de contacto</dt>
              <dd>{selected.contact.name}</dd>
            </div>
            <div>
              <dt>Correo</dt>
              <dd>{selected.contact.email}</dd>
            </div>
            <div>
              <dt>Teléfono</dt>
              <dd>{selected.contact.phone}</dd>
            </div>
          </dl>

          {selected.decision ? (
            <div className="proposal-decision">
              <p>
                <strong>{selected.status === 'APPROVED' ? 'Aprobada' : 'Rechazada'}</strong> el{' '}
                {formatDate(selected.decision.decided_at)}
                {selected.decision.by?.name
                  ? ` por ${selected.decision.by.name}`
                  : ''}
                .
              </p>
              <p className="muted">
                Nota interna: {selected.decision.note ?? 'Sin nota.'}
              </p>
            </div>
          ) : (
            <div className="form">
              <label>
                Nota interna (opcional)
                <textarea
                  value={note}
                  onChange={(event) => setNote(event.target.value)}
                  rows={3}
                  maxLength={1000}
                  disabled={busy}
                  placeholder="Motivo de la decisión, condiciones o seguimiento pendiente."
                />
              </label>

              <div className="admin-form-actions">
                <Button loading={busy} onClick={() => void decide('approve')}>
                  Aprobar propuesta
                </Button>
                <Button
                  variant="danger"
                  disabled={busy}
                  onClick={() => void decide('reject')}
                >
                  Rechazar propuesta
                </Button>
                <Button
                  variant="secondary"
                  disabled={busy}
                  onClick={() => setSelected(null)}
                >
                  Cerrar detalle
                </Button>
              </div>
            </div>
          )}

          {selected.decision && (
            <div className="admin-form-actions">
              <Button variant="secondary" onClick={() => setSelected(null)}>
                Cerrar detalle
              </Button>
            </div>
          )}
        </Card>
      )}

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
            <option value="">Todas</option>
            <option value="PENDING">Pendientes</option>
            <option value="APPROVED">Aprobadas</option>
            <option value="REJECTED">Rechazadas</option>
          </select>
        </label>
      </div>

      {loading && proposals.length === 0 && (
        <>
          <p className="ui-sr-only" role="status">
            Cargando propuestas…
          </p>
          <div className="admin-skeleton" aria-hidden="true">
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={72} radius={16} />
            ))}
          </div>
        </>
      )}

      {!loading && proposals.length === 0 && (
        <EmptyState
          title="No hay propuestas para este filtro."
          description="Cuando un usuario registrado envíe una propuesta, aparecerá aquí con sus datos de contacto."
        />
      )}

      {proposals.length > 0 && (
        <div className="table-wrapper admin-table">
          <table>
            <caption className="ui-sr-only">Propuestas de rifas enviadas por usuarios</caption>
            <thead>
              <tr>
                <th scope="col">Propuesta</th>
                <th scope="col">Proponente</th>
                <th scope="col">Estado</th>
                <th scope="col">Enviada</th>
                <th scope="col">Decisión</th>
                <th scope="col">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {proposals.map((proposal) => (
                <tr key={proposal.id}>
                  <td className="admin-cell-title" data-label="Propuesta">
                    {proposal.title}
                  </td>
                  <td data-label="Proponente">
                    <span className="admin-cell-stack">
                      <strong>{proposal.contact.name}</strong>
                      <span className="muted">{proposal.contact.email}</span>
                    </span>
                  </td>
                  <td data-label="Estado">
                    <Badge tone={STATUS_TONES[proposal.status]}>
                      {STATUS_LABELS[proposal.status]}
                    </Badge>
                  </td>
                  <td data-label="Enviada">{formatDate(proposal.created_at)}</td>
                  <td data-label="Decisión">
                    {proposal.decision?.decided_at
                      ? formatDate(proposal.decision.decided_at)
                      : '—'}
                  </td>
                  <td className="actions" data-label="Acciones">
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={detailLoading}
                      onClick={() => void openDetail(proposal.id)}
                    >
                      Ver detalle
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
            Página {meta.current_page} de {meta.last_page} · {meta.total} propuestas
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

      {loading && proposals.length > 0 && (
        <p className="ui-sr-only" role="status">
          Actualizando propuestas…
        </p>
      )}
    </section>
  )
}
