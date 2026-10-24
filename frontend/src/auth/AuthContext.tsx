import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { api, ensureCsrfCookie } from '../api/client'
import type { User } from '../types'

interface RegisterPayload {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
}

interface AuthContextValue {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let active = true

    api
      .get<{ data: User }>('/me')
      .then((response) => {
        if (active) {
          setUser(response.data.data)
        }
      })
      .catch(() => {
        if (active) {
          setUser(null)
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    await ensureCsrfCookie()
    const response = await api.post<{ data: User }>('/login', { email, password })
    setUser(response.data.data)
  }, [])

  const register = useCallback(async (payload: RegisterPayload) => {
    await ensureCsrfCookie()
    const response = await api.post<{ data: User }>('/register', payload)
    setUser(response.data.data)
  }, [])

  const logout = useCallback(async () => {
    await ensureCsrfCookie()
    await api.post('/logout')
    setUser(null)
  }, [])

  const value = useMemo(
    () => ({ user, loading, login, register, logout }),
    [user, loading, login, register, logout],
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
