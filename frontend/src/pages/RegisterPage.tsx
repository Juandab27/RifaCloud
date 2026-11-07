import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import AuthShell from '../components/AuthShell'
import Alert from '../components/ui/Alert'
import Button from '../components/ui/Button'
import Field from '../components/ui/Field'
import { postLoginPath } from '../lib/navigation'

export default function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
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

    try {
      await register(form)
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

        <Button type="submit" block loading={submitting}>
          {submitting ? 'Creando cuenta…' : 'Registrarme'}
        </Button>
      </form>
    </AuthShell>
  )
}
