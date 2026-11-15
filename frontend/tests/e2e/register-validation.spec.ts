import { expect, test, type Route } from '@playwright/test'
import { mockApi, user } from './api-fixtures'

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })
}

async function mockRegister(page: Parameters<typeof mockApi>[0]) {
  let payload: Record<string, unknown> | null = null
  let called = false

  await page.route(
    (url) => url.pathname === '/api/register',
    async (route) => {
      called = true
      payload = route.request().postDataJSON() as Record<string, unknown>

      return json(route, { data: user }, 201)
    },
  )

  return {
    wasCalled: () => called,
    payload: () => payload,
  }
}

async function fillValidIdentity(page: Parameters<typeof mockApi>[0]) {
  await page.getByLabel('Nombre completo').fill('Persona de prueba')
  await page.getByLabel('Correo electrónico').fill('persona@example.com')
  await page.getByLabel('Teléfono celular').fill('3001234567')
}

async function acceptConsents(page: Parameters<typeof mockApi>[0]) {
  await page.getByRole('checkbox', { name: /Pol.*tica de Tratamiento de Datos Personales/ }).check()
  await page.getByRole('checkbox', { name: /T.*rminos y Condiciones del prototipo/ }).check()
}

test.describe('Validaciones del registro', () => {
  test('exige contraseña fuerte, coincidencia y muestra la checklist dinámica', async ({
    page,
  }) => {
    await mockApi(page)
    const register = await mockRegister(page)

    await page.goto('/register')
    await fillValidIdentity(page)

    const password = page.locator('input[autocomplete="new-password"]').first()
    const confirmation = page.locator('input[autocomplete="new-password"]').nth(1)
    const metItems = page.locator('.password-checklist li.is-met')

    await password.fill('abc')
    await confirmation.fill('abc')
    await acceptConsents(page)
    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect(
      page.getByText(
        'La contraseña debe tener al menos 8 caracteres, una letra mayúscula y un número.',
      ),
    ).toBeVisible()
    await expect(metItems).toHaveCount(1)
    expect(register.wasCalled()).toBe(false)

    await password.fill('Abcdefg1')
    await expect(metItems).toHaveCount(3)

    await confirmation.fill('Abcdefg2')
    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect(page.getByText('Las contraseñas no coinciden.')).toBeVisible()
    await expect(metItems).toHaveCount(3)
    expect(register.wasCalled()).toBe(false)

    await confirmation.fill('Abcdefg1')
    await expect(metItems).toHaveCount(4)

    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect.poll(() => register.wasCalled()).toBe(true)
    expect(register.payload()).toMatchObject({
      password: 'Abcdefg1',
      password_confirmation: 'Abcdefg1',
    })
    await expect(page).toHaveURL(/\/$/)
  })

  test('muestra +57, normaliza formatos y rechaza correo mal formado', async ({ page }) => {
    await mockApi(page)
    const register = await mockRegister(page)

    await page.goto('/register')

    await expect(page.locator('.phone-prefix')).toHaveText('+57')

    const phone = page.getByLabel('Teléfono celular')
    await phone.fill('4001234567')
    await page.getByLabel('Nombre completo').fill('Persona de prueba')
    await page.locator('input[autocomplete="new-password"]').first().fill('ClaveSegura1')
    await page.locator('input[autocomplete="new-password"]').nth(1).fill('ClaveSegura1')
    await acceptConsents(page)
    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect(page.getByText('Ingresa un número de celular válido.')).toBeVisible()
    expect(register.wasCalled()).toBe(false)

    await phone.fill('+57 300 123 4567')
    await page.getByLabel('Correo electrónico').click()
    await expect(phone).toHaveValue('3001234567')

    await page.getByLabel('Correo electrónico').fill('persona@example')
    await page.locator('input[autocomplete="new-password"]').first().fill('ClaveSegura1')
    await page.locator('input[autocomplete="new-password"]').nth(1).fill('ClaveSegura1')
    await acceptConsents(page)
    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect(
      page.getByText(
        'Ingresa un correo electrónico válido (por ejemplo, nombre@dominio.com).',
      ),
    ).toBeVisible()
    expect(register.wasCalled()).toBe(false)

    await page.getByLabel('Correo electrónico').fill('persona+rifas@sub.example.com')
    await page.getByRole('button', { name: 'Registrarme' }).click()

    await expect.poll(() => register.wasCalled()).toBe(true)
    expect(register.payload()).toMatchObject({
      name: 'Persona de prueba',
      email: 'persona+rifas@sub.example.com',
      phone: '3001234567',
    })
  })

  test('el formulario de registro cabe en móvil', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await mockApi(page)
    await page.goto('/register')

    await expect(page.getByRole('heading', { name: 'Crear cuenta' })).toBeVisible()
    await expect(page.locator('.phone-prefix')).toHaveText('+57')

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))

    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
  })
})
