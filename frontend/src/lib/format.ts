export function formatCents(cents: number): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(cents / 100)
}

/** Número de rifa legible (p. ej. 10000 → 10.000). */
export function formatRaffleNumber(value: number): string {
  return new Intl.NumberFormat('es-CO').format(value)
}

export function formatDate(value: string | null): string {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('es-CO', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}
