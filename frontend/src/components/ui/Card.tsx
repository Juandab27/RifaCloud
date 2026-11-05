import type { HTMLAttributes, ReactNode } from 'react'

export interface CardProps extends HTMLAttributes<HTMLElement> {
  /** Elemento semántico del contenedor. */
  as?: 'div' | 'section' | 'article'
  /** Variante estrecha usada por las pantallas de autenticación. */
  narrow?: boolean
  children: ReactNode
}

/** Superficie elevada del sistema. Solo aplica clases, no altera contenido. */
export default function Card({
  as: Tag = 'div',
  narrow = false,
  className,
  children,
  ...rest
}: CardProps) {
  const classes = ['card', narrow ? 'narrow' : '', className].filter(Boolean).join(' ')

  return (
    <Tag className={classes} {...rest}>
      {children}
    </Tag>
  )
}
