import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { apiErrorMessage } from '../api/client'
import { useAuth } from '../auth/AuthContext'

export default function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
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
      navigate('/')
    } catch (caught) {
      setError(apiErrorMessage(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="card narrow">
      <h1>Crear cuenta</h1>
      <p className="muted">Solo necesitamos tu nombre, correo y teléfono.</p>

      {error && <p className="alert">{error}</p>}

      <form onSubmit={handleSubmit} className="form">
        <label>
          Nombre completo
          <input
            value={form.name}
            onChange={(event) => update('name', event.target.value)}
            required
            autoComplete="name"
          />
        </label>

        <label>
          Correo electrónico
          <input
            type="email"
            value={form.email}
            onChange={(event) => update('email', event.target.value)}
            required
            autoComplete="email"
          />
        </label>

        <label>
          Teléfono
          <input
            value={form.phone}
            onChange={(event) => update('phone', event.target.value)}
            required
            autoComplete="tel"
            maxLength={20}
          />
        </label>

        <label>
          Contraseña
          <input
            type="password"
            value={form.password}
            onChange={(event) => update('password', event.target.value)}
            required
            minLength={8}
            autoComplete="new-password"
          />
        </label>

        <label>
          Confirmar contraseña
          <input
            type="password"
            value={form.password_confirmation}
            onChange={(event) => update('password_confirmation', event.target.value)}
            required
            minLength={8}
            autoComplete="new-password"
          />
        </label>

        <button type="submit" disabled={submitting}>
          {submitting ? 'Creando cuenta…' : 'Registrarme'}
        </button>
      </form>

      <p className="muted">
        ¿Ya tienes cuenta? <Link to="/login">Ingresa</Link>
      </p>
    </section>
  )
}
