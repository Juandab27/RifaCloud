import type { HTMLAttributes, ReactNode } from 'react'

export type AlertTone = 'danger' | 'warning' | 'success' | 'info'

export interface AlertProps extends Omit<HTMLAttributes<HTMLDivElement>, 'title'> {
  tone?: AlertTone
  /** Título opcional mostrado sobre el mensaje. */
  heading?: ReactNode
  children: ReactNode
}

const DEFAULT_ROLE: Record<AlertTone, 'alert' | undefined> = {
  danger: 'alert',
  warning: 'alert',
  success: undefined,
  info: undefined,
}

/** Mensaje en línea. `danger` y `warning` se anuncian como alerta. */
export default function Alert({
  tone = 'danger',
  heading,
  className,
  children,
  role,
  ...rest
}: AlertProps) {
  return (
    <div
      className={['alert', `alert-${tone}`, className].filter(Boolean).join(' ')}
      role={role ?? DEFAULT_ROLE[tone]}
      {...rest}
    >
      {heading && <strong className="alert-title">{heading}</strong>}
      {children}
    </div>
  )
}
