import type { ReactNode } from 'react'
import { LEGAL_UPDATED_AT, LEGAL_VERSION } from '../../lib/legal'
import Alert from '../ui/Alert'
import Badge from '../ui/Badge'
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
 * S11: shared presentation for the public privacy, terms and regulatory
 * pages. The draft warning is intentionally visible on every document: these
 * texts are academic drafts, not legal advice and not an operating permit.
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
        <Badge tone="warning">Borrador</Badge>
        <span>Versión {LEGAL_VERSION}</span>
        <span>Actualizado: {LEGAL_UPDATED_AT}</span>
      </div>

      <Alert tone="warning" heading="Documento preliminar, no asesoría jurídica">
        RifaCloud es un prototipo académico. No está autorizado por Coljuegos ni
        opera rifas reales. Los textos entre corchetes son marcadores que deben
        completarse y validarse con revisión jurídica humana antes de cualquier
        publicación o uso comercial.
      </Alert>

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
        Versión {LEGAL_VERSION}. Documento preliminar del prototipo RifaCloud.
        No constituye asesoría jurídica ni autorización de operación.
      </p>
    </article>
  )
}
