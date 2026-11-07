import { expect, test } from '@playwright/test'
import { mockApi } from './api-fixtures'

test.describe('Detalle de rifa: ventana de venta', () => {
  test('una rifa próxima no ofrece reservar números', async ({ page }) => {
    await mockApi(page)
    await page.goto('/raffles/1')

    await expect(page.locator('.raffle-hero .status')).toHaveText('Próximamente')
    await expect(page.locator('.alert-title', { hasText: 'Venta próximamente' })).toBeVisible()
    await expect(page.getByText('Venta desde el')).toBeVisible()
    await expect(page.getByRole('button', { name: /Reservar números/ })).toHaveCount(0)

    const firstNumber = page.locator('button.number').first()
    await expect(firstNumber).toBeDisabled()

    await expect(page.getByRole('link', { name: 'Ver otras rifas' })).toBeVisible()
  })

  test('una rifa disponible permite seleccionar números', async ({ page }) => {
    await mockApi(page)
    await page.goto('/raffles/2')

    await expect(page.locator('.raffle-hero .status')).toHaveText('Disponible')
    await expect(page.getByRole('button', { name: /Reservar números/ })).toBeVisible()

    const availableNumber = page.getByRole('button', { name: 'Número 1, disponible' })
    await expect(availableNumber).toBeEnabled()
    await availableNumber.click()
    const selectedNumber = page.getByRole('button', { name: 'Número 1, seleccionado' })
    await expect(selectedNumber).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByText('Seleccionados: 1')).toBeVisible()
  })

  test('una rifa fuera de la ventana se muestra cerrada y sin reserva', async ({ page }) => {
    await mockApi(page)
    await page.goto('/raffles/3')

    await expect(page.locator('.raffle-hero .status')).toHaveText('Cerrada')
    await expect(page.locator('.alert-title', { hasText: 'Venta cerrada' })).toBeVisible()
    await expect(page.getByRole('button', { name: /Reservar números/ })).toHaveCount(0)
    await expect(page.locator('button.number').first()).toBeDisabled()
    await expect(page.getByRole('link', { name: 'Ver otras rifas' })).toBeVisible()
  })
})

test.describe('Resultado oficial', () => {
  test('publica ganador, fecha y evidencia sin alterar el resultado', async ({ page }) => {
    await mockApi(page)
    await page.goto('/raffles/4')

    const result = page.locator('.raffle-result')
    await expect(result.getByRole('heading', { name: 'Número ganador' })).toBeVisible()
    await expect(result.locator('.raffle-result-number')).toHaveText('1.234')
    await expect(result).toContainText('Fecha de publicación')
    await expect(result).toContainText('Acta del sorteo verificada por la administración.')

    const evidence = result.getByRole('link', { name: 'Ver evidencia' })
    await expect(evidence).toHaveAttribute('href', 'https://example.invalid/acta-sorteo')
    await expect(evidence).toHaveAttribute('target', '_blank')
  })
})
