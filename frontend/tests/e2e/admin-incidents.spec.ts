import { expect, test, type Page, type Route } from '@playwright/test'

const admin = {
  id: 2,
  name: 'Admin de prueba',
  email: 'admin@example.invalid',
  phone: '0000000000',
  role: 'ADMIN',
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })
}

async function mockAdminApis(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    (route) => json(route, { data: admin }),
  )

  await page.route(
    (url) => url.pathname === '/api/admin/orders',
    (route) =>
      json(route, {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
      }),
  )

  await page.route(
    (url) => url.pathname === '/api/admin/payments/incidents',
    (route) =>
      json(route, {
        data: [
          {
            id: 70,
            order_id: 7,
            order_number: 'RC-0007',
            order_status: 'PAYMENT_REVIEW',
            status: 'UNKNOWN',
            amount: 100000,
            currency: 'COP',
            created_at: '2026-11-07T12:00:00+00:00',
            verified_at: null,
            customer: {
              id: 1,
              name: 'Comprador de prueba',
              email: 'buyer@example.invalid',
            },
            raffle: { id: 2, title: 'Rifa disponible' },
          },
          {
            id: 71,
            order_id: 8,
            order_number: 'RC-0008',
            order_status: 'REQUIRES_REFUND',
            status: 'REFUND_REQUIRED',
            amount: 100000,
            currency: 'COP',
            created_at: '2026-11-07T12:01:00+00:00',
            verified_at: null,
            customer: {
              id: 1,
              name: 'Comprador de prueba',
              email: 'buyer@example.invalid',
            },
            raffle: { id: 2, title: 'Rifa disponible' },
          },
          {
            id: 72,
            order_id: 9,
            order_number: 'RC-0009',
            order_status: 'EXPIRED',
            status: 'UNKNOWN',
            amount: 100000,
            currency: 'COP',
            created_at: '2026-11-07T12:02:00+00:00',
            verified_at: null,
            customer: {
              id: 1,
              name: 'Comprador de prueba',
              email: 'buyer@example.invalid',
            },
            raffle: { id: 2, title: 'Rifa disponible' },
          },
        ],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 3 },
      }),
  )
}

test('las incidencias solo ofrecen resolver cuando la orden está en revisión', async ({
  page,
}) => {
  await mockAdminApis(page)
  await page.goto('/admin/orders')

  await page.getByRole('button', { name: 'Incidencias de pago' }).click()

  await expect(page.locator('tbody tr')).toHaveCount(3)
  await expect(page.getByRole('button', { name: 'Resolver con proveedor' })).toHaveCount(1)
  await expect(page.getByText('Reembolso manual')).toHaveCount(1)
  await expect(page.getByText('Reconciliación automática')).toHaveCount(1)
})
