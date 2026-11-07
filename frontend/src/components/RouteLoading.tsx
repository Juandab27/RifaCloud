/** Estado de carga compartido por las guardas de ruta. Solo presentación. */
import Spinner from './ui/Spinner'

export default function RouteLoading({ label = 'Cargando…' }: { label?: string }) {
  return (
    <div className="route-loading" role="status" aria-live="polite">
      <Spinner size="sm" />
      <span className="route-loading-label">{label}</span>
    </div>
  )
}
