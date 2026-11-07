interface LocationLike {
  pathname?: unknown
  search?: unknown
  hash?: unknown
}

/**
 * Returns a safe in-app path to resume after login.
 *
 * Only same-origin absolute paths are accepted; protocol-relative or external
 * URLs fall back to the catalog to avoid an open redirect.
 */
export function postLoginPath(state: unknown): string {
  const from = (state as { from?: LocationLike } | null | undefined)?.from

  if (!from || typeof from.pathname !== 'string') {
    return '/'
  }

  const path = from.pathname

  if (!path.startsWith('/') || path.startsWith('//')) {
    return '/'
  }

  const search =
    typeof from.search === 'string' && from.search.startsWith('?') ? from.search : ''
  const hash = typeof from.hash === 'string' && from.hash.startsWith('#') ? from.hash : ''

  return `${path}${search}${hash}`
}
