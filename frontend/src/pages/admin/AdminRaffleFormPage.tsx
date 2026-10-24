import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import type { Raffle, RafflePayload } from '../../types'

function createEmptyForm() {
  return {
    title: '',
    description: '',
    price_per_number: '1000',
    min_number: '1',
    max_number: '100',
    starts_at: '',
    ends_at: '',
  }
}

function toDateTimeLocal(value: string | null): string {
  return value ? value.slice(0, 16) : ''
}

export default function AdminRaffleFormPage() {
  const { raffleId } = useParams()
  const isEditing = raffleId !== undefined
  const navigate = useNavigate()
  const [form, setForm] = useState(createEmptyForm)
  const [raffle, setRaffle] = useState<Raffle | null>(null)
  const [loading, setLoading] = useState(isEditing)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!isEditing) {
      setRaffle(null)
      setForm(createEmptyForm())
      setError(null)
      setLoading(false)
      return
    }

    let active = true

    setLoading(true)

    api
      .get<{ data: Raffle }>(`/admin/raffles/${raffleId}`)
      .then((response) => {
        if (!active) {
          return
        }

        const loaded = response.data.data
        setRaffle(loaded)
        setForm({
          title: loaded.title,
          description: loaded.description ?? '',
          price_per_number: String(loaded.price_per_number),
          min_number: String(loaded.min_number),
          max_number: String(loaded.max_number),
          starts_at: toDateTimeLocal(loaded.starts_at),
          ends_at: toDateTimeLocal(loaded.ends_at),
        })
        setError(null)
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
  }, [isEditing, raffleId])

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    const payload: RafflePayload = {
      title: form.title,
      description: form.description === '' ? null : form.description,
      price_per_number: Number(form.price_per_number),
      min_number: Number(form.min_number),
      max_number: Number(form.max_number),
      starts_at: form.starts_at === '' ? null : form.starts_at,
      ends_at: form.ends_at === '' ? null : form.ends_at,
    }

    try {
      if (isEditing) {
        await api.put<{ data: Raffle }>(`/admin/raffles/${raffleId}`, payload)
      } else {
        await api.post<{ data: Raffle }>('/admin/raffles', payload)
      }

      navigate('/admin/raffles')
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return <p className="muted">Cargando rifa…</p>
  }

  if (isEditing && !raffle) {
    return (
      <section className="card narrow">
        <p className="alert">{error ?? 'No se pudo cargar la rifa.'}</p>
        <Link to="/admin/raffles">Volver a administración</Link>
      </section>
    )
  }

  const readOnly = isEditing && raffle?.status !== 'DRAFT'

  return (
    <section className="card">
      <p>
        <Link to="/admin/raffles">← Volver a administración</Link>
      </p>

      <h1>{isEditing ? 'Editar rifa' : 'Nueva rifa'}</h1>
      <p className="muted">
        {isEditing
          ? 'Solo las rifas en borrador se pueden editar. Si cambias el rango, los números se regeneran.'
          : 'Los números del rango se generan automáticamente al crear la rifa. El precio se guarda en centavos.'}
      </p>

      {readOnly && (
        <p className="alert">Esta rifa ya no está en borrador y no se puede editar.</p>
      )}

      {error && <p className="alert">{error}</p>}

      <form onSubmit={handleSubmit} className="form">
        <label>
          Título
          <input
            value={form.title}
            onChange={(event) => update('title', event.target.value)}
            required
            maxLength={255}
            disabled={readOnly || submitting}
          />
        </label>

        <label>
          Descripción
          <textarea
            value={form.description}
            onChange={(event) => update('description', event.target.value)}
            rows={3}
            disabled={readOnly || submitting}
          />
        </label>

        <div className="form-row">
          <label>
            Precio por número (centavos)
            <input
              type="number"
              min={1}
              value={form.price_per_number}
              onChange={(event) => update('price_per_number', event.target.value)}
              required
              disabled={readOnly || submitting}
            />
          </label>

          <label>
            Número inicial
            <input
              type="number"
              min={1}
              value={form.min_number}
              onChange={(event) => update('min_number', event.target.value)}
              required
              disabled={readOnly || submitting}
            />
          </label>

          <label>
            Número final
            <input
              type="number"
              min={1}
              value={form.max_number}
              onChange={(event) => update('max_number', event.target.value)}
              required
              disabled={readOnly || submitting}
            />
          </label>
        </div>

        <div className="form-row">
          <label>
            Inicio (opcional)
            <input
              type="datetime-local"
              value={form.starts_at}
              onChange={(event) => update('starts_at', event.target.value)}
              disabled={readOnly || submitting}
            />
          </label>

          <label>
            Fin (opcional)
            <input
              type="datetime-local"
              value={form.ends_at}
              onChange={(event) => update('ends_at', event.target.value)}
              disabled={readOnly || submitting}
            />
          </label>
        </div>

        <button type="submit" disabled={submitting || readOnly}>
          {submitting ? 'Guardando…' : isEditing ? 'Guardar cambios' : 'Crear rifa'}
        </button>
      </form>
    </section>
  )
}
