import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export default function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  async function handleLogout() {
    await logout()
    navigate('/')
  }

  return (
    <div className="app">
      <header className="topbar">
        <Link to="/" className="brand">
          RifaCloud
        </Link>

        <nav className="nav">
          <NavLink to="/">Catálogo</NavLink>
          {user?.role === 'ADMIN' && <NavLink to="/admin/raffles">Administración</NavLink>}
        </nav>

        <div className="session">
          {user ? (
            <>
              <span className="muted">{user.name}</span>
              <button type="button" className="link-button" onClick={handleLogout}>
                Salir
              </button>
            </>
          ) : (
            <>
              <Link to="/login">Ingresar</Link>
              <Link to="/register">Registrarse</Link>
            </>
          )}
        </div>
      </header>

      <main className="content">
        <Outlet />
      </main>
    </div>
  )
}
