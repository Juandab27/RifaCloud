import { expect, test, type Page } from '@playwright/test'
import { mockApi } from './api-fixtures'

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => {
    const root = document.documentElement

    return {
      scrollWidth: root.scrollWidth,
      clientWidth: root.clientWidth,
    }
  })

  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
}

test.describe('Experiencia móvil', () => {
  test.use({ viewport: { width: 390, height: 844 } })

  test('el catálogo, el menú y las tarjetas no desbordan en móvil', async ({ page }) => {
    await mockApi(page)
    await page.goto('/')

    await expect(page.getByRole('button', { name: 'Abrir menú de navegación' })).toBeVisible()
    await page.getByRole('button', { name: 'Abrir menú de navegación' }).click()
    await expect(page.getByRole('link', { name: 'Catálogo' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Mis compras' })).toBeVisible()

    await expectNoHorizontalOverflow(page)
    await expect(page.locator('.raffle-card').first()).toBeVisible()
  })

  test('el detalle con resultado se adapta y conserva la evidencia', async ({ page }) => {
    await mockApi(page)
    await page.goto('/raffles/4')

    await expect(page.locator('.raffle-result-number')).toHaveText('1.234')
    await expect(page.getByRole('link', { name: 'Ver evidencia' })).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })

  test('la recuperación de un pago rechazado cabe en móvil', async ({ page }) => {
    await mockApi(page)
    await page.goto('/orders/7')

    const retry = page.getByRole('link', { name: 'Elegir números de nuevo' })
    await expect(retry).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })

  test('Mis compras y el acceso mantienen su identidad en móvil', async ({ page }) => {
    await mockApi(page)

    await page.goto('/orders')
    await expect(page.getByRole('heading', { name: 'Mis compras' })).toBeVisible()
    await expect(page.locator('.order-history-card')).toBeVisible()
    await expectNoHorizontalOverflow(page)

    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'Ingresar' })).toBeVisible()
    await expect(page.getByLabel('Correo electrónico')).toBeVisible()
    await expect(page.getByLabel('Contraseña')).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })

  test('la administración de rifas conserva su tabla en móvil', async ({ page }) => {
    await mockApi(page, { admin: true })
    await page.goto('/admin/raffles')

    await expect(
      page.getByRole('heading', { name: 'Administración de rifas' }),
    ).toBeVisible()
    await expect(page.locator('.admin-table')).toBeVisible()
    await expectNoHorizontalOverflow(page)
  })
})
