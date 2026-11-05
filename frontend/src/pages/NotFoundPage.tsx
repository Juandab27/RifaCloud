import { Link } from 'react-router-dom'

export default function NotFoundPage() {
  return (
    <section className="not-found">
      <p className="page-header-eyebrow">Error 404</p>
      <h1>Página no encontrada</h1>
      <p className="muted">
        El enlace puede estar mal escrito o la página ya no existe.
      </p>
      <Link className="button" to="/">
        Volver al catálogo
      </Link>
    </section>
  )
}
