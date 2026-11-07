import Skeleton from './Skeleton'

export type PageSkeletonVariant = 'catalog' | 'list' | 'detail'

export interface PageSkeletonProps {
  variant?: PageSkeletonVariant
  /** Texto anunciado a lectores de pantalla. */
  label?: string
  /** Número de tarjetas para `catalog` y `list`. */
  count?: number
}

/**
 * Esqueleto de página reutilizable. Mantiene el ritmo visual de la pantalla
 * real (tarjetas, resumen y cifras) para que la carga no provoque saltos.
 */
export default function PageSkeleton({
  variant = 'list',
  label = 'Cargando…',
  count = 2,
}: PageSkeletonProps) {
  const cards = Math.max(1, count)

  return (
    <>
      <p className="ui-sr-only" role="status">
        {label}
      </p>

      <div className={`page-skeleton page-skeleton--${variant}`} aria-hidden="true">
        {variant === 'catalog' &&
          Array.from({ length: cards }, (_, index) => (
            <div className="page-skeleton-card" key={index}>
              <Skeleton width="72%" height={22} />
              <Skeleton lines={2} />
              <Skeleton width="48%" height={30} />
              <Skeleton height={42} radius={10} />
            </div>
          ))}

        {variant === 'list' &&
          Array.from({ length: cards }, (_, index) => (
            <div className="page-skeleton-card" key={index}>
              <Skeleton width="30%" height={12} />
              <Skeleton width="58%" height={24} />
              <Skeleton width="42%" height={18} radius={999} />
              <div className="page-skeleton-grid">
                <Skeleton height={42} radius={10} />
                <Skeleton height={42} radius={10} />
                <Skeleton height={42} radius={10} />
              </div>
              <Skeleton lines={2} />
            </div>
          ))}

        {variant === 'detail' && (
          <>
            <div className="page-skeleton-card">
              <Skeleton width="26%" height={12} />
              <Skeleton width="56%" height={32} />
              <Skeleton width="38%" height={20} radius={999} />
            </div>
            <div className="page-skeleton-card">
              <Skeleton lines={3} />
            </div>
            <div className="page-skeleton-card">
              <Skeleton width="34%" height={22} />
              <div className="page-skeleton-grid">
                <Skeleton height={44} radius={10} />
                <Skeleton height={44} radius={10} />
                <Skeleton height={44} radius={10} />
              </div>
            </div>
          </>
        )}
      </div>
    </>
  )
}
