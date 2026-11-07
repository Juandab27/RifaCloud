import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import axios from 'axios'
import { api, ensureCsrfCookie, UNAUTHORIZED_EVENT } from '../api/client'
import type { User } from '../types'

interface RegisterPayload {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}

type SessionState = 'checking' | 'authenticated' | 'anonymous' | 'unavailable'

interface AuthContextValue {
  user: User | null
  loading: boolean
  sessionUnavailable: boolean
  login: (email: string, password: string) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
  retrySession: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [sessionState, setSessionState] = useState<SessionState>('checking')

  const checkSession = useCallback(async () => {
    setSessionState('checking')

    try {
      const response = await api.get<{ data: User }>('/me')
      setUser(response.data.data)
      setSessionState('authenticated')
    } catch (caught) {
      // Only an explicit 401 means "anonymous". A 5xx, a timeout or a network
      // failure is an availability problem: keep the current identity so a
      // reload or a Mercado Pago return does not log the user out.
      if (axios.isAxiosError(caught) && caught.response?.status === 401) {
        setUser(null)
        setSessionState('anonymous')

        return
      }

      setSessionState('unavailable')
    }
  }, [])

  useEffect(() => {
    void checkSession()
  }, [checkSession])

  useEffect(() => {
    function handleUnauthorized() {
      setUser(null)
      setSessionState('anonymous')
    }

    window.addEventListener(UNAUTHORIZED_EVENT, handleUnauthorized)

    return () => {
      window.removeEventListener(UNAUTHORIZED_EVENT, handleUnauthorized)
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    await ensureCsrfCookie()
    const response = await api.post<{ data: User }>('/login', { email, password })
    setUser(response.data.data)
    setSessionState('authenticated')
  }, [])

  const register = useCallback(async (payload: RegisterPayload) => {
    await ensureCsrfCookie()
    const response = await api.post<{ data: User }>('/register', payload)
    setUser(response.data.data)
    setSessionState('authenticated')
  }, [])

  const logout = useCallback(async () => {
    try {
      await ensureCsrfCookie()
      await api.post('/logout')
    } catch {
      // A failed server logout must not leave the UI half-authenticated. The
      // server session may survive, but the next /me check will restore it.
    } finally {
      setUser(null)
      setSessionState('anonymous')
    }
  }, [])

  const retrySession = useCallback(async () => {
    await checkSession()
  }, [checkSession])

  const value = useMemo(
    () => ({
      user,
      loading: sessionState === 'checking',
      sessionUnavailable: sessionState === 'unavailable',
      login,
      register,
      logout,
      retrySession,
    }),
    [user, sessionState, login, register, logout, retrySession],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === undefined) {
    throw new Error('useAuth must be used inside an AuthProvider')
  }

  return context
}
