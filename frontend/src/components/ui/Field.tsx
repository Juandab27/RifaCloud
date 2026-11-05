import {
  cloneElement,
  useId,
  type ReactElement,
  type ReactNode,
} from 'react'

export interface FieldControlProps {
  id?: string
  required?: boolean
  'aria-describedby'?: string
  'aria-invalid'?: boolean
}

export interface FieldProps {
  /** Texto visible de la etiqueta. */
  label: ReactNode
  /** El control real (`input`, `textarea`, `select`). Sus handlers no se tocan. */
  children: ReactElement<FieldControlProps>
  id?: string
  hint?: ReactNode
  error?: ReactNode
  required?: boolean
  className?: string
  /** Oculta la etiqueta visualmente pero la mantiene para lectores de pantalla. */
  hideLabel?: boolean
}

/**
 * Envoltorio de campo: asocia etiqueta, ayuda y error al control mediante
 * `id`/`htmlFor` y `aria-describedby`. No valida ni transforma valores.
 */
export default function Field({
  label,
  children,
  id,
  hint,
  error,
  required,
  className,
  hideLabel = false,
}: FieldProps) {
  const generatedId = useId()
  const controlId = id ?? children.props.id ?? generatedId
  const hintId = hint ? `${controlId}-hint` : undefined
  const errorId = error ? `${controlId}-error` : undefined
  const describedBy =
    [hintId, errorId].filter(Boolean).join(' ') || children.props['aria-describedby']
  const isRequired = required ?? children.props.required

  const control = cloneElement(children, {
    id: controlId,
    required: isRequired,
    'aria-describedby': describedBy,
    'aria-invalid': error ? true : children.props['aria-invalid'],
  })

  return (
    <div className={['ui-field', className].filter(Boolean).join(' ')}>
      <label
        className={hideLabel ? 'ui-field-label ui-sr-only' : 'ui-field-label'}
        htmlFor={controlId}
      >
        {label}
        {isRequired && (
          <span className="ui-field-required" aria-hidden="true">
            *
          </span>
        )}
      </label>

      {control}

      {hint && !error && (
        <p className="ui-field-hint" id={hintId}>
          {hint}
        </p>
      )}

      {error && (
        <p className="ui-field-error" id={errorId} role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
