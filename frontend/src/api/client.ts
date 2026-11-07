import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'

export const UNAUTHORIZED_EVENT = 'rifacloud:unauthorized'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

/**
 * Sanctum SPA authentication requires the CSRF cookie before any stateful
 * POST request (login, register, logout).
 */
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

type RetryableRequest = InternalAxiosRequestConfig & { _csrfRetried?: boolean }

/**
 * Session and CSRF resilience:
 *
 *  - 401 means the session is gone. It notifies the AuthProvider so the whole
 *    UI logs out once, instead of each page guessing.
 *  - 419 means the XSRF token expired (the session may still be valid). It
 *    refreshes the cookie and replays the request once, preserving the form
 *    state and the user's place in the flow.
 *  - 409/503 are business/availability responses: they are never retried
 *    blindly and never clear the session.
 */
api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const status = error.response?.status
    const config = error.config as RetryableRequest | undefined

    if (status === 401 && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent(UNAUTHORIZED_EVENT))

      return Promise.reject(error)
    }

    if (status === 419 && config !== undefined && config._csrfRetried !== true) {
      config._csrfRetried = true

      try {
        await ensureCsrfCookie()

        return await api.request(config)
      } catch {
        return Promise.reject(error)
      }
    }

    return Promise.reject(error)
  },
)

export function apiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined

    if (data?.errors) {
      const firstError = Object.values(data.errors)[0]
      if (firstError?.length) {
        return firstError[0]
      }
    }

    if (data?.message) {
      return data.message
    }

    if (error.response?.status === 401) {
      return 'Debes iniciar sesión.'
    }

    if (error.response?.status === 403) {
      return 'No tienes permisos para esta acción.'
    }

    if (error.response?.status === 419) {
      return 'La sesión de seguridad expiró. Inténtalo de nuevo.'
    }

    if (error.response?.status === 429) {
      return 'Demasiados intentos. Espera un momento antes de volver a intentarlo.'
    }

    if (error.response?.status === 503) {
      return 'El servicio no está disponible en este momento. Inténtalo de nuevo.'
    }

    if (error.response === undefined) {
      return 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.'
    }
  }

  return 'Ocurrió un error inesperado.'
}
