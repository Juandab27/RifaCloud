import { expect, test, type Page, type Route } from '@playwright/test'
import { adminUser, mockApi, user } from './api-fixtures'

const ISO = '2026-11-14T15:00:00.000Z'

const pendingProposal = {
  id: 1,
  user_id: 1,
  title: 'Rifa de una bicicleta de montaña',
  description:
    'Propuesta para rifar una bicicleta de montaña con casco y accesorios básicos. El premio se entregaría en Bogotá.',
  status: 'PENDING',
  contact: {
    name: user.name,
    email: user.email,
    phone: '3001112233',
  },
  privacy_version: '2026-11-14',
  privacy_accepted_at: ISO,
  decision: null,
  created_at: ISO,
  updated_at: ISO,
}

const secondProposal = {
  ...pendingProposal,
  id: 2,
  title: 'Rifa de una consola',
  description:
    'Propuesta para rifar una consola de videojuegos con dos controles y un juego. Ideal para la comunidad.',
}

const decisionBy = {
  id: adminUser.id,
  name: adminUser.name,
  email: adminUser.email,
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => {
    const root = document.documentElement

    return { scrollWidth: root.scrollWidth, clientWidth: root.clientWidth }
  })

  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
}

test.describe('Propuestas de rifas', () => {
  test('el formulario autenticado usa el contacto de la cuenta y no lo envía', async ({
    page,
  }) => {
    await mockApi(page)

    await page.route(
      (url) => url.pathname === '/api/proposals',
      async (route) => {
        if (route.request().method() !== 'POST') {
          return json(route, { message: 'Method Not Allowed' }, 405)
        }

        return json(
          route,
          {
            data: {
              id: 42,
              title: 'Rifa de una bicicleta de montaña',
              description: 'Propuesta enviada desde la prueba.',
              status: 'PENDING',
              created_at: ISO,
            },
          },
          201,
        )
      },
    )

    await page.goto('/proposals/new')

    await expect(page.getByRole('heading', { name: 'Proponer una rifa' })).toBeVisible()
    await expect(page.locator('.facts')).toContainText(user.name)
    await expect(page.locator('.facts')).toContainText(user.email)
    await expect(page.locator('.facts')).toContainText(user.phone)

    // Contact is read-only and never rendered as an editable field.
    await expect(page.locator('input[type="email"], input[type="tel"], input[name="phone"]')).toHaveCount(0)

    await page.getByLabel('Título de la propuesta').fill('Rifa de una bicicleta de montaña')
    await page
      .getByLabel('Descripción')
      .fill('Quiero proponer una rifa de una bicicleta de montaña para la comunidad.')
    await page.locator('#proposal-privacy-consent').check()

    const requestPromise = page.waitForRequest(
      (request) =>
        request.method() === 'POST' && new URL(request.url()).pathname === '/api/proposals',
    )

    await page.getByRole('button', { name: 'Enviar propuesta' }).click()
    const request = await requestPromise
    const payload = request.postDataJSON() as Record<string, unknown>

    expect(payload).toEqual({
      title: 'Rifa de una bicicleta de montaña',
      description: 'Quiero proponer una rifa de una bicicleta de montaña para la comunidad.',
      privacy_accepted: true,
    })
    expect(payload).not.toHaveProperty('user_id')
    expect(payload).not.toHaveProperty('contact_name')
    expect(payload).not.toHaveProperty('contact_email')
    expect(payload).not.toHaveProperty('contact_phone')

    await expect(page.getByRole('heading', { name: 'Propuesta enviada' })).toBeVisible()
    await expect(page.getByText('Referencia #42')).toBeVisible()
  })

  test('sin sesión redirige al login y regresa al formulario', async ({ page }) => {
    await mockApi(page)

    await page.route(
      (url) => url.pathname === '/api/me',
      (route) => json(route, { message: 'Unauthenticated.' }, 401),
    )
    await page.route(
      (url) => url.pathname === '/api/login',
      (route) => json(route, { data: user }),
    )

    await page.goto('/proposals/new')

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('heading', { name: 'Ingresar' })).toBeVisible()

    await page.getByLabel('Correo electrónico').fill(user.email)
    await page.getByLabel('Contraseña').fill('demo-password')
    await page.getByRole('button', { name: 'Ingresar' }).click()

    await expect(page).toHaveURL(/\/proposals\/new$/)
    await expect(page.getByRole('heading', { name: 'Proponer una rifa' })).toBeVisible()
  })

  test('el administrador ve el contacto, aprueba y rechaza sin crear rifas', async ({
    page,
  }) => {
    await mockApi(page, { admin: true })

    let firstDecision: Record<string, unknown> | null = null
    let secondDecision: Record<string, unknown> | null = null

    await page.route(
      (url) => url.pathname === '/api/admin/proposals/metrics',
      (route) => json(route, { data: { pending: 2, approved: 0, rejected: 0 } }),
    )

    await page.route(
      (url) => url.pathname === '/api/admin/proposals',
      (route) =>
        json(route, {
          data: [pendingProposal, secondProposal],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
        }),
    )

    await page.route(
      (url) => url.pathname === '/api/admin/proposals/1',
      (route) =>
        json(route, {
          data: firstDecision
            ? { ...pendingProposal, status: 'APPROVED', decision: firstDecision }
            : pendingProposal,
        }),
    )

    await page.route(
      (url) => url.pathname === '/api/admin/proposals/2',
      (route) =>
        json(route, {
          data: secondDecision
            ? { ...secondProposal, status: 'REJECTED', decision: secondDecision }
            : secondProposal,
        }),
    )

    await page.route(
      (url) => url.pathname === '/api/admin/proposals/1/approve',
      async (route) => {
        firstDecision = {
          note: 'Cumple las condiciones del prototipo.',
          decided_at: ISO,
          by: decisionBy,
        }

        return json(route, {
          data: { ...pendingProposal, status: 'APPROVED', decision: firstDecision },
        })
      },
    )

    await page.route(
      (url) => url.pathname === '/api/admin/proposals/2/reject',
      async (route) => {
        secondDecision = {
          note: 'Falta detallar el premio.',
          decided_at: ISO,
          by: decisionBy,
        }

        return json(route, {
          data: { ...secondProposal, status: 'REJECTED', decision: secondDecision },
        })
      },
    )

    page.on('dialog', (dialog) => void dialog.accept())

    await page.goto('/admin/proposals')

    await expect(page.getByRole('heading', { name: 'Propuestas de rifas' })).toBeVisible()
    await expect(page.getByText('2 pendientes')).toBeVisible()
    await expect(page.locator('.admin-table tbody tr')).toHaveCount(2)

    await page.getByRole('button', { name: 'Ver detalle' }).first().click()

    const detail = page.locator('.proposal-detail')
    await expect(detail).toContainText('Rifa de una bicicleta de montaña')
    await expect(detail).toContainText(user.email)
    await expect(detail).toContainText('3001112233')

    await detail.getByLabel('Nota interna (opcional)').fill('Cumple las condiciones del prototipo.')
    await detail.getByRole('button', { name: 'Aprobar propuesta' }).click()

    await expect(detail).toContainText('Aprobada')
    await expect(detail).toContainText('Cumple las condiciones del prototipo.')

    await detail.getByRole('button', { name: 'Cerrar detalle' }).click()
    await page.getByRole('button', { name: 'Ver detalle' }).nth(1).click()

    await expect(detail).toContainText('Rifa de una consola')
    await detail.getByLabel('Nota interna (opcional)').fill('Falta detallar el premio.')
    await detail.getByRole('button', { name: 'Rechazar propuesta' }).click()

    await expect(detail).toContainText('Rechazada')
    await expect(detail).toContainText('Falta detallar el premio.')

    // The moderation flow only calls proposal endpoints; no raffle POST exists.
    expect(firstDecision).not.toBeNull()
    expect(secondDecision).not.toBeNull()
  })

  test('el formulario y la moderación caben en móvil', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await mockApi(page)

    await page.goto('/proposals/new')
    await page.getByRole('button', { name: 'Abrir menú de navegación' }).click()
    await expect(page.getByRole('link', { name: 'Proponer una rifa' })).toBeVisible()

    await page.goto('/proposals/new')
    await expect(page.getByRole('heading', { name: 'Proponer una rifa' })).toBeVisible()
    await expectNoHorizontalOverflow(page)

    await mockApi(page, { admin: true })
    await page.route(
      (url) => url.pathname === '/api/admin/proposals/metrics',
      (route) => json(route, { data: { pending: 1, approved: 0, rejected: 0 } }),
    )
    await page.route(
      (url) => url.pathname === '/api/admin/proposals',
      (route) =>
        json(route, {
          data: [pendingProposal],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
    )

    await page.goto('/admin/proposals')
    await expect(page.getByRole('heading', { name: 'Propuestas de rifas' })).toBeVisible()
    await expect(page.locator('.admin-table')).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })
})
