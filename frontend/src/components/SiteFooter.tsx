import { Link } from 'react-router-dom'
import { LEGAL_ROUTES, LEGAL_VERSION } from '../lib/legal'

/**
 * S11: persistent footer with the public legal documents. It is intentionally
 * visible on every route, including login and registration, and repeats that
 * this is an academic prototype without Coljuegos authorization.
 */
export default function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="site-footer-inner">
        <div className="site-footer-identity">
          <Link to="/" className="site-footer-brand">
            <span className="brand-mark" aria-hidden="true">
              R
            </span>
            <span>RifaCloud</span>
          </Link>
          <p className="site-footer-copy">
            Prototipo académico de computación en la nube. No es un operador de
            rifas autorizado y no realiza ventas reales.
          </p>
        </div>

        <nav className="site-footer-nav" aria-label="Información legal">
          <Link to={LEGAL_ROUTES.privacy}>Privacidad</Link>
          <Link to={`${LEGAL_ROUTES.privacy}#aviso`}>Aviso de privacidad</Link>
          <Link to={`${LEGAL_ROUTES.privacy}#derechos`}>Derechos del titular</Link>
          <Link to={LEGAL_ROUTES.terms}>Términos</Link>
          <Link to={LEGAL_ROUTES.regulatory}>Alcance regulatorio</Link>
        </nav>

        <p className="site-footer-note">
          Documentos preliminares, no asesoría jurídica. Versión {LEGAL_VERSION}.
          RifaCloud no cuenta con autorización de Coljuegos.
        </p>
      </div>
    </footer>
  )
}
