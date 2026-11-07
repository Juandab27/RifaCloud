import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import RouteLoading from './RouteLoading'
import SessionUnavailable from './SessionUnavailable'

export default function RequireAuth() {
  const { user, loading, sessionUnavailable, retrySession } = useAuth()
  const location = useLocation()

  if (loading) {
    return <RouteLoading label="Comprobando sesión…" />
  }

  // A failed session check (503/timeout) is not a logout: keep the user on the
  // current page and offer a retry instead of redirecting to /login.
  if (!user && sessionUnavailable) {
    return <SessionUnavailable onRetry={retrySession} />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  return <Outlet />
}
