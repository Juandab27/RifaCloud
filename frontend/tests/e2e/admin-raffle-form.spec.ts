import { expect, test, type Page, type Route } from '@playwright/test'

/**
 * Prueba enfocada del formulario administrativo:
 * - el precio se muestra y se envía en COP, sin conversiones;
 * - las fechas se eligen con el calendario y se envían como UTC;
 * - la hora de America/Bogota se conserva al cambiar de día.
 */

const admin = {
  id: 2,
  name: 'Admin de prueba',
  email: 'admin@example.invalid',
  phone: '0000000000',
  role: 'ADMIN',
}

const raffle = {
  id: 1,
  title: 'Rifa de prueba',
  description: 'Rifa para probar el formulario.',
  price_per_number: 1000,
  min_number: 1,
  max_number: 10,
  total_numbers: 10,
  status: 'DRAFT',
  reservation_minutes: 10,
  max_numbers_per_order: 50,
  starts_at: '2026-11-20T15:00:00+00:00',
  ends_at: '2026-11-25T15:00:00+00:00',
  numbers_count: 10,
  result: null,
  created_at: '2026-11-01T12:00:00+00:00',
  updated_at: '2026-11-01T12:00:00+00:00',
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })
}

async function mockAdminForm(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    (route) => json(route, { data: admin }),
  )

  await page.route(
    (url) => url.pathname === '/api/admin/raffles/1',
    (route) => {
      if (route.request().method() === 'PUT') {
        return json(route, { data: raffle })
      }

      return json(route, { data: raffle })
    },
  )

  await page.route(
    (url) => url.pathname === '/api/admin/raffles',
    (route) =>
      json(route, {
        data: [raffle],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
      }),
  )
}

test('el precio se edita en COP y las fechas se eligen en el calendario de Bogotá', async ({
  page,
}) => {
  await mockAdminForm(page)
  await page.goto('/admin/raffles/1/edit')

  const price = page.getByLabel('Precio por número (COP)')
  await expect(price).toHaveValue('1000')

  const start = page.getByLabel('Inicio (opcional)')
  await expect(start).toContainText('10:00')

  await start.click()
  await expect(page.getByRole('dialog', { name: 'Seleccionar fecha y hora' })).toBeVisible()
  await page.getByRole('button', { name: '21 de noviembre de 2026' }).click()
  await expect(start).toContainText(/21[/\s]/)
  await expect(start).toContainText('10:00')

  await page.getByLabel('Hora', { exact: true }).selectOption('8')
  await page.getByLabel('Minutos', { exact: true }).selectOption('30')
  await page.getByRole('button', { name: 'Listo' }).click()
  await expect(start).toContainText('8:30')

  await price.fill('50000')

  const requestPromise = page.waitForRequest(
    (request) =>
      request.method() === 'PUT' && new URL(request.url()).pathname === '/api/admin/raffles/1',
  )

  await page.getByRole('button', { name: 'Guardar cambios' }).click()

  const request = await requestPromise
  const payload = request.postDataJSON() as Record<string, unknown>

  expect(payload.price_per_number).toBe(50000)
  expect(payload.starts_at).toBe('2026-11-21T13:30:00.000Z')
})

test('editar una rifa existente no multiplica ni divide su precio', async ({ page }) => {
  await mockAdminForm(page)
  await page.goto('/admin/raffles/1/edit')

  const price = page.getByLabel('Precio por número (COP)')
  await expect(price).toHaveValue('1000')

  const requestPromise = page.waitForRequest(
    (request) =>
      request.method() === 'PUT' && new URL(request.url()).pathname === '/api/admin/raffles/1',
  )

  await page.getByRole('button', { name: 'Guardar cambios' }).click()

  const request = await requestPromise
  const payload = request.postDataJSON() as Record<string, unknown>

  expect(payload.price_per_number).toBe(1000)
})

test('rechaza una fecha de cierre igual o anterior al inicio antes de enviar', async ({
  page,
}) => {
  await mockAdminForm(page)
  await page.goto('/admin/raffles/1/edit')

  const end = page.getByLabel('Fin (opcional)')
  await end.click()
  await page.getByRole('button', { name: '20 de noviembre de 2026' }).click()
  await page.getByRole('button', { name: 'Listo' }).click()

  await page.getByRole('button', { name: 'Guardar cambios' }).click()

  await expect(
    page.getByText('La fecha de cierre debe ser posterior a la de inicio.', { exact: true }),
  ).toBeVisible()
})

test('el calendario cabe en un viewport móvil sin desbordamiento horizontal', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await mockAdminForm(page)
  await page.goto('/admin/raffles/1/edit')

  await page.getByLabel('Inicio (opcional)').click()

  const dialog = page.getByRole('dialog', { name: 'Seleccionar fecha y hora' })
  await expect(dialog).toBeVisible()

  const box = await dialog.boundingBox()
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  )

  expect(box).not.toBeNull()
  expect(box!.x).toBeGreaterThanOrEqual(0)
  expect(box!.x + box!.width).toBeLessThanOrEqual(390)
  expect(overflow).toBe(0)
})
