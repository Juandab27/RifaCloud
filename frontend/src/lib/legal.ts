/**
 * Public legal documents: version, date, routes and contact.
 *
 * The registration API validates `consent_version` against the configured
 * version. When these public texts change substantially, update this value and
 * keep LEGAL_CONSENT_VERSION in the backend environment aligned during the
 * deployment window.
 */
export const LEGAL_VERSION = '2026-11-15'

export const LEGAL_UPDATED_AT = '15 de noviembre de 2026'

export const CONTACT_EMAIL = 'rifacloud27@gmail.com'

export const LEGAL_ROUTES = {
  privacy: '/privacidad',
  terms: '/terminos',
  /** Kept for compatibility with previously shared links; not promoted in the UI. */
  regulatory: '/alcance-regulatorio',
} as const

/** Sentence used by the contextual checkout note for the simulated gateway. */
export const CHECKOUT_SIMULATED_NOTICE =
  'Demostración: el pago se procesa con el gateway simulado interno. No se cobra dinero real.'

/** Sentence used by the contextual checkout note for Mercado Pago sandbox. */
export const CHECKOUT_SANDBOX_NOTICE =
  'El pago se realiza en el checkout de Mercado Pago. Este prototipo usa el entorno sandbox: no se cobra dinero real y la confirmación se valida en el servidor.'
