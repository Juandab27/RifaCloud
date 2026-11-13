/**
 * S11: single source for the public legal document version and routes.
 *
 * The backend refuses a registration whose `consent_version` does not match
 * the configured version. When the privacy policy or the prototype terms
 * change substantially, bump this value (and LEGAL_CONSENT_VERSION in the
 * backend environment) so consent evidence always points to the exact text
 * the person saw.
 */
export const LEGAL_VERSION = '2026-11-11'

export const LEGAL_UPDATED_AT = '11 de noviembre de 2026'

export const LEGAL_ROUTES = {
  privacy: '/privacidad',
  terms: '/terminos',
  regulatory: '/alcance-regulatorio',
} as const

/** Sentence used by the contextual checkout note for the simulated gateway. */
export const CHECKOUT_SIMULATED_NOTICE =
  'Demostración: el pago se procesa con el gateway simulado interno. No se cobra dinero real.'

/** Sentence used by the contextual checkout note for Mercado Pago sandbox. */
export const CHECKOUT_SANDBOX_NOTICE =
  'El pago se realiza en el checkout de Mercado Pago. Este prototipo usa el entorno sandbox: no se cobra dinero real y la confirmación se valida en el servidor.'
