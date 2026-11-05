export interface SkeletonProps {
  width?: string | number
  height?: string | number
  radius?: string | number
  /** Número de barras; la última se acorta para simular texto. */
  lines?: number
  className?: string
}

function toCssSize(value: string | number | undefined): string | undefined {
  if (value === undefined) {
    return undefined
  }

  return typeof value === 'number' ? `${value}px` : value
}

/** Marcador de carga puramente decorativo. */
export default function Skeleton({
  width,
  height = 14,
  radius,
  lines = 1,
  className,
}: SkeletonProps) {
  const baseStyle = {
    width: toCssSize(width),
    height: toCssSize(height),
    borderRadius: toCssSize(radius),
  }

  if (lines <= 1) {
    return (
      <span
        className={['skeleton', className].filter(Boolean).join(' ')}
        style={baseStyle}
        aria-hidden="true"
      />
    )
  }

  return (
    <span
      className={['skeleton-stack', className].filter(Boolean).join(' ')}
      aria-hidden="true"
    >
      {Array.from({ length: lines }, (_, index) => (
        <span
          key={index}
          className="skeleton"
          style={{
            ...baseStyle,
            width: index === lines - 1 ? '62%' : (baseStyle.width ?? '100%'),
          }}
        />
      ))}
    </span>
  )
}
