import type { ReactNode } from 'react'

export interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  /** Línea superior en versalitas (contexto o sección). */
  eyebrow?: ReactNode
  /** Acción principal alineada a la derecha. */
  actions?: ReactNode
  className?: string
  id?: string
}

/** Cabecera de página: título, descripción y acción principal opcional. */
export default function PageHeader({
  title,
  description,
  eyebrow,
  actions,
  className,
  id,
}: PageHeaderProps) {
  return (
    <header className={['page-header', className].filter(Boolean).join(' ')}>
      <div>
        {eyebrow && <p className="page-header-eyebrow">{eyebrow}</p>}
        <h1 id={id}>{title}</h1>
        {description && <p className="muted">{description}</p>}
      </div>

      {actions && <div className="page-header-actions">{actions}</div>}
    </header>
  )
}
