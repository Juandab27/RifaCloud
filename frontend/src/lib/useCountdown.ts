import { useEffect, useState } from 'react'

export type CountdownTone = 'calm' | 'warning' | 'urgent' | 'expired'

export interface CountdownState {
  expired: boolean
  remainingMs: number
  /** Proporción restante (0-1) o `null` si no hay fecha de creación válida. */
  progress: number | null
  /** Formato `m:ss`, nunca negativo. */
  clock: string
  minutes: number
  seconds: number
  tone: CountdownTone
}

/**
 * Cuenta regresiva puramente cosmética para la reserva. El backend sigue
 * siendo la única autoridad sobre la expiración: este hook solo calcula el
 * tiempo restante a partir de `expires_at` (y `created_at` para la barra).
 */
export function useCountdown(
  expiresAt: string | null,
  startedAt: string | null = null,
): CountdownState | null {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    if (!expiresAt) {
      return
    }

    const target = new Date(expiresAt).getTime()

    if (Number.isNaN(target)) {
      return
    }

    setNow(Date.now())

    if (Date.now() >= target) {
      return
    }

    const timer = window.setInterval(() => {
      const current = Date.now()
      setNow(current)

      if (current >= target) {
        window.clearInterval(timer)
      }
    }, 1000)

    return () => window.clearInterval(timer)
  }, [expiresAt])

  if (!expiresAt) {
    return null
  }

  const target = new Date(expiresAt).getTime()

  if (Number.isNaN(target)) {
    return null
  }

  const remainingMs = Math.max(0, target - now)
  const expired = remainingMs <= 0
  const totalSeconds = Math.ceil(remainingMs / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  const clock = `${minutes}:${seconds.toString().padStart(2, '0')}`

  const startedAtMs = startedAt ? new Date(startedAt).getTime() : Number.NaN
  const totalMs = Number.isFinite(startedAtMs) ? target - startedAtMs : Number.NaN
  const progress =
    Number.isFinite(totalMs) && totalMs > 0
      ? Math.min(1, Math.max(0, remainingMs / totalMs))
      : null

  const tone: CountdownTone = expired
    ? 'expired'
    : remainingMs <= 60_000
      ? 'urgent'
      : remainingMs <= 180_000
        ? 'warning'
        : 'calm'

  return { expired, remainingMs, progress, clock, minutes, seconds, tone }
}
