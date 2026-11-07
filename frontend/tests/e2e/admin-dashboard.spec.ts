import { expect, test, type Page } from '@playwright/test'
import { dashboardMetrics, mockApi } from './api-fixtures'

function metricCard(page: Page, label: string) {
  return page.locator('.metric-card', { hasText: label })
}

test.describe('Dashboard administrativo', () => {
  test('muestra las cuatro métricas confirmadas', async ({ page }) => {
    await mockApi(page, {
      admin: true,
      metrics: {
        raffles: () => ({ body: { data: dashboardMetrics.raffles } }),
        orders: () => ({ body: { data: dashboardMetrics.orders } }),
        payments: () => ({ body: { data: dashboardMetrics.payments } }),
      },
    })

    await page.goto('/admin/dashboard')

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
    await expect(metricCard(page, 'Rifas publicadas').locator('.metric-value')).toHaveText('3')
    await expect(metricCard(page, 'Órdenes pagadas').locator('.metric-value')).toHaveText('5')
    await expect(metricCard(page, 'Números vendidos').locator('.metric-value')).toHaveText('12')
    await expect(metricCard(page, 'Incidencias pendientes').locator('.metric-value')).toHaveText('2')
    await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible()
  })

  test('muestra carga mientras espera los servicios', async ({ page }) => {
    await mockApi(page, {
      admin: true,
      metrics: {
        raffles: () => ({ body: { data: dashboardMetrics.raffles } }),
        orders: async () => {
          await new Promise((resolve) => setTimeout(resolve, 700))

          return { body: { data: dashboardMetrics.orders } }
        },
        payments: () => ({ body: { data: dashboardMetrics.payments } }),
      },
    })

    await page.goto('/admin/dashboard')

    await expect(metricCard(page, 'Órdenes pagadas').getByText('Cargando')).toBeVisible()
    await expect(metricCard(page, 'Órdenes pagadas').locator('.metric-value')).toHaveText('5')
  })

  test('un servicio caído se muestra como no disponible y se puede reintentar', async ({
    page,
  }) => {
    let ordersDown = true

    await mockApi(page, {
      admin: true,
      metrics: {
        raffles: () => ({ body: { data: dashboardMetrics.raffles } }),
        orders: () =>
          ordersDown
            ? { status: 503, body: { message: 'Orders temporarily unavailable.' } }
            : { body: { data: dashboardMetrics.orders } },
        payments: () => ({ body: { data: dashboardMetrics.payments } }),
      },
    })

    await page.goto('/admin/dashboard')

    await expect(
      page.locator('.alert-title', { hasText: 'Algunos indicadores no están disponibles' }),
    ).toBeVisible()
    const ordersCard = metricCard(page, 'Órdenes pagadas')
    await expect(ordersCard.locator('.metric-value')).toHaveText('No disponible')
    await expect(ordersCard).not.toContainText('0')
    await expect(metricCard(page, 'Rifas publicadas').locator('.metric-value')).toHaveText('3')

    ordersDown = false
    await page.getByRole('button', { name: 'Actualizar' }).click()

    await expect(ordersCard.locator('.metric-value')).toHaveText('5')
    await expect(
      page.locator('.alert-title', { hasText: 'Algunos indicadores no están disponibles' }),
    ).toHaveCount(0)
  })

  test('si todos los servicios fallan ofrece reintento', async ({ page }) => {
    let down = true
    const fail = () => ({ status: 503, body: { message: 'Service unavailable.' } })

    await mockApi(page, {
      admin: true,
      metrics: {
        raffles: () => (down ? fail() : { body: { data: dashboardMetrics.raffles } }),
        orders: () => (down ? fail() : { body: { data: dashboardMetrics.orders } }),
        payments: () => (down ? fail() : { body: { data: dashboardMetrics.payments } }),
      },
    })

    await page.goto('/admin/dashboard')

    await expect(
      page.locator('.alert-title', { hasText: 'No pudimos cargar el dashboard' }),
    ).toBeVisible()

    down = false
    await page.getByRole('button', { name: 'Reintentar' }).click()

    await expect(metricCard(page, 'Rifas publicadas').locator('.metric-value')).toHaveText('3')
    await expect(metricCard(page, 'Órdenes pagadas').locator('.metric-value')).toHaveText('5')
  })

  test('un cero confirmado se muestra como cero', async ({ page }) => {
    await mockApi(page, {
      admin: true,
      metrics: {
        raffles: () => ({ body: { data: { published_raffles: 0 } } }),
        orders: () => ({ body: { data: { paid_orders: 0, sold_numbers: 0 } } }),
        payments: () => ({ body: { data: { pending_incidents: 0 } } }),
      },
    })

    await page.goto('/admin/dashboard')

    await expect(page.getByText('Todavía no hay actividad')).toBeVisible()
    await expect(metricCard(page, 'Rifas publicadas').locator('.metric-value')).toHaveText('0')
    await expect(metricCard(page, 'Órdenes pagadas').locator('.metric-value')).toHaveText('0')
  })

  test('un usuario normal no accede al dashboard', async ({ page }) => {
    await mockApi(page)

    await page.goto('/admin/dashboard')

    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: 'Rifas disponibles' })).toBeVisible()
  })

  test.describe('móvil', () => {
    test.use({ viewport: { width: 390, height: 844 } })

    test('las métricas y la navegación caben sin desbordar', async ({ page }) => {
      await mockApi(page, {
        admin: true,
        metrics: {
          raffles: () => ({ body: { data: dashboardMetrics.raffles } }),
          orders: () => ({ body: { data: dashboardMetrics.orders } }),
          payments: () => ({ body: { data: dashboardMetrics.payments } }),
        },
      })

      await page.goto('/admin/dashboard')

      await expect(page.locator('.metric-card')).toHaveCount(4)
      const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))
      expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)

      await page.getByRole('button', { name: 'Abrir menú de navegación' }).click()
      await expect(page.getByRole('link', { name: 'Dashboard' })).toBeVisible()
    })
  })
})
