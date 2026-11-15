import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/ToastProvider'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import Field from '../components/ui/Field'
import PageHeader from '../components/ui/PageHeader'
import { LEGAL_ROUTES, LEGAL_VERSION } from '../lib/legal'
import type { RaffleProposal } from '../types'

const TITLE_MAX = 120
const DESCRIPTION_MAX = 2000

/**
 * Authenticated proposal form.
 *
 * The form never asks for name, email or phone: those values come from the
 * account and are resolved again by the backend. The only identity the client
 * sends is its session cookie.
 */
export default function ProposalFormPage() {
  const { user } = useAuth()
  const toast = useToast()
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [privacyAccepted, setPrivacyAccepted] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [created, setCreated] = useState<RaffleProposal | null>(null)

  if (!user) {
    return null
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)

    if (!privacyAccepted) {
      setError('Debes aceptar el aviso de privacidad para enviar la propuesta.')

      return
    }

    setSubmitting(true)

    try {
      // Only proposal content and explicit consent are sent. The backend
      // ignores any identity field and uses the authenticated session.
      const response = await api.post<{ data: RaffleProposal }>('/proposals', {
        title,
        description,
        privacy_accepted: true,
      })

      setCreated(response.data.data)
      setTitle('')
      setDescription('')
      setPrivacyAccepted(false)
      toast.success('Propuesta enviada. La administración la revisará.')
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  if (created) {
    return (
      <section>
        <PageHeader
          eyebrow="Comunidad"
          title="Propuesta enviada"
          description="La administración revisará tu propuesta y decidirá si se convierte en una rifa."
        />

        <Card>
          <p className="page-header-eyebrow">Referencia #{created.id}</p>
          <h2>{created.title}</h2>
          <p className="muted">{created.description}</p>
          <p>
            Estado actual: <strong>Pendiente de revisión</strong>. Aprobar una propuesta no crea
            una rifa automáticamente; la administración deberá configurarla por separado.
          </p>

          <div className="admin-form-actions">
            <Link className="button" to="/">
              Volver al catálogo
            </Link>
            <Button
              variant="secondary"
              onClick={() => {
                setCreated(null)
                setError(null)
              }}
            >
              Enviar otra propuesta
            </Button>
          </div>
        </Card>
      </section>
    )
  }

  return (
    <section>
      <PageHeader
        eyebrow="Comunidad"
        title="Proponer una rifa"
        description="Cuéntanos qué rifa te gustaría ver en RifaCloud. La administración revisará la idea y se pondrá en contacto contigo si necesita más información."
      />

      {error && <Alert tone="danger">{error}</Alert>}

      <Card>
        <form className="form" onSubmit={handleSubmit}>
          <div>
            <p className="page-header-eyebrow">Datos de contacto</p>
            <p className="muted">
              Usaremos los datos de tu cuenta. No necesitas escribirlos de nuevo y no se
              publican en el catálogo.
            </p>

            <dl className="facts">
              <div>
                <dt>Nombre</dt>
                <dd>{user.name}</dd>
              </div>
              <div>
                <dt>Correo</dt>
                <dd>{user.email}</dd>
              </div>
              <div>
                <dt>Teléfono</dt>
                <dd>{user.phone}</dd>
              </div>
            </dl>
          </div>

          <Field
            label="Título de la propuesta"
            required
            hint={`Entre 5 y ${TITLE_MAX} caracteres.`}
          >
            <input
              type="text"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
              minLength={5}
              maxLength={TITLE_MAX}
              required
              disabled={submitting}
              placeholder="Ej. Rifa de una bicicleta de montaña"
            />
          </Field>

          <Field
            label="Descripción"
            required
            hint={`Entre 10 y ${DESCRIPTION_MAX} caracteres. Describe el premio, el contexto y por qué sería una buena rifa.`}
          >
            <textarea
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              minLength={10}
              maxLength={DESCRIPTION_MAX}
              rows={7}
              required
              disabled={submitting}
              placeholder="Quiero proponer una rifa de..."
            />
          </Field>

          <fieldset className="consent-fieldset">
            <legend>Aviso de privacidad</legend>

            <div className="consent-item">
              <input
                id="proposal-privacy-consent"
                type="checkbox"
                checked={privacyAccepted}
                onChange={(event) => setPrivacyAccepted(event.target.checked)}
                required
              />
              <label htmlFor="proposal-privacy-consent">
                Acepto el{' '}
                <Link to={LEGAL_ROUTES.privacy} target="_blank" rel="noreferrer">
                  aviso de privacidad
                </Link>{' '}
                (versión {LEGAL_VERSION}) para que la administración trate mis datos de
                contacto con el fin de revisar esta propuesta.
              </label>
            </div>
          </fieldset>

          <div className="admin-form-actions">
            <Button type="submit" loading={submitting}>
              {submitting ? 'Enviando…' : 'Enviar propuesta'}
            </Button>
            <Link className="button button-ghost" to="/">
              Cancelar
            </Link>
          </div>
        </form>
      </Card>
    </section>
  )
}
