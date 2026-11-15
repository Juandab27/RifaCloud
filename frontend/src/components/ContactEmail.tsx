import { useEffect, useRef, useState } from 'react'
import { CONTACT_EMAIL } from '../lib/legal'

export interface ContactEmailProps {
  /** Text shown for the mailto link. Defaults to the address itself. */
  label?: string
  /** Shows an accessible copy-to-clipboard action next to the link. */
  showCopy?: boolean
  className?: string
}

type CopyStatus = 'idle' | 'copied' | 'error'

/**
 * Email contact link with an optional clipboard fallback.
 *
 * `mailto:` opens only when the browser/device has a mail handler configured.
 * The copy action gives users without one a way to keep the address.
 */
export default function ContactEmail({
  label = CONTACT_EMAIL,
  showCopy = false,
  className,
}: ContactEmailProps) {
  const [status, setStatus] = useState<CopyStatus>('idle')
  const resetTimer = useRef<number | null>(null)

  useEffect(() => {
    return () => {
      if (resetTimer.current !== null) {
        window.clearTimeout(resetTimer.current)
      }
    }
  }, [])

  async function copyAddress() {
    try {
      if (navigator.clipboard?.writeText !== undefined) {
        await navigator.clipboard.writeText(CONTACT_EMAIL)
      } else {
        const field = document.createElement('textarea')
        field.value = CONTACT_EMAIL
        field.setAttribute('readonly', '')
        field.style.position = 'fixed'
        field.style.opacity = '0'
        document.body.appendChild(field)
        field.select()
        const copied = document.execCommand('copy')
        document.body.removeChild(field)

        if (!copied) {
          throw new Error('copy failed')
        }
      }

      setStatus('copied')
    } catch {
      setStatus('error')
    }

    if (resetTimer.current !== null) {
      window.clearTimeout(resetTimer.current)
    }

    resetTimer.current = window.setTimeout(() => setStatus('idle'), 2500)
  }

  const copyLabel =
    status === 'copied' ? 'Copiado' : status === 'error' ? 'Reintentar' : 'Copiar'

  return (
    <span className={['contact-email', className].filter(Boolean).join(' ')}>
      <a className="contact-email__link" href={`mailto:${CONTACT_EMAIL}`}>
        {label}
      </a>

      {showCopy && (
        <button
          type="button"
          className="contact-email__copy"
          onClick={() => void copyAddress()}
          aria-label="Copiar dirección de contacto"
        >
          {copyLabel}
        </button>
      )}

      <span className="ui-sr-only" role="status" aria-live="polite">
        {status === 'copied'
          ? 'Correo copiado al portapapeles.'
          : status === 'error'
            ? 'No se pudo copiar el correo. Puedes seleccionarlo manualmente.'
            : ''}
      </span>
    </span>
  )
}
