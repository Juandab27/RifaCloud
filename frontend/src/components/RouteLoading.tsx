/** Estado de carga compartido por las guardas de ruta. Solo presentación. */
export default function RouteLoading({ label = 'Cargando…' }: { label?: string }) {
  return (
    <div className="route-loading" role="status" aria-live="polite">
      <span className="ui-spinner" aria-hidden="true" />
      <span className="route-loading-label">{label}</span>
    </div>
  )
}
