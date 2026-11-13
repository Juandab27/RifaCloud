import { useEffect, useId, useMemo, useRef, useState } from 'react'
import {
  formatDate,
  toBogotaDateTimeInput,
  toUtcIsoFromBogotaInput,
} from '../../lib/format'

const MONTHS = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
]

const WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D']

interface DateParts {
  year: number
  month: number
  day: number
  hour: number
  minute: number
}

interface DayCell {
  year: number
  month: number
  day: number
  inMonth: boolean
}

export interface DateTimeFieldProps {
  /** Valor local de Colombia: `YYYY-MM-DDTHH:mm`. */
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  id?: string
  required?: boolean
  'aria-describedby'?: string
  'aria-invalid'?: boolean
}

const pad = (value: number): string => String(value).padStart(2, '0')

function parseInputValue(value: string): DateParts | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)

  if (!match) {
    return null
  }

  return {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4]),
    minute: Number(match[5]),
  }
}

function toInputValue(parts: DateParts): string {
  return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`
}

function todayParts(): DateParts {
  const parsed = parseInputValue(toBogotaDateTimeInput(new Date().toISOString()))

  return parsed ?? { year: 2026, month: 1, day: 1, hour: 9, minute: 0 }
}

function describeValue(value: string): string {
  if (value === '') {
    return 'Seleccionar fecha y hora'
  }

  const iso = toUtcIsoFromBogotaInput(value)

  return iso === null ? 'Seleccionar fecha y hora' : formatDate(iso)
}

/**
 * Selector de fecha y hora con calendario.
 *
 * - No expone un campo de texto: la fecha solo se elige con el calendario.
 * - Trabaja en America/Bogota y entrega el valor local `YYYY-MM-DDTHH:mm`.
 * - Conserva la hora al cambiar de día y permite limpiar el campo opcional.
 */
export default function DateTimeField({
  value,
  onChange,
  disabled = false,
  id,
  required = false,
  'aria-describedby': describedBy,
  'aria-invalid': invalid,
}: DateTimeFieldProps) {
  const generatedId = useId()
  const controlId = id ?? generatedId
  const popoverId = `${controlId}-calendar`
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const [open, setOpen] = useState(false)

  const selected = parseInputValue(value)
  const [view, setView] = useState(() => {
    const base = selected ?? todayParts()

    return { year: base.year, month: base.month }
  })

  useEffect(() => {
    const parsed = parseInputValue(value)

    if (parsed !== null) {
      setView({ year: parsed.year, month: parsed.month })
    }
  }, [value])

  useEffect(() => {
    if (!open) {
      return
    }

    function handlePointerDown(event: PointerEvent) {
      if (rootRef.current !== null && !rootRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  const cells = useMemo<DayCell[]>(() => {
    const firstOfMonth = new Date(Date.UTC(view.year, view.month - 1, 1))
    const startOffset = (firstOfMonth.getUTCDay() + 6) % 7

    return Array.from({ length: 42 }, (_, index) => {
      const date = new Date(
        Date.UTC(view.year, view.month - 1, 1 - startOffset + index),
      )
      const month = date.getUTCMonth() + 1

      return {
        year: date.getUTCFullYear(),
        month,
        day: date.getUTCDate(),
        inMonth: month === view.month && date.getUTCFullYear() === view.year,
      }
    })
  }, [view])

  function toggleCalendar() {
    if (disabled) {
      return
    }

    if (!open) {
      const base = parseInputValue(value) ?? todayParts()
      setView({ year: base.year, month: base.month })
    }

    setOpen((current) => !current)
  }

  function shiftMonth(delta: number) {
    const date = new Date(Date.UTC(view.year, view.month - 1 + delta, 1))
    setView({ year: date.getUTCFullYear(), month: date.getUTCMonth() + 1 })
  }

  function selectDay(cell: DayCell) {
    const base = parseInputValue(value)
    const time = base === null ? { hour: 9, minute: 0 } : { hour: base.hour, minute: base.minute }

    onChange(
      toInputValue({
        year: cell.year,
        month: cell.month,
        day: cell.day,
        hour: time.hour,
        minute: time.minute,
      }),
    )

    if (!cell.inMonth) {
      setView({ year: cell.year, month: cell.month })
    }
  }

  function changeTime(field: 'hour' | 'minute', raw: string) {
    const base = parseInputValue(value) ?? todayParts()
    const next: DateParts = {
      ...base,
      [field]: Number(raw),
    }

    onChange(toInputValue(next))
  }

  function selectToday() {
    const base = parseInputValue(value)
    const today = todayParts()
    const time = base === null ? { hour: 9, minute: 0 } : { hour: base.hour, minute: base.minute }

    onChange(
      toInputValue({
        year: today.year,
        month: today.month,
        day: today.day,
        hour: time.hour,
        minute: time.minute,
      }),
    )
    setView({ year: today.year, month: today.month })
  }

  const description = describeValue(value)

  return (
    <div className="date-picker" ref={rootRef}>
      <button
        ref={triggerRef}
        id={controlId}
        type="button"
        className={['date-picker-trigger', value === '' ? 'is-empty' : ''].filter(Boolean).join(' ')}
        onClick={toggleCalendar}
        disabled={disabled}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls={popoverId}
        aria-required={required || undefined}
        aria-describedby={describedBy || undefined}
        aria-invalid={invalid || undefined}
      >
        <span>{description}</span>
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.7"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          focusable="false"
        >
          <rect x="4" y="5.5" width="16" height="15" rx="2" />
          <path d="M8 3.5v4M16 3.5v4M4 10h16" />
        </svg>
      </button>

      {open && (
        <div
          id={popoverId}
          className="date-picker-popover"
          role="dialog"
          aria-label="Seleccionar fecha y hora"
        >
          <div className="date-picker-head">
            <button
              type="button"
              className="date-picker-nav"
              aria-label="Mes anterior"
              onClick={() => shiftMonth(-1)}
            >
              ‹
            </button>
            <span className="date-picker-month">
              {MONTHS[view.month - 1]} {view.year}
            </span>
            <button
              type="button"
              className="date-picker-nav"
              aria-label="Mes siguiente"
              onClick={() => shiftMonth(1)}
            >
              ›
            </button>
          </div>

          <div className="date-picker-weekdays" aria-hidden="true">
            {WEEKDAYS.map((weekday, index) => (
              <span key={`${weekday}-${index}`}>{weekday}</span>
            ))}
          </div>

          <div className="date-picker-grid" role="group" aria-label="Días del mes">
            {cells.map((cell) => {
              const isSelected =
                selected !== null &&
                selected.year === cell.year &&
                selected.month === cell.month &&
                selected.day === cell.day

              return (
                <button
                  key={`${cell.year}-${cell.month}-${cell.day}`}
                  type="button"
                  className={[
                    'date-picker-day',
                    cell.inMonth ? '' : 'is-outside',
                    isSelected ? 'is-selected' : '',
                  ]
                    .filter(Boolean)
                    .join(' ')}
                  aria-pressed={isSelected}
                  aria-label={`${cell.day} de ${MONTHS[cell.month - 1]} de ${cell.year}`}
                  onClick={() => selectDay(cell)}
                >
                  {cell.day}
                </button>
              )
            })}
          </div>

          <div className="date-picker-time">
            <label className="date-picker-time-field">
              <span>Hora</span>
              <select
                aria-label="Hora"
                value={selected?.hour ?? 9}
                onChange={(event) => changeTime('hour', event.target.value)}
              >
                {Array.from({ length: 24 }, (_, hour) => (
                  <option key={hour} value={hour}>
                    {pad(hour)}
                  </option>
                ))}
              </select>
            </label>
            <label className="date-picker-time-field">
              <span>Minutos</span>
              <select
                aria-label="Minutos"
                value={selected?.minute ?? 0}
                onChange={(event) => changeTime('minute', event.target.value)}
              >
                {Array.from({ length: 60 }, (_, minute) => (
                  <option key={minute} value={minute}>
                    {pad(minute)}
                  </option>
                ))}
              </select>
            </label>
            <span className="date-picker-zone">America/Bogota</span>
          </div>

          <div className="date-picker-actions">
            <button type="button" className="date-picker-action" onClick={selectToday}>
              Hoy
            </button>
            <button
              type="button"
              className="date-picker-action"
              onClick={() => onChange('')}
            >
              Limpiar
            </button>
            <button
              type="button"
              className="date-picker-action date-picker-action--primary"
              onClick={() => setOpen(false)}
            >
              Listo
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
