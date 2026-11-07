import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import Card from './ui/Card'

export interface AuthShellProps {
  title: ReactNode
  description?: ReactNode
  eyebrow?: ReactNode
  children: ReactNode
  /** Enlaces secundarios bajo el formulario (registro / ingreso). */
  footer?: ReactNode
}

/**
 * Estructura visual compartida por login y registro. No contiene formularios
 * ni conoce la autenticación: B3 insertará el contenido en `children`.
 */
export default function AuthShell({
  title,
  description,
  eyebrow,
  children,
  footer,
}: AuthShellProps) {
  return (
    <div className="auth-shell">
      <aside className="auth-shell-aside">
        <Link to="/" className="auth-shell-brand">
          <span className="brand-mark" aria-hidden="true">
            R
          </span>
          <span>RifaCloud</span>
        </Link>
        <p className="auth-shell-tagline">
          Elige tus números y sigue el estado de tu compra en un solo lugar.
        </p>
        <ul className="auth-shell-points">
          <li>Catálogo público de rifas publicadas</li>
          <li>Reserva temporal de los números elegidos</li>
          <li>Confirmación del pago validada en el servidor</li>
        </ul>
      </aside>

      <Card as="section" className="auth-shell-panel">
        {eyebrow && <p className="page-header-eyebrow">{eyebrow}</p>}
        <h1>{title}</h1>
        {description && <p className="muted">{description}</p>}
        {children}
        {footer && <div className="auth-shell-footer">{footer}</div>}
      </Card>
    </div>
  )
}
