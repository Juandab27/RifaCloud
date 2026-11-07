export interface SpinnerProps {
  size?: 'sm' | 'md' | 'lg'
  /** Cuando se indica, el indicador se anuncia como estado. */
  label?: string
  className?: string
}

const SIZE_CLASS = {
  sm: 'ui-spinner-sm',
  md: '',
  lg: 'ui-spinner-lg',
} as const

/** Indicador circular reutilizable para cargas en línea y botones. */
export default function Spinner({ size = 'md', label, className }: SpinnerProps) {
  const classes = ['ui-spinner', SIZE_CLASS[size], className].filter(Boolean).join(' ')

  return (
    <span
      className={classes}
      role={label ? 'status' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
    />
  )
}
