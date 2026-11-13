import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/ToastProvider'
import AuthShell from '../components/AuthShell'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Field from '../components/ui/Field'
import { postLoginPath } from '../lib/navigation'
import { LEGAL_ROUTES, LEGAL_VERSION } from '../lib/legal'

export default function RegisterPage() {
  const { register } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  })
  const [privacyAccepted, setPrivacyAccepted] = useState(false)
  const [termsAccepted, setTermsAccepted] = useState(false)
  const [marketingAccepted, setMarketingAccepted] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)

    if (!privacyAccepted || !termsAccepted) {
      setError('Debes aceptar la Política de Tratamiento de Datos Personales y los Términos y Condiciones.')
      return
    }

    setSubmitting(true)

    try {
      await register({
        ...form,
        privacy_consent: privacyAccepted,
        terms_consent: termsAccepted,
        marketing_consent: marketingAccepted,
        consent_version: LEGAL_VERSION,
      })
      toast.success('Cuenta creada. Ya puedes reservar tus números.')
      navigate(postLoginPath(location.state), { replace: true })
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthShell
      eyebrow="Nueva cuenta"
      title="Crear cuenta"
      description="Solo necesitamos tu nombre, correo y teléfono."
      footer={
        <p className="auth-switch">
          ¿Ya tienes cuenta? <Link to="/login">Ingresa</Link>
        </p>
      }
    >
      {error && <Alert tone="danger">{error}</Alert>}

      <form onSubmit={handleSubmit} className="form auth-form">
        <Field label="Nombre completo" required>
          <input
            value={form.name}
            onChange={(event) => update('name', event.target.value)}
            required
            autoComplete="name"
          />
        </Field>

        <Field label="Correo electrónico" required>
          <input
            type="email"
            value={form.email}
            onChange={(event) => update('email', event.target.value)}
            required
            autoComplete="email"
          />
        </Field>

        <Field label="Teléfono" required>
          <input
            value={form.phone}
            onChange={(event) => update('phone', event.target.value)}
            required
            autoComplete="tel"
            maxLength={20}
          />
        </Field>

        <Field label="Contraseña" required hint="Mínimo 8 caracteres.">
          <input
            type="password"
            value={form.password}
            onChange={(event) => update('password', event.target.value)}
            required
            minLength={8}
            autoComplete="new-password"
          />
        </Field>

        <Field label="Confirmar contraseña" required hint="Debe coincidir con la contraseña.">
          <input
            type="password"
            value={form.password_confirmation}
            onChange={(event) => update('password_confirmation', event.target.value)}
            required
            minLength={8}
            autoComplete="new-password"
          />
        </Field>

        <fieldset className="consent-fieldset">
          <legend>Autorizaciones</legend>

          <div className="consent-item">
            <input
              id="privacy-consent"
              type="checkbox"
              checked={privacyAccepted}
              onChange={(event) => setPrivacyAccepted(event.target.checked)}
              required
            />
            <div>
              <label htmlFor="privacy-consent">
                He leído y acepto la Política de Tratamiento de Datos Personales
                y el aviso de privacidad. <span className="consent-required">Obligatorio.</span>
              </label>
              <p className="consent-links">
                <Link to={LEGAL_ROUTES.privacy} target="_blank" rel="noreferrer">
                  Leer la política de privacidad
                </Link>
                <span aria-hidden="true"> · </span>
                <Link to={`${LEGAL_ROUTES.privacy}#aviso`} target="_blank" rel="noreferrer">
                  Leer el aviso de privacidad
                </Link>
              </p>
            </div>
          </div>

          <div className="consent-item">
            <input
              id="terms-consent"
              type="checkbox"
              checked={termsAccepted}
              onChange={(event) => setTermsAccepted(event.target.checked)}
              required
            />
            <div>
              <label htmlFor="terms-consent">
                He leído y acepto los Términos y Condiciones del prototipo.
                {' '}<span className="consent-required">Obligatorio.</span>
              </label>
              <p className="consent-links">
                <Link to={LEGAL_ROUTES.terms} target="_blank" rel="noreferrer">
                  Leer los términos y condiciones
                </Link>
                <span aria-hidden="true"> · </span>
                <Link to={LEGAL_ROUTES.regulatory} target="_blank" rel="noreferrer">
                  Ver el alcance regulatorio
                </Link>
              </p>
            </div>
          </div>

          <div className="consent-item">
            <input
              id="marketing-consent"
              type="checkbox"
              checked={marketingAccepted}
              onChange={(event) => setMarketingAccepted(event.target.checked)}
            />
            <div>
              <label htmlFor="marketing-consent">
                Autorizo el uso de mi correo para recibir información y
                novedades sobre RifaCloud. <span className="muted">Opcional: no
                condiciona el registro.</span>
              </label>
            </div>
          </div>

          <p className="consent-version">
            Documentos versión {LEGAL_VERSION}. Tu aceptación se envía junto con
            la versión de los documentos para dejar evidencia del consentimiento.
          </p>
        </fieldset>

        <Button type="submit" block loading={submitting}>
          {submitting ? 'Creando cuenta…' : 'Registrarme'}
        </Button>
      </form>
    </AuthShell>
  )
}
