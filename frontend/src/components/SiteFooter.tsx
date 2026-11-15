import { Link } from 'react-router-dom'
import ContactEmail from './ContactEmail'
import { CONTACT_EMAIL, LEGAL_ROUTES } from '../lib/legal'

/**
 * Persistent footer with the public legal documents and a discreet academic
 * notice. The regulatory summary remains available at its previous route for
 * compatibility but is no longer promoted here.
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
            Proyecto académico de computación en la nube. Las rifas y pagos son
            demostrativos; no se realizan cobros reales ni operaciones
            comerciales.
          </p>
        </div>

        <nav className="site-footer-nav" aria-label="Información legal">
          <Link to={LEGAL_ROUTES.privacy}>Privacidad</Link>
          <Link to={LEGAL_ROUTES.terms}>Términos</Link>
          <a href={`mailto:${CONTACT_EMAIL}`}>Contacto</a>
        </nav>

        <p className="site-footer-note">
          Equipo de RifaCloud · <ContactEmail showCopy />
        </p>
      </div>
    </footer>
  )
}
