import type { Page, Route } from '@playwright/test'

/**
 * Fixtures de API para las pruebas de navegador.
 *
 * Todo se intercepta en el navegador: no se consulta MySQL, no se crea una
 * orden real y el gateway de pago permanece simulado. Los datos son aislados
 * de los de desarrollo y producción.
 */

const now = Date.now()
const iso = (offsetMs: number): string => new Date(now + offsetMs).toISOString()

const HOUR = 60 * 60 * 1000
const DAY = 24 * HOUR

export const user = {
  id: 1,
  name: 'Comprador de prueba',
  email: 'buyer@example.invalid',
  phone: '0000000000',
  role: 'USER',
}

export const adminUser = {
  ...user,
  id: 2,
  name: 'Admin de prueba',
  email: 'admin@example.invalid',
  role: 'ADMIN',
}

const baseRaffle = {
  description: 'Rifa de prueba para las comprobaciones de interfaz.',
  price_per_number: 1000,
  min_number: 1,
  max_number: 2000,
  total_numbers: 2000,
  reservation_minutes: 10,
  max_numbers_per_order: 50,
  numbers_count: 2000,
  result: null,
  created_at: iso(-10 * DAY),
  updated_at: iso(-1 * DAY),
}

export const upcomingRaffle = {
  ...baseRaffle,
  id: 1,
  title: 'Rifa próxima',
  status: 'PUBLISHED',
  starts_at: iso(2 * DAY),
  ends_at: iso(5 * DAY),
}

export const openRaffle = {
  ...baseRaffle,
  id: 2,
  title: 'Rifa disponible',
  status: 'PUBLISHED',
  starts_at: iso(-1 * DAY),
  ends_at: iso(2 * DAY),
}

export const closedRaffle = {
  ...baseRaffle,
  id: 3,
  title: 'Rifa cerrada',
  status: 'PUBLISHED',
  starts_at: iso(-5 * DAY),
  ends_at: iso(-1 * DAY),
}

export const finishedRaffle = {
  ...baseRaffle,
  id: 4,
  title: 'Rifa finalizada',
  status: 'FINISHED',
  starts_at: iso(-20 * DAY),
  ends_at: iso(-10 * DAY),
  result: {
    winning_number: 1234,
    published_at: iso(-1 * DAY),
    evidence_description: 'Acta del sorteo verificada por la administración.',
    evidence_url: 'https://example.invalid/acta-sorteo',
  },
}

export const raffles = [upcomingRaffle, openRaffle, closedRaffle, finishedRaffle]

const numbersPayload = {
  data: [
    { id: 11, number: 1, status: 'AVAILABLE' },
    { id: 12, number: 2, status: 'RESERVED' },
    { id: 13, number: 3, status: 'SOLD' },
    { id: 14, number: 4, status: 'AVAILABLE' },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 100,
    total: 4,
    counts: { available: 2, reserved: 1, sold: 1 },
  },
}

export const rejectedOrder = {
  id: 7,
  order_number: 'RC-0007',
  status: 'REJECTED',
  total_amount: 2000,
  expires_at: null,
  paid_at: null,
  created_at: iso(-1 * HOUR),
  simulated_payments: true,
  raffle: { id: 2, title: 'Rifa disponible' },
  numbers: [
    { id: 21, number: 10, status: 'AVAILABLE', price_at_purchase: 1000 },
    { id: 22, number: 11, status: 'AVAILABLE', price_at_purchase: 1000 },
  ],
  payments: [
    {
      id: 70,
      provider: 'MERCADOPAGO',
      status: 'REJECTED',
      amount: 2000,
      currency: 'COP',
      checkout_url: null,
      simulated: true,
      verified_at: iso(-30 * 60 * 1000),
      created_at: iso(-1 * HOUR),
    },
  ],
}

export const expiredOrder = {
  ...rejectedOrder,
  id: 8,
  order_number: 'RC-0008',
  status: 'EXPIRED',
  total_amount: 1000,
  numbers: [
    { id: 23, number: 20, status: 'AVAILABLE', price_at_purchase: 1000 },
  ],
  payments: [],
}

export const catalogPayload = {
  data: raffles,
  meta: { current_page: 1, last_page: 1, per_page: 15, total: raffles.length },
}

export const ordersListPayload = {
  data: [rejectedOrder],
  meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
}

export const dashboardMetrics = {
  raffles: { published_raffles: 3 },
  orders: { paid_orders: 5, sold_numbers: 12 },
  payments: { pending_incidents: 2 },
}

export interface DashboardMetricReply {
  status?: number
  body: unknown
}

export type DashboardMetricHandler = () => DashboardMetricReply | Promise<DashboardMetricReply>

export interface MockApiOptions {
  admin?: boolean
  metrics?: {
    raffles?: DashboardMetricHandler
    orders?: DashboardMetricHandler
    payments?: DashboardMetricHandler
  }
}

const detailById: Record<string, unknown> = {
  '1': upcomingRaffle,
  '2': openRaffle,
  '3': closedRaffle,
  '4': finishedRaffle,
}

const orderById: Record<string, unknown> = {
  '7': rejectedOrder,
  '8': expiredOrder,
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  })
}

/**
 * Instala el mock antes de navegar. Cualquier ruta /api no contemplada
 * responde 404 para que una llamada inesperada falle de forma visible.
 */
export async function mockApi(
  page: Page,
  options: MockApiOptions = {},
): Promise<void> {
  const sessionUser = options.admin ? adminUser : user

  await page.route(
    (url) => url.pathname.startsWith('/sanctum/'),
    (route) => route.fulfill({ status: 204, body: '' }),
  )

  await page.route(
    // Solo el espacio de la API: evita interceptar módulos de Vite como
    // /src/api/client.ts, que también contienen "/api/" en la ruta.
    (url) => url.pathname === '/api' || url.pathname.startsWith('/api/'),
    async (route) => {
      const url = new URL(route.request().url())
      const path = url.pathname

      const metricHandlers: Record<string, DashboardMetricHandler | undefined> = {
        '/api/admin/raffles/metrics': options.metrics?.raffles,
        '/api/admin/orders/metrics': options.metrics?.orders,
        '/api/admin/payments/incidents/metrics': options.metrics?.payments,
      }
      const metricHandler = metricHandlers[path]

      if (metricHandler) {
        const reply = await metricHandler()

        return json(route, reply.body, reply.status ?? 200)
      }

      if (path === '/api/me') {
        return json(route, { data: sessionUser })
      }

      if (path === '/api/raffles' || path === '/api/admin/raffles') {
        return json(route, catalogPayload)
      }

      if (path === '/api/orders') {
        return json(route, ordersListPayload)
      }

      const detailMatch = path.match(/^\/api\/raffles\/(\d+)$/)

      if (detailMatch && detailById[detailMatch[1]]) {
        return json(route, { data: detailById[detailMatch[1]] })
      }

      const numbersMatch = path.match(/^\/api\/raffles\/(\d+)\/numbers$/)

      if (numbersMatch && detailById[numbersMatch[1]]) {
        return json(route, numbersPayload)
      }

      const orderMatch = path.match(/^\/api\/orders\/(\d+)$/)

      if (orderMatch && orderById[orderMatch[1]]) {
        return json(route, { data: orderById[orderMatch[1]] })
      }

      return json(route, { message: 'Not mocked' }, 404)
    },
  )
}
