import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import RouteLoading from './RouteLoading'

export default function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) {
    return <RouteLoading label="Comprobando sesión…" />
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}
