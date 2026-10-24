import axios from 'axios'

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
  }

  return 'Ocurrió un error inesperado.'
}
