import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

/**
 * Blocks private customer routes on the client. The backend still enforces
 * authentication and ownership for every order endpoint.
 */
export default function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) {
    return <p className="muted">Cargando…</p>
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
