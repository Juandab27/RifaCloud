import { useState, type FormEvent } from 'react'
import axios from 'axios'
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
import {
  PASSWORD_MIN_LENGTH,
  isStrongPassword,
  isValidEmail,
  normalizeColombianMobile,
  passwordChecks,
} from '../lib/registration'

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
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const checks = passwordChecks(form.password)
  const passwordsMatch =
    form.password_confirmation !== '' && form.password === form.password_confirmation

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => {
      if (!(field in current) && !(field === 'password' && 'password_confirmation' in current)) {
        return current
      }

      const next = { ...current }
      delete next[field]

      if (field === 'password') {
        delete next.password_confirmation
      }

      return next
    })
  }

  function normalizeVisiblePhone() {
    const normalized = normalizeColombianMobile(form.phone)

    if (normalized !== null) {
      update('phone', normalized)
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)

    const nextErrors: Record<string, string> = {}
    const name = form.name.trim()
    const email = form.email.trim()
    const phone = normalizeColombianMobile(form.phone)

    if (name === '') {
      nextErrors.name = 'El nombre es obligatorio.'
    }

    if (!isValidEmail(email)) {
      nextErrors.email = 'Ingresa un correo electrónico válido (por ejemplo, nombre@dominio.com).'
    }

    if (phone === null) {
      nextErrors.phone = 'Ingresa un número de celular válido.'
    }

    if (!isStrongPassword(form.password)) {
      nextErrors.password =
        'La contraseña debe tener al menos 8 caracteres, una letra mayúscula y un número.'
    }

    if (form.password !== form.password_confirmation) {
      nextErrors.password_confirmation = 'Las contraseñas no coinciden.'
    }

    if (!privacyAccepted || !termsAccepted) {
      setError(
        'Debes aceptar la Política de Tratamiento de Datos Personales y los Términos y Condiciones.',
      )
    }

    setFieldErrors(nextErrors)

    if (Object.keys(nextErrors).length > 0 || !privacyAccepted || !termsAccepted) {
      return
    }

    setSubmitting(true)

    try {
      await register({
        ...form,
        name,
        email,
        phone: phone as string,
        privacy_consent: privacyAccepted,
        terms_consent: termsAccepted,
        // No promotional checkbox is shown. Keep the API field explicit for
        // compatibility with the current backend contract.
        marketing_consent: false,
        consent_version: LEGAL_VERSION,
      })
      toast.success('Cuenta creada. Ya puedes reservar tus números.')
      navigate(postLoginPath(location.state), { replace: true })
    } catch (caught) {
      // Surface the backend's per-field messages in Spanish next to each input.
      if (axios.isAxiosError(caught)) {
        const data = caught.response?.data as
          | { errors?: Record<string, string[]> }
          | undefined

        if (data?.errors) {
          const fromServer: Record<string, string> = {}

          for (const [field, messages] of Object.entries(data.errors)) {
            if (messages[0]) {
              fromServer[field] = messages[0]
            }
          }

          setFieldErrors((current) => ({ ...current, ...fromServer }))
        }
      }

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

      <form onSubmit={handleSubmit} className="form auth-form" noValidate>
        <Field label="Nombre completo" required error={fieldErrors.name}>
          <input
            value={form.name}
            onChange={(event) => update('name', event.target.value)}
            required
            autoComplete="name"
          />
        </Field>

        <Field label="Correo electrónico" required error={fieldErrors.email}>
          <input
            type="email"
            value={form.email}
            onChange={(event) => update('email', event.target.value)}
            required
            autoComplete="email"
            inputMode="email"
          />
        </Field>

        <div className="ui-field">
          <label className="ui-field-label" htmlFor="register-phone">
            Teléfono celular
            <span className="ui-field-required" aria-hidden="true">
              *
            </span>
          </label>

          <div className="phone-input">
            <span className="phone-prefix" aria-hidden="true">
              +57
            </span>
            <input
              id="register-phone"
              type="tel"
              inputMode="tel"
              value={form.phone}
              onChange={(event) => update('phone', event.target.value)}
              onBlur={normalizeVisiblePhone}
              required
              autoComplete="tel-national"
              maxLength={30}
              placeholder="300 123 4567"
              aria-invalid={fieldErrors.phone ? true : undefined}
            />
          </div>

          {fieldErrors.phone && (
            <p className="ui-field-error" role="alert">
              {fieldErrors.phone}
            </p>
          )}
        </div>

        <Field
          label="Contraseña"
          required
          error={fieldErrors.password}
          hint={`Mínimo ${PASSWORD_MIN_LENGTH} caracteres, una letra mayúscula y un número. Puedes usar caracteres especiales.`}
        >
          <input
            type="password"
            value={form.password}
            onChange={(event) => update('password', event.target.value)}
            required
            minLength={PASSWORD_MIN_LENGTH}
            autoComplete="new-password"
          />
        </Field>

        <ul className="password-checklist" aria-live="polite">
          <li className={checks.minLength ? 'is-met' : ''}>
            <span aria-hidden="true">{checks.minLength ? '✓' : '•'}</span>
            Mínimo {PASSWORD_MIN_LENGTH} caracteres
          </li>
          <li className={checks.hasUppercase ? 'is-met' : ''}>
            <span aria-hidden="true">{checks.hasUppercase ? '✓' : '•'}</span>
            Al menos una letra mayúscula
          </li>
          <li className={checks.hasNumber ? 'is-met' : ''}>
            <span aria-hidden="true">{checks.hasNumber ? '✓' : '•'}</span>
            Al menos un número
          </li>
          <li className={passwordsMatch ? 'is-met' : ''}>
            <span aria-hidden="true">{passwordsMatch ? '✓' : '•'}</span>
            Las contraseñas coinciden
          </li>
        </ul>

        <Field
          label="Confirmar contraseña"
          required
          error={fieldErrors.password_confirmation}
          hint="Debe coincidir con la contraseña."
        >
          <input
            type="password"
            value={form.password_confirmation}
            onChange={(event) => update('password_confirmation', event.target.value)}
            required
            minLength={PASSWORD_MIN_LENGTH}
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
                He leído y acepto la Política de Tratamiento de Datos Personales.
                {' '}<span className="consent-required">Obligatorio.</span>
              </label>
              <p className="consent-links">
                <Link to={LEGAL_ROUTES.privacy} target="_blank" rel="noreferrer">
                  Leer la política de privacidad
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
              </p>
            </div>
          </div>

        </fieldset>

        <Button type="submit" block loading={submitting}>
          {submitting ? 'Creando cuenta…' : 'Registrarme'}
        </Button>
      </form>
    </AuthShell>
  )
}
