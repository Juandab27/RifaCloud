import assert from 'node:assert/strict'
import {
  isStrongPassword,
  isValidEmail,
  normalizeColombianMobile,
  passwordChecks,
} from '../src/lib/registration.ts'

/**
 * Focused checks for the registration rules shared by the form and tests.
 * No database, service or browser is involved.
 */

let checks = 0

function check(actual: unknown, expected: unknown, message: string): void {
  assert.deepEqual(actual, expected, message)
  checks += 1
}

check(
  passwordChecks('Abcdefg1'),
  { minLength: true, hasUppercase: true, hasNumber: true },
  'una contraseña válida cumple los tres requisitos',
)
check(
  passwordChecks('Abc1'),
  { minLength: false, hasUppercase: true, hasNumber: true },
  'detecta longitud insuficiente',
)
check(
  passwordChecks('abcdefg1'),
  { minLength: true, hasUppercase: false, hasNumber: true },
  'detecta falta de mayúscula',
)
check(
  passwordChecks('Abcdefgh'),
  { minLength: true, hasUppercase: true, hasNumber: false },
  'detecta falta de número',
)

check(isStrongPassword('Abcdefg1'), true, 'acepta 8 caracteres con mayúscula y número')
check(isStrongPassword('UnaClaveMuyLarga-Con_Simbolos!2026'), true, 'acepta claves largas y especiales')
check(isStrongPassword('Abcdefg1'), true, 'acepta exactamente 8 caracteres')
check(isStrongPassword('abcdefg1'), false, 'rechaza sin mayúscula')
check(isStrongPassword('Abcdefgh'), false, 'rechaza sin número')
check(isStrongPassword('Abc1'), false, 'rechaza menos de 8 caracteres')

check(normalizeColombianMobile('3001234567'), '3001234567', 'acepta 10 dígitos')
check(normalizeColombianMobile('300 123 4567'), '3001234567', 'acepta espacios')
check(normalizeColombianMobile('+57 300 123 4567'), '3001234567', 'acepta +57 con espacios')
check(normalizeColombianMobile('+573001234567'), '3001234567', 'acepta +57 compacto')
check(normalizeColombianMobile('573001234567'), '3001234567', 'acepta prefijo 57 sin +')
check(normalizeColombianMobile('(300) 123-4567'), '3001234567', 'acepta paréntesis y guiones')
check(normalizeColombianMobile('  3001234567  '), '3001234567', 'recorta espacios externos')

for (const invalid of [
  '',
  '300123456',
  '30012345678',
  '4001234567',
  '0000000000',
  '30012A4567',
  '+1 3001234567',
  '300 123 456',
  '+57 400 123 4567',
]) {
  check(normalizeColombianMobile(invalid), null, `rechaza teléfono inválido: ${invalid || '(vacío)'}`)
}

for (const valid of [
  'ana@example.com',
  'ana+rifas@sub.example.co',
  'ANA@EXAMPLE.COM',
  "o'brien@example.com",
  'nombre.apellido@dominio.com.co',
]) {
  check(isValidEmail(valid), true, `acepta correo válido: ${valid}`)
}

for (const invalid of [
  '',
  'ana',
  'ana@',
  '@example.com',
  'ana@example',
  'ana @example.com',
  'ana@@example.com',
  '.ana@example.com',
  'ana..x@example.com',
  'ana@-example.com',
  'ana@example..com',
  'ana@example.c',
  'ana@exa_mple.com',
]) {
  check(isValidEmail(invalid), false, `rechaza correo inválido: ${invalid || '(vacío)'}`)
}

check(isValidEmail('ana@example.com '), true, 'recorta espacios externos del correo')

console.log(`registration: ${checks} checks passed`)
