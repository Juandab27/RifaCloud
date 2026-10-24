import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export default function AdminRoute() {
  const { user, loading } = useAuth()

  if (loading) {
    return <p className="muted">Cargando…</p>
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  if (user.role !== 'ADMIN') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
