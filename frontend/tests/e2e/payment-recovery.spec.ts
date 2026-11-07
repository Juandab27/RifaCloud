import { expect, test } from '@playwright/test'
import { mockApi } from './api-fixtures'

test.describe('Pago rechazado: recuperación', () => {
  test('explica el rechazo y permite volver a la rifa', async ({ page }) => {
    await mockApi(page)
    await page.goto('/orders/7')

    await expect(page.getByRole('heading', { name: 'El pago no se completó' })).toBeVisible()
    await expect(
      page.getByText('El proveedor rechazó el pago y los números volvieron a estar disponibles.'),
    ).toBeVisible()

    const retry = page.getByRole('link', { name: 'Elegir números de nuevo' })
    await expect(retry).toHaveAttribute('href', '/raffles/2')
    await expect(page.getByRole('link', { name: 'Ver otras rifas' })).toHaveAttribute(
      'href',
      '/',
    )

    await retry.click()
    await expect(page).toHaveURL(/\/raffles\/2$/)
    await expect(page.locator('.raffle-hero .status')).toHaveText('Disponible')
  })

  test('una reserva vencida también ofrece una nueva oportunidad', async ({ page }) => {
    await mockApi(page)
    await page.goto('/orders/8')

    await expect(page.getByRole('heading', { name: 'La reserva venció' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Elegir números de nuevo' })).toHaveAttribute(
      'href',
      '/raffles/2',
    )
  })
})
