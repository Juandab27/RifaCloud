export type Role = 'USER' | 'ADMIN'

export interface User {
  id: number
  name: string
  email: string
  phone: string
  role: Role
}

export type RaffleStatus = 'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'FINISHED'

export interface RaffleResult {
  winning_number: number
  published_at: string | null
  evidence_description: string | null
  evidence_url: string | null
}

export interface Raffle {
  id: number
  title: string
  description: string | null
  price_per_number: number
  min_number: number
  max_number: number
  total_numbers: number
  status: RaffleStatus
  reservation_minutes: number
  max_numbers_per_order: number
  starts_at: string | null
  ends_at: string | null
  numbers_count?: number
  result?: RaffleResult | null
  created_at: string | null
  updated_at: string | null
}

export type NumberStatus = 'AVAILABLE' | 'RESERVED' | 'SOLD'

export interface RaffleNumber {
  id: number
  number: number
  status: NumberStatus
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PaginationMeta
}

export interface NumberAvailabilityResponse {
  data: RaffleNumber[]
  meta: PaginationMeta & {
    counts: {
      available: number
      reserved: number
      sold: number
    }
  }
}

export interface RafflePayload {
  title: string
  description: string | null
  price_per_number: number
  min_number: number
  max_number: number
  starts_at: string | null
  ends_at: string | null
}

export type OrderStatus =
  | 'PENDING_PAYMENT'
  | 'PAID'
  | 'REJECTED'
  | 'EXPIRED'
  | 'PAYMENT_REVIEW'
  | 'REQUIRES_REFUND'

export interface OrderNumber {
  id: number
  number: number
  status: NumberStatus
  price_at_purchase: number
}

export type PaymentState = 'PENDING' | 'APPROVED' | 'REJECTED' | 'UNKNOWN' | 'REFUND_REQUIRED'

export interface Payment {
  id: number
  provider: 'MERCADOPAGO' | 'PAYPAL'
  status: PaymentState
  amount: number
  currency: string
  checkout_url: string | null
  simulated?: boolean
  verified_at: string | null
  created_at: string | null
}

export interface Order {
  id: number
  order_number: string
  status: OrderStatus
  total_amount: number
  expires_at: string | null
  paid_at: string | null
  created_at: string | null
  simulated_payments?: boolean
  raffle?: {
    id: number
    title: string
  }
  numbers?: OrderNumber[]
  payments?: Payment[]
}

export interface AdminOrder extends Order {
  customer: {
    id: number
    name: string
    email: string
  }
  latest_payment_status: PaymentState | null
}

export interface PaymentIncident {
  id: number
  order_id: number
  order_number: string
  status: PaymentState
  amount: number
  currency: string
  created_at: string | null
  verified_at: string | null
  customer: {
    id: number
    name: string
    email: string
  }
  raffle: {
    id: number
    title: string
  }
}
