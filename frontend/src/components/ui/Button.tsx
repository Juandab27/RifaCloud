import type { ButtonHTMLAttributes, ReactNode } from 'react'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md'

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Jerarquía visual. `primary` es el CTA champagne por defecto. */
  variant?: ButtonVariant
  size?: ButtonSize
  /** Deshabilita el botón y anuncia el estado ocupado. */
  loading?: boolean
  block?: boolean
  leadingIcon?: ReactNode
}

const VARIANT_CLASS: Record<ButtonVariant, string> = {
  primary: '',
  secondary: 'btn-secondary',
  ghost: 'btn-ghost',
  danger: 'btn-danger',
}

/**
 * Botón presentacional. No navega ni ejecuta lógica: solo reenvía handlers.
 * Usa `type="submit"` explícito dentro de formularios.
 */
export default function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  block = false,
  leadingIcon,
  className,
  children,
  type = 'button',
  disabled,
  ...rest
}: ButtonProps) {
  const classes = [
    'btn',
    VARIANT_CLASS[variant],
    size === 'sm' ? 'btn-sm' : '',
    block ? 'btn-block' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <button
      type={type}
      className={classes}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <span className="ui-spinner" aria-hidden="true" /> : leadingIcon}
      {children}
    </button>
  )
}
