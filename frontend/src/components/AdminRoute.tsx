import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import RouteLoading from './RouteLoading'

export default function AdminRoute() {
  const { user, loading } = useAuth()

  if (loading) {
    return <RouteLoading label="Comprobando permisos…" />
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  if (user.role !== 'ADMIN') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
