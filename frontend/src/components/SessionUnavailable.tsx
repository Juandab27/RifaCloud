import { useState } from 'react'
import { Link } from 'react-router-dom'
import Alert from './ui/Alert'
import Button from './ui/Button'
import Card from './ui/Card'

interface SessionUnavailableProps {
  onRetry: () => Promise<void> | void
}

/**
 * Recoverable state for a failed session check.
 *
 * A 503, a timeout or a network error is not an expired session: redirecting
 * to /login here would throw away the user's place (for example, the order
 * page they just returned to from Mercado Pago).
 */
export default function SessionUnavailable({ onRetry }: SessionUnavailableProps) {
  const [retrying, setRetrying] = useState(false)

  async function handleRetry() {
    setRetrying(true)

    try {
      await onRetry()
    } finally {
      setRetrying(false)
    }
  }

  return (
    <Card narrow>
      <Alert tone="warning">
        No pudimos comprobar tu sesión en este momento. Tu sesión podría seguir activa; vuelve a
        intentarlo.
      </Alert>
      <div className="payment-actions">
        <Button onClick={handleRetry} loading={retrying}>
          {retrying ? 'Comprobando…' : 'Reintentar'}
        </Button>
        <Link className="button button-ghost" to="/">
          Volver al catálogo
        </Link>
      </div>
    </Card>
  )
}
