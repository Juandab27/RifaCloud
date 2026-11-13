import axios from 'axios'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, apiErrorMessage } from '../../api/client'
import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import DateTimeField from '../../components/ui/DateTimeField'
import Field from '../../components/ui/Field'
import PageHeader from '../../components/ui/PageHeader'
import Skeleton from '../../components/ui/Skeleton'
import {
  formatCop,
  toBogotaDateTimeInput,
  toUtcIsoFromBogotaInput,
} from '../../lib/format'
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

/** Errores 422 por campo; solo presentación, no altera la validación. */
function extractFieldErrors(caught: unknown): Record<string, string> {
  if (!axios.isAxiosError(caught)) {
    return {}
  }

  const data = caught.response?.data as { errors?: Record<string, string[]> } | undefined

  if (!data?.errors) {
    return {}
  }

  const result: Record<string, string> = {}

  for (const [field, messages] of Object.entries(data.errors)) {
    if (messages.length > 0) {
      result[field] = messages[0]
    }
  }

  return result
}

export default function AdminRaffleFormPage() {
  const { raffleId } = useParams()
  const isEditing = raffleId !== undefined
  const navigate = useNavigate()
  const [form, setForm] = useState(createEmptyForm)
  const [raffle, setRaffle] = useState<Raffle | null>(null)
  const [loading, setLoading] = useState(isEditing)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
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
          starts_at: toBogotaDateTimeInput(loaded.starts_at),
          ends_at: toBogotaDateTimeInput(loaded.ends_at),
        })
        setError(null)
        setFieldErrors({})
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

    setFieldErrors((current) => {
      if (!(field in current)) {
        return current
      }

      const next = { ...current }
      delete next[field]
      return next
    })
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})

    const priceCop = Number(form.price_per_number)

    if (!Number.isInteger(priceCop) || priceCop < 1) {
      setFieldErrors({ price_per_number: 'Ingresa un precio entero en pesos colombianos.' })

      return
    }

    if (form.starts_at !== '' && form.ends_at !== '' && form.ends_at <= form.starts_at) {
      setFieldErrors({ ends_at: 'La fecha de cierre debe ser posterior a la de inicio.' })

      return
    }

    setSubmitting(true)

    const payload: RafflePayload = {
      title: form.title,
      description: form.description === '' ? null : form.description,
      price_per_number: priceCop,
      min_number: Number(form.min_number),
      max_number: Number(form.max_number),
      starts_at: toUtcIsoFromBogotaInput(form.starts_at),
      ends_at: toUtcIsoFromBogotaInput(form.ends_at),
    }

    try {
      if (isEditing) {
        await api.put<{ data: Raffle }>(`/admin/raffles/${raffleId}`, payload)
      } else {
        await api.post<{ data: Raffle }>('/admin/raffles', payload)
      }

      navigate('/admin/raffles')
    } catch (caught) {
      setFieldErrors(extractFieldErrors(caught))
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <>
        <p className="ui-sr-only" role="status">
          Cargando rifa…
        </p>
        <div className="admin-skeleton" aria-hidden="true">
          <Skeleton width="40%" height={30} />
          <Skeleton height={180} radius={16} />
          <Skeleton height={110} radius={16} />
        </div>
      </>
    )
  }

  if (isEditing && !raffle) {
    return (
      <Card narrow>
        <Alert tone="danger">{error ?? 'No se pudo cargar la rifa.'}</Alert>
        <Link to="/admin/raffles">Volver a administración</Link>
      </Card>
    )
  }

  const readOnly = isEditing && raffle?.status !== 'DRAFT'
  const priceCop = Number(form.price_per_number)
  const pricePreview =
    Number.isInteger(priceCop) && priceCop > 0 ? formatCop(priceCop) : null

  return (
    <section>
      <p className="back-link">
        <Link to="/admin/raffles">← Volver a administración</Link>
      </p>

      <PageHeader
        eyebrow={isEditing ? 'Editar' : 'Nueva'}
        title={isEditing ? 'Editar rifa' : 'Nueva rifa'}
        description={
          isEditing
            ? 'Solo las rifas en borrador se pueden editar. Si cambias el rango, los números se regeneran.'
            : 'Los números del rango se generan automáticamente al crear la rifa. El precio se ingresa en pesos colombianos (COP).'
        }
      />

      {readOnly && (
        <Alert tone="warning">Esta rifa ya no está en borrador y no se puede editar.</Alert>
      )}

      {error && <Alert tone="danger">{error}</Alert>}

      <Card>
        <form onSubmit={handleSubmit} className="form admin-form">
          <fieldset className="admin-form-section">
            <legend>Datos de la rifa</legend>

            <Field label="Título" required error={fieldErrors.title}>
              <input
                value={form.title}
                onChange={(event) => update('title', event.target.value)}
                required
                maxLength={255}
                disabled={readOnly || submitting}
              />
            </Field>

            <Field label="Descripción" error={fieldErrors.description}>
              <textarea
                value={form.description}
                onChange={(event) => update('description', event.target.value)}
                rows={3}
                disabled={readOnly || submitting}
              />
            </Field>
          </fieldset>

          <fieldset className="admin-form-section">
            <legend>Precio y números</legend>

            <div className="form-row">
              <Field
                label="Precio por número (COP)"
                required
                error={fieldErrors.price_per_number}
                hint={
                  pricePreview
                    ? `Se mostrará como ${pricePreview}.`
                    : 'Ingresa el valor en pesos colombianos.'
                }
              >
                <input
                  type="number"
                  min={1}
                  step={1}
                  inputMode="numeric"
                  value={form.price_per_number}
                  onChange={(event) => update('price_per_number', event.target.value)}
                  required
                  disabled={readOnly || submitting}
                />
              </Field>

              <Field label="Número inicial" required error={fieldErrors.min_number}>
                <input
                  type="number"
                  min={1}
                  value={form.min_number}
                  onChange={(event) => update('min_number', event.target.value)}
                  required
                  disabled={readOnly || submitting}
                />
              </Field>

              <Field label="Número final" required error={fieldErrors.max_number}>
                <input
                  type="number"
                  min={1}
                  value={form.max_number}
                  onChange={(event) => update('max_number', event.target.value)}
                  required
                  disabled={readOnly || submitting}
                />
              </Field>
            </div>
          </fieldset>

          <fieldset className="admin-form-section">
            <legend>Calendario</legend>

            <div className="form-row">
              <Field
                label="Inicio (opcional)"
                error={fieldErrors.starts_at}
                hint="Hora de Colombia (America/Bogota)."
              >
                <DateTimeField
                  value={form.starts_at}
                  onChange={(value) => update('starts_at', value)}
                  disabled={readOnly || submitting}
                />
              </Field>

              <Field
                label="Fin (opcional)"
                error={fieldErrors.ends_at}
                hint="Hora de Colombia (America/Bogota)."
              >
                <DateTimeField
                  value={form.ends_at}
                  onChange={(value) => update('ends_at', value)}
                  disabled={readOnly || submitting}
                />
              </Field>
            </div>

            <p className="ui-field-hint">
              Si dejas ambas fechas vacías, la rifa no restringe la venta por calendario. La
              fecha de cierre debe ser posterior a la de inicio.
            </p>
          </fieldset>

          <div className="admin-form-actions">
            <Button type="submit" loading={submitting} disabled={readOnly}>
              {submitting ? 'Guardando…' : isEditing ? 'Guardar cambios' : 'Crear rifa'}
            </Button>
          </div>
        </form>
      </Card>
    </section>
  )
}
