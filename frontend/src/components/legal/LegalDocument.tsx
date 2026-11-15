import type { ReactNode } from 'react'
import { LEGAL_UPDATED_AT, LEGAL_VERSION } from '../../lib/legal'
import ContactEmail from '../ContactEmail'
import PageHeader from '../ui/PageHeader'

export interface LegalTocItem {
  id: string
  label: string
}

export interface LegalDocumentProps {
  eyebrow: string
  title: string
  description: string
  toc: LegalTocItem[]
  children: ReactNode
}

/**
 * Shared presentation for the public legal pages.
 *
 * The academic disclaimer is deliberately brief and visible, without internal
 * development notes or draft markers.
 */
export default function LegalDocument({
  eyebrow,
  title,
  description,
  toc,
  children,
}: LegalDocumentProps) {
  return (
    <article className="legal-page">
      <PageHeader eyebrow={eyebrow} title={title} description={description} />

      <div className="legal-meta">
        <span>Versión {LEGAL_VERSION}</span>
        <span>Actualizado: {LEGAL_UPDATED_AT}</span>
      </div>

      <p className="legal-footnote">
        RifaCloud es un proyecto académico. Las rifas y pagos son demostrativos;
        no se realizan cobros reales ni operaciones comerciales.
      </p>

      <nav className="legal-toc" aria-label="Contenido del documento">
        <p className="legal-toc-title">Contenido</p>
        <ol>
          {toc.map((item) => (
            <li key={item.id}>
              <a href={`#${item.id}`}>{item.label}</a>
            </li>
          ))}
        </ol>
      </nav>

      <div className="legal-prose">{children}</div>

      <p className="legal-footnote">
        Equipo de RifaCloud · <ContactEmail showCopy />
      </p>
    </article>
  )
}
