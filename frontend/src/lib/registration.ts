/**
 * Registration validation shared by the form and its unit tests.
 *
 * The backend repeats these rules, so the browser is a UX layer and the
 * server remains the authority.
 */

export const PASSWORD_MIN_LENGTH = 8

export interface PasswordChecks {
  minLength: boolean
  hasUppercase: boolean
  hasNumber: boolean
}

export function passwordChecks(value: string): PasswordChecks {
  return {
    minLength: value.length >= PASSWORD_MIN_LENGTH,
    hasUppercase: /[A-Z]/.test(value),
    hasNumber: /[0-9]/.test(value),
  }
}

export function isStrongPassword(value: string): boolean {
  const checks = passwordChecks(value)

  return checks.minLength && checks.hasUppercase && checks.hasNumber
}

/**
 * Normalizes the accepted Colombian mobile formats to the 10-digit national
 * number stored by the backend:
 *   3001234567, 300 123 4567, +57 300 123 4567, +573001234567.
 * Returns null for any other value.
 */
export function normalizeColombianMobile(value: string): string | null {
  const candidate = value.trim()

  if (candidate === '' || candidate.length > 30) {
    return null
  }

  if (!/^\+?[0-9(][0-9\s().-]*$/.test(candidate)) {
    return null
  }

  let digits = candidate.replace(/\D+/g, '')

  if (digits === '') {
    return null
  }

  if (candidate.startsWith('+')) {
    if (!digits.startsWith('57')) {
      return null
    }

    digits = digits.slice(2)
  } else if (digits.length === 12 && digits.startsWith('57')) {
    digits = digits.slice(2)
  }

  return /^3\d{9}$/.test(digits) ? digits : null
}

/**
 * Practical full-format email validation: one @, a non-empty local part, a
 * domain with at least one dot, valid labels and an alphabetic TLD. It allows
 * plus tags, subdomains and uppercase without imposing provider-specific rules.
 */
export function isValidEmail(value: string): boolean {
  const email = value.trim()

  if (email === '' || email.length > 255 || /\s/.test(email)) {
    return false
  }

  const at = email.indexOf('@')

  if (at <= 0 || at !== email.lastIndexOf('@')) {
    return false
  }

  const local = email.slice(0, at)
  const domain = email.slice(at + 1)

  if (
    local.length > 64 ||
    local.startsWith('.') ||
    local.endsWith('.') ||
    local.includes('..') ||
    !/^[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test(local)
  ) {
    return false
  }

  if (
    domain === '' ||
    domain.length > 253 ||
    domain.startsWith('.') ||
    domain.endsWith('.') ||
    domain.includes('..')
  ) {
    return false
  }

  const labels = domain.split('.')

  if (labels.length < 2) {
    return false
  }

  if (
    labels.some(
      (label) => !/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/.test(label),
    )
  ) {
    return false
  }

  return /^[A-Za-z]{2,63}$/.test(labels[labels.length - 1])
}
