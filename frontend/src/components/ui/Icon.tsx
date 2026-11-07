import type { ReactNode } from 'react'

export type IconName = 'ticket' | 'bag' | 'alert' | 'check' | 'info' | 'warning'

export interface IconProps {
  name: IconName
  size?: number
  className?: string
}

const PATHS: Record<IconName, ReactNode> = {
  // Entrada / rifa
  ticket: (
    <>
      <path d="M4 9.5A1.5 1.5 0 0 1 5.5 8h13A1.5 1.5 0 0 1 20 9.5v.75a1.75 1.75 0 0 0 0 3.5v.75a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 14.5v-.75a1.75 1.75 0 0 0 0-3.5V9.5Z" />
      <path d="M14 8v8" strokeDasharray="2 3" />
    </>
  ),
  // Compras
  bag: (
    <>
      <path d="M6.25 8h11.5l-1 12H7.25l-1-12Z" />
      <path d="M9 8V6.5a3 3 0 0 1 6 0V8" />
    </>
  ),
  alert: (
    <>
      <path d="M12 4 2.75 20h18.5L12 4Z" />
      <path d="M12 10v4" />
      <path d="M12 17.25h.01" />
    </>
  ),
  check: (
    <>
      <circle cx="12" cy="12" r="8.5" />
      <path d="m8.5 12.25 2.5 2.5 4.5-5" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 11v5" />
      <path d="M12 8h.01" />
    </>
  ),
  warning: (
    <>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5v5.5" />
      <path d="M12 16.5h.01" />
    </>
  ),
}

/**
 * Icono decorativo del sistema. Nunca aporta significado por sí solo: siempre
 * acompaña a un texto visible o a una etiqueta accesible.
 */
export default function Icon({ name, size = 20, className }: IconProps) {
  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name]}
    </svg>
  )
}
