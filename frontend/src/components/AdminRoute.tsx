import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import RouteLoading from './RouteLoading'
import SessionUnavailable from './SessionUnavailable'

export default function AdminRoute() {
  const { user, loading, sessionUnavailable, retrySession } = useAuth()
  const location = useLocation()

  if (loading) {
    return <RouteLoading label="Comprobando permisos…" />
  }

  if (!user && sessionUnavailable) {
    return <SessionUnavailable onRetry={retrySession} />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />
  }

  if (user.role !== 'ADMIN') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
