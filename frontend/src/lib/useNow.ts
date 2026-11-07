import { useEffect, useState } from 'react'

/**
 * Reloj de presentación para estados que dependen de la hora (ventanas de
 * venta). Se actualiza cada minuto por defecto y no consulta al backend.
 */
export function useNow(intervalMs = 60_000): Date {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), intervalMs)

    return () => {
      window.clearInterval(timer)
    }
  }, [intervalMs])

  return now
}
