import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

/**
 * Shell de la aplicación: cabecera, navegación principal y contenedor.
 * La sesión y el cierre de sesión se delegan por completo a `useAuth`.
 */
export default function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)
  const toggleRef = useRef<HTMLButtonElement>(null)

  // El menú móvil se cierra al cambiar de ruta.
  useEffect(() => {
    setMenuOpen(false)
  }, [location.pathname])

  // Escape cierra el menú y devuelve el foco al control que lo abrió.
  useEffect(() => {
    if (!menuOpen) {
      return
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setMenuOpen(false)
        toggleRef.current?.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [menuOpen])

  async function handleLogout() {
    await logout()
    navigate('/')
  }

  return (
    <div className="app">
      <a className="skip-link" href="#main-content">
        Saltar al contenido
      </a>

      <header className="topbar">
        <div className="topbar-inner">
          <Link to="/" className="brand" onClick={() => setMenuOpen(false)}>
            <span className="brand-mark" aria-hidden="true">
              R
            </span>
            <span>RifaCloud</span>
          </Link>

          <button
            ref={toggleRef}
            type="button"
            className="menu-toggle"
            aria-expanded={menuOpen}
            aria-controls="primary-navigation"
            aria-label={menuOpen ? 'Cerrar menú de navegación' : 'Abrir menú de navegación'}
            onClick={() => setMenuOpen((open) => !open)}
          >
            <svg
              className="menu-toggle-icon"
              viewBox="0 0 24 24"
              width="20"
              height="20"
              aria-hidden="true"
              focusable="false"
            >
              {menuOpen ? (
                <path
                  d="M6 6l12 12M18 6L6 18"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                />
              ) : (
                <path
                  d="M4 7h16M4 12h16M4 17h16"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                />
              )}
            </svg>
          </button>

          <div id="primary-navigation" className={`topbar-panel${menuOpen ? ' is-open' : ''}`}>
            <nav className="nav" aria-label="Navegación principal">
              <NavLink to="/" end onClick={() => setMenuOpen(false)}>
                Catálogo
              </NavLink>
              {user?.role === 'ADMIN' && (
                <NavLink to="/admin/raffles" onClick={() => setMenuOpen(false)}>
                  Administración
                </NavLink>
              )}
            </nav>

            <div className="session">
              {user ? (
                <>
                  <span className="session-user" title={user.name}>
                    {user.name}
                  </span>
                  <button type="button" className="link-button" onClick={() => void handleLogout()}>
                    Salir
                  </button>
                </>
              ) : (
                <>
                  <Link className="button button-ghost" to="/login">
                    Ingresar
                  </Link>
                  <Link className="button" to="/register">
                    Registrarse
                  </Link>
                </>
              )}
            </div>
          </div>
        </div>
      </header>

      <main className="content" id="main-content" tabIndex={-1}>
        <Outlet />
      </main>
    </div>
  )
}
