/** Zona horaria oficial del producto. */
export const BOGOTA_TIME_ZONE = 'America/Bogota'

/**
 * Formatea un importe entero en pesos colombianos (COP).
 *
 * El dominio guarda pesos completos, no centavos. No se divide ni se
 * multiplica por 100: el valor recibido es el valor que se muestra.
 */
export function formatCop(amount: number, currency = 'COP'): string {
  if (!Number.isFinite(amount)) {
    return '—'
  }

  const formatted = new Intl.NumberFormat('es-CO', {
    maximumFractionDigits: 0,
  }).format(amount)

  return `$ ${formatted} ${currency.toUpperCase()}`
}

/** Número de rifa legible (p. ej. 10000 → 10.000). */
export function formatRaffleNumber(value: number): string {
  return new Intl.NumberFormat('es-CO').format(value)
}

/** Fecha y hora visibles, siempre en hora de Colombia. */
export function formatDate(value: string | null): string {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('es-CO', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: BOGOTA_TIME_ZONE,
  }).format(new Date(value))
}

interface BogotaParts {
  year: string
  month: string
  day: string
  hour: string
  minute: string
}

function bogotaParts(date: Date): BogotaParts {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BOGOTA_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date)

  const get = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((part) => part.type === type)?.value ?? ''

  return {
    year: get('year'),
    month: get('month'),
    day: get('day'),
    hour: get('hour'),
    minute: get('minute'),
  }
}

/**
 * Convierte un timestamp ISO (normalmente UTC) al valor que espera el
 * selector local: `YYYY-MM-DDTHH:mm` en America/Bogota.
 */
export function toBogotaDateTimeInput(value: string | null): string {
  if (!value) {
    return ''
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const parts = bogotaParts(date)

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

/**
 * Convierte `YYYY-MM-DDTHH:mm` interpretado en America/Bogota a un ISO UTC.
 *
 * Colombia no aplica horario de verano: el desplazamiento es siempre UTC-5.
 * Enviar el instante UTC (con `Z`) evita que el backend guarde la hora de
 * pared como si ya fuera UTC.
 */
export function toUtcIsoFromBogotaInput(value: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)

  if (!match) {
    return null
  }

  const [, year, month, day, hour, minute] = match
  const utc = new Date(
    Date.UTC(
      Number(year),
      Number(month) - 1,
      Number(day),
      Number(hour) + 5,
      Number(minute),
    ),
  )

  if (Number.isNaN(utc.getTime())) {
    return null
  }

  return utc.toISOString()
}
