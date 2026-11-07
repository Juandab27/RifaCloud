import { expect, test } from '@playwright/test'
import { mockApi } from './api-fixtures'

test.describe('Catálogo: estados temporales', () => {
  test('muestra próximamente, disponible, cerrada y finalizada', async ({ page }) => {
    await mockApi(page)
    await page.goto('/')

    const upcoming = page.locator('.raffle-card', { hasText: 'Rifa próxima' })
    await expect(upcoming.locator('.status')).toHaveText('Próximamente')
    await expect(upcoming).toContainText('Comienza el')
    await expect(upcoming.locator('.raffle-card-cta')).toHaveText('Ver detalles')

    const open = page.locator('.raffle-card', { hasText: 'Rifa disponible' })
    await expect(open.locator('.status')).toHaveText('Disponible')
    await expect(open).toContainText('Cierra el')
    await expect(open.locator('.raffle-card-cta')).toHaveText('Ver números')

    const closed = page.locator('.raffle-card', { hasText: 'Rifa cerrada' })
    await expect(closed.locator('.status')).toHaveText('Cerrada')
    await expect(closed).toContainText('Venta cerrada el')
    await expect(closed.locator('.raffle-card-cta')).toHaveText('Ver detalles')

    const finished = page.locator('.raffle-card', { hasText: 'Rifa finalizada' })
    await expect(finished.locator('.status')).toHaveText('Finalizada')
    await expect(finished.locator('.raffle-card-result')).toContainText('Ganador: N.º 1.234')
    await expect(finished.locator('.raffle-card-cta')).toHaveText('Ver resultado')
  })
})
