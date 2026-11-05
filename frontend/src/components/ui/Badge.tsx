import type { HTMLAttributes, ReactNode } from 'react'

export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'review' | 'accent'

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: BadgeTone
  children: ReactNode
}

/** Etiqueta compacta para leyendas, conteos y marcas como «SIMULADO». */
export default function Badge({
  tone = 'neutral',
  className,
  children,
  ...rest
}: BadgeProps) {
  return (
    <span className={['badge', `badge--${tone}`, className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </span>
  )
}
