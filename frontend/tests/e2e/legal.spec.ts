import { expect, test } from '@playwright/test'
import { mockApi, user } from './api-fixtures'

function pendingOrder(simulated: boolean) {
  return {
    id: 9,
    order_number: 'RC-0009',
    status: 'PENDING_PAYMENT',
    total_amount: 1000,
    expires_at: new Date(Date.now() + 10 * 60 * 1000).toISOString(),
    paid_at: null,
    created_at: new Date().toISOString(),
    simulated_payments: simulated,
    raffle: { id: 2, title: 'Rifa disponible' },
    numbers: [{ id: 31, number: 30, status: 'RESERVED', price_at_purchase: 1000 }],
    payments: [],
  }
}

async function anonymousSession(page: Parameters<typeof mockApi>[0]) {
  await page.route(
    (url) => url.pathname === '/api/me',
    (route) =>
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated.' }),
      }),
  )
}

test('no hay franja global y el pie legal aparece en catálogo, login y detalle', async ({
  page,
}) => {
  await mockApi(page)
  await anonymousSession(page)

  await page.goto('/')
  await expect(page.getByRole('note')).toHaveCount(0)

  const footer = page.getByRole('contentinfo')
  await expect(footer.getByRole('link', { name: 'Privacidad', exact: true })).toBeVisible()
  await expect(footer.getByRole('link', { name: 'Términos', exact: true })).toBeVisible()
  await expect(
    footer.getByRole('link', { name: 'Alcance regulatorio', exact: true }),
  ).toBeVisible()
  await expect(footer).toContainText('no cuenta con autorización de Coljuegos')

  await page.goto('/login')
  await expect(page.getByRole('heading', { level: 1, name: 'Ingresar' })).toBeVisible()
  await expect(page.getByRole('note')).toHaveCount(0)
  await expect(page.getByRole('contentinfo')).toBeVisible()

  await page.goto('/raffles/2')
  await expect(
    page.getByRole('heading', { level: 1, name: 'Rifa disponible' }),
  ).toBeVisible()
  await expect(page.getByRole('note')).toHaveCount(0)
  await expect(page.getByRole('contentinfo')).toBeVisible()
})

test('los documentos legales son públicos desde el pie', async ({ page }) => {
  await mockApi(page)
  await anonymousSession(page)
  await page.goto('/')

  const footer = page.getByRole('contentinfo')

  await footer.getByRole('link', { name: 'Privacidad', exact: true }).click()
  await expect(page).toHaveURL(/\/privacidad$/)
  await expect(
    page.getByRole('heading', { level: 1, name: /Política de Tratamiento de Datos Personales/ }),
  ).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Aviso de privacidad resumido' })).toBeVisible()
  await expect(page.getByText(/no asesoría jurídica/i).first()).toBeVisible()

  await footer.getByRole('link', { name: 'Términos', exact: true }).click()
  await expect(page).toHaveURL(/\/terminos$/)
  await expect(
    page.getByRole('heading', { level: 1, name: /Términos y Condiciones del prototipo/ }),
  ).toBeVisible()
  await expect(page.getByText(/No hay premios reales/i)).toBeVisible()

  await footer.getByRole('link', { name: 'Alcance regulatorio', exact: true }).click()
  await expect(page).toHaveURL(/\/alcance-regulatorio$/)
  await expect(
    page.getByRole('heading', { level: 1, name: /Alcance regulatorio de las rifas en Colombia/ }),
  ).toBeVisible()
  await expect(page.getByText(/No es un operador autorizado de juegos de suerte y azar/i)).toBeVisible()
})

test('el registro exige los consentimientos y separa el promocional', async ({ page }) => {
  await mockApi(page)
  await anonymousSession(page)

  let payload: Record<string, unknown> | null = null

  await page.route(
    (url) => url.pathname === '/api/register',
    async (route) => {
      payload = route.request().postDataJSON() as Record<string, unknown>

      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ data: user }),
      })
    },
  )

  await page.goto('/register')

  const privacy = page.getByRole('checkbox', { name: /Política de Tratamiento de Datos Personales/ })
  const terms = page.getByRole('checkbox', { name: /Términos y Condiciones del prototipo/ })
  const marketing = page.getByRole('checkbox', { name: /novedades sobre RifaCloud/ })

  await expect(privacy).not.toBeChecked()
  await expect(terms).not.toBeChecked()
  await expect(marketing).not.toBeChecked()

  // Both required boxes are native-required, so the form cannot be submitted
  // until the person accepts the two documents.
  expect(await privacy.evaluate((element) => (element as HTMLInputElement).checkValidity())).toBe(false)

  await page.getByLabel('Nombre completo').fill('Persona de prueba')
  await page.getByLabel('Correo electrónico').fill('consent@example.invalid')
  await page.getByLabel('Teléfono').fill('3000000000')
  await page.locator('input[autocomplete="new-password"]').first().fill('secret-password')
  await page.locator('input[autocomplete="new-password"]').nth(1).fill('secret-password')

  await privacy.check()
  await terms.check()

  await page.getByRole('button', { name: 'Registrarme' }).click()

  await expect.poll(() => payload).not.toBeNull()

  expect(payload).toMatchObject({
    name: 'Persona de prueba',
    email: 'consent@example.invalid',
    privacy_consent: true,
    terms_consent: true,
    marketing_consent: false,
    consent_version: '2026-11-11',
  })
})

test('el checkout distingue el gateway simulado del sandbox de Mercado Pago', async ({
  page,
}) => {
  await mockApi(page)

  await page.route(
    (url) => url.pathname === '/api/orders/9',
    (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: pendingOrder(true) }),
      }),
  )

  await page.goto('/orders/9')

  await expect(page.getByText('Gateway simulado', { exact: true })).toBeVisible()
  await expect(page.getByText(/gateway simulado interno/i)).toBeVisible()
  await expect(page.getByText('Mercado Pago sandbox', { exact: true })).toHaveCount(0)
})

test('el checkout de Mercado Pago se identifica como sandbox, no como simulación interna', async ({
  page,
}) => {
  await mockApi(page)

  await page.route(
    (url) => url.pathname === '/api/orders/9',
    (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: pendingOrder(false) }),
      }),
  )

  await page.goto('/orders/9')

  await expect(page.getByText('Mercado Pago sandbox', { exact: true })).toBeVisible()
  await expect(page.getByText(/checkout de Mercado Pago/i)).toBeVisible()
  await expect(page.getByText(/gateway simulado interno/i)).toHaveCount(0)
})

test('las páginas legales se adaptan a móvil sin desbordar', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await mockApi(page)
  await page.goto('/privacidad')

  await expect(
    page.getByRole('heading', { level: 1, name: /Política de Tratamiento de Datos Personales/ }),
  ).toBeVisible()

  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
  )

  expect(overflow).toBe(false)
  await expect(page.getByRole('contentinfo')).toContainText('no cuenta con autorización de Coljuegos')
})
