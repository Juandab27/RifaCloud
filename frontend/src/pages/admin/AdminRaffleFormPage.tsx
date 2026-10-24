import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import type { Raffle, RafflePayload } from '../../types'

export default function AdminRaffleFormPage() {
  const navigate = useNavigate()
  const [form, setForm] = useState({
    title: '',
    description: '',
    price_per_number: '1000',
    min_number: '1',
    max_number: '100',
    starts_at: '',
    ends_at: '',
  })
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

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
      await api.post<{ data: Raffle }>('/admin/raffles', payload)
      navigate('/admin/raffles')
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="card">
      <p>
        <Link to="/admin/raffles">← Volver a administración</Link>
      </p>

      <h1>Nueva rifa</h1>
      <p className="muted">
        Los números del rango se generan automáticamente al crear la rifa. El precio se guarda en
        centavos.
      </p>

      {error && <p className="alert">{error}</p>}

      <form onSubmit={handleSubmit} className="form">
        <label>
          Título
          <input
            value={form.title}
            onChange={(event) => update('title', event.target.value)}
            required
            maxLength={255}
          />
        </label>

        <label>
          Descripción
          <textarea
            value={form.description}
            onChange={(event) => update('description', event.target.value)}
            rows={3}
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
            />
          </label>

          <label>
            Fin (opcional)
            <input
              type="datetime-local"
              value={form.ends_at}
              onChange={(event) => update('ends_at', event.target.value)}
            />
          </label>
        </div>

        <button type="submit" disabled={submitting}>
          {submitting ? 'Creando…' : 'Crear rifa'}
        </button>
      </form>
    </section>
  )
}
