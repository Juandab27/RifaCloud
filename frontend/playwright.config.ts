import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas de navegador con API interceptada.
 *
 * - No requieren MySQL, servicios ni Mercado Pago: cada test registra sus
 *   propias respuestas con `page.route` (datos aislados y gateway simulado).
 * - Usa el Chrome instalado en el equipo (channel: chrome); no descarga
 *   navegadores ni crea recursos externos.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  forbidOnly: true,
  retries: 0,
  timeout: 30_000,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    channel: 'chrome',
    headless: true,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chrome',
      use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    },
  ],
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: true,
    timeout: 120_000,
  },
})
