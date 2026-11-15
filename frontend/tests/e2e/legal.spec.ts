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

test('el pie público muestra privacidad, términos y contacto sin enlaces internos', async ({
  page,
}) => {
  await mockApi(page)
  await anonymousSession(page)
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'])

  await page.goto('/')
  await expect(page.getByRole('note')).toHaveCount(0)

  const footer = page.getByRole('contentinfo')
  await expect(footer.getByRole('link', { name: 'Privacidad', exact: true })).toBeVisible()
  await expect(footer.getByRole('link', { name: 'Términos', exact: true })).toBeVisible()

  const contact = footer.getByRole('link', { name: 'Contacto', exact: true })
  await expect(contact).toBeVisible()
  await expect(contact).toHaveAttribute('href', 'mailto:rifacloud27@gmail.com')

  const emailLink = footer.getByRole('link', { name: 'rifacloud27@gmail.com' })
  await expect(emailLink).toBeVisible()
  await expect(emailLink).toHaveAttribute('href', 'mailto:rifacloud27@gmail.com')

  const copyButton = footer.getByRole('button', { name: 'Copiar dirección de contacto' })
  await expect(copyButton).toBeVisible()
  await copyButton.click()
  await expect(footer.getByRole('status')).toContainText('Correo copiado al portapapeles.')
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
    'rifacloud27@gmail.com',
  )

  await expect(
    footer.getByRole('link', { name: 'Alcance regulatorio', exact: true }),
  ).toHaveCount(0)
  await expect(footer).toContainText(/proyecto académico/i)
  await expect(footer).toContainText('no se realizan cobros reales')

  await page.goto('/login')
  await expect(page.getByRole('heading', { level: 1, name: 'Ingresar' })).toBeVisible()
  await expect(page.getByRole('contentinfo')).toBeVisible()

  await page.goto('/raffles/2')
  await expect(
    page.getByRole('heading', { level: 1, name: 'Rifa disponible' }),
  ).toBeVisible()
  await expect(page.getByRole('contentinfo')).toBeVisible()
})

test('las páginas legales públicas no muestran borradores ni marcadores internos', async ({
  page,
}) => {
  await mockApi(page)
  await anonymousSession(page)
  await page.goto('/')

  const footer = page.getByRole('contentinfo')

  await footer.getByRole('link', { name: 'Privacidad', exact: true }).click()
  await expect(page).toHaveURL(/\/privacidad$/)
  await expect(
    page.getByRole('heading', { level: 1, name: /Política de Tratamiento de Datos Personales/ }),
  ).toBeVisible()
  await expect(page.getByText('Equipo de RifaCloud').first()).toBeVisible()
  await expect(page.getByText('rifacloud27@gmail.com').first()).toBeVisible()
  await expect(
    page.locator('a[href="mailto:rifacloud27@gmail.com"]').first(),
  ).toBeVisible()
  await expect(page.getByText(/Borrador|Documento preliminar|PENDIENTE/)).toHaveCount(0)

  await footer.getByRole('link', { name: 'Términos', exact: true }).click()
  await expect(page).toHaveURL(/\/terminos$/)
  await expect(
    page.getByRole('heading', { level: 1, name: 'Términos y Condiciones' }),
  ).toBeVisible()
  await expect(page.getByText(/no opera rifas comerciales/i)).toBeVisible()
  await expect(
    page.locator('a[href="mailto:rifacloud27@gmail.com"]').first(),
  ).toBeVisible()
  await expect(page.getByText(/Borrador|Documento preliminar|PENDIENTE/)).toHaveCount(0)

  // The old regulatory route remains reachable for compatibility but is not
  // promoted in the footer.
  await page.goto('/alcance-regulatorio')
  await expect(
    page.getByRole('heading', { level: 1, name: /Alcance regulatorio de las rifas en Colombia/ }),
  ).toBeVisible()
  await expect(page.getByText(/Borrador|Documento preliminar|PENDIENTE/)).toHaveCount(0)
})

test('el registro exige privacidad y términos y no pide consentimiento promocional', async ({
  page,
}) => {
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

  const privacy = page.getByRole('checkbox', {
    name: /Política de Tratamiento de Datos Personales/,
  })
  const terms = page.getByRole('checkbox', {
    name: /Términos y Condiciones/,
  })

  await expect(privacy).not.toBeChecked()
  await expect(terms).not.toBeChecked()
  await expect(
    page.getByRole('checkbox', { name: /novedades sobre RifaCloud/i }),
  ).toHaveCount(0)

  expect(
    await privacy.evaluate((element) => (element as HTMLInputElement).checkValidity()),
  ).toBe(false)

  await page.getByLabel('Nombre completo').fill('Persona de prueba')
  await page.getByLabel('Correo electrónico').fill('consent@example.invalid')
  await page.getByLabel('Teléfono celular').fill('+57 300 000 0000')
  await page.locator('input[autocomplete="new-password"]').first().fill('Secret123')
  await page.locator('input[autocomplete="new-password"]').nth(1).fill('Secret123')

  await privacy.check()
  await terms.check()

  await page.getByRole('button', { name: 'Registrarme' }).click()

  await expect.poll(() => payload).not.toBeNull()

  expect(payload).toMatchObject({
    name: 'Persona de prueba',
    email: 'consent@example.invalid',
    phone: '3000000000',
    privacy_consent: true,
    terms_consent: true,
    marketing_consent: false,
    consent_version: '2026-11-15',
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
  await expect(page.getByRole('contentinfo')).toContainText(/proyecto académico/i)
  await expect(
    page.getByRole('contentinfo').getByRole('link', { name: 'rifacloud27@gmail.com' }),
  ).toBeVisible()
  await expect(
    page.getByRole('contentinfo').getByRole('button', { name: 'Copiar dirección de contacto' }),
  ).toBeVisible()
})
