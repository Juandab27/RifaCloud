import type { ReactNode } from 'react'
import Alert from './Alert'
import Button from './Button'
import Card from './Card'
import Icon from './Icon'

export interface ErrorStateProps {
  /** Título corto; por defecto explica que no se pudo cargar. */
  title?: ReactNode
  /** Mensaje ya traducido por `apiErrorMessage`. */
  message: ReactNode
  onRetry?: () => void
  retrying?: boolean
  retryLabel?: string
  /** Acción secundaria, normalmente un enlace de vuelta. */
  secondaryAction?: ReactNode
  className?: string
}

/**
 * Estado de error reutilizable con reintento. No conoce la causa: muestra el
 * mensaje recibido y deja el control de reintento a la página.
 */
export default function ErrorState({
  title,
  message,
  onRetry,
  retrying = false,
  retryLabel,
  secondaryAction,
  className,
}: ErrorStateProps) {
  return (
    <Card narrow className={['error-state', className].filter(Boolean).join(' ')}>
      <span className="error-state-icon" aria-hidden="true">
        <Icon name="alert" size={24} />
      </span>

      <Alert tone="danger" heading={title ?? 'No pudimos cargar la información'}>
        {message}
      </Alert>

      {(onRetry || secondaryAction) && (
        <div className="payment-actions">
          {onRetry && (
            <Button onClick={onRetry} loading={retrying}>
              {retrying ? 'Reintentando…' : (retryLabel ?? 'Reintentar')}
            </Button>
          )}
          {secondaryAction}
        </div>
      )}
    </Card>
  )
}
