import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import Icon from './ui/Icon'

export type ToastTone = 'success' | 'error' | 'info' | 'warning'

interface ToastEntry {
  id: number
  tone: ToastTone
  message: string
}

export interface ToastOptions {
  tone?: ToastTone
  /** Milisegundos antes de cerrarse sola; `0` la mantiene abierta. */
  duration?: number
}

export interface ToastApi {
  show: (message: string, options?: ToastOptions) => void
  success: (message: string, options?: Omit<ToastOptions, 'tone'>) => void
  error: (message: string, options?: Omit<ToastOptions, 'tone'>) => void
  info: (message: string, options?: Omit<ToastOptions, 'tone'>) => void
  warning: (message: string, options?: Omit<ToastOptions, 'tone'>) => void
}

const ToastContext = createContext<ToastApi | undefined>(undefined)

const DEFAULT_DURATION = 5200

const TONE_ICON: Record<ToastTone, 'check' | 'alert' | 'info' | 'warning'> = {
  success: 'check',
  error: 'alert',
  info: 'info',
  warning: 'warning',
}

const TONE_ROLE: Record<ToastTone, 'status' | 'alert'> = {
  success: 'status',
  error: 'alert',
  info: 'status',
  warning: 'alert',
}

/**
 * Mensajes breves de éxito, aviso o error. Viven por encima de las rutas para
 * sobrevivir a una navegación (por ejemplo, tras iniciar sesión o reservar).
 * No ejecuta lógica de negocio: solo presenta lo que la página ya sabe.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastEntry[]>([])
  const nextId = useRef(0)
  const timers = useRef<number[]>([])

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const show = useCallback(
    (message: string, options: ToastOptions = {}) => {
      const id = nextId.current + 1
      nextId.current = id

      const tone = options.tone ?? 'info'
      const duration = options.duration ?? DEFAULT_DURATION

      // Como máximo tres avisos visibles; el más antiguo se descarta.
      setToasts((current) => [...current.slice(-2), { id, tone, message }])

      if (duration > 0) {
        timers.current.push(window.setTimeout(() => dismiss(id), duration))
      }
    },
    [dismiss],
  )

  useEffect(
    () => () => {
      timers.current.forEach((timer) => window.clearTimeout(timer))
    },
    [],
  )

  const value = useMemo<ToastApi>(
    () => ({
      show,
      success: (message, options) => show(message, { ...options, tone: 'success' }),
      error: (message, options) => show(message, { ...options, tone: 'error' }),
      info: (message, options) => show(message, { ...options, tone: 'info' }),
      warning: (message, options) => show(message, { ...options, tone: 'warning' }),
    }),
    [show],
  )

  return (
    <ToastContext.Provider value={value}>
      {children}

      <div className="toast-viewport">
        {toasts.map((toast) => (
          <div
            key={toast.id}
            className={`toast toast-${toast.tone}`}
            role={TONE_ROLE[toast.tone]}
          >
            <span className="toast-icon" aria-hidden="true">
              <Icon name={TONE_ICON[toast.tone]} size={18} />
            </span>
            <span className="toast-message">{toast.message}</span>
            <button
              type="button"
              className="toast-close"
              aria-label="Cerrar notificación"
              onClick={() => dismiss(toast.id)}
            >
              ×
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const context = useContext(ToastContext)

  if (context === undefined) {
    throw new Error('useToast must be used inside a ToastProvider')
  }

  return context
}
