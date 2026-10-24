export type Role = 'USER' | 'ADMIN'

export interface User {
  id: number
  name: string
  email: string
  phone: string
  role: Role
}

export type RaffleStatus = 'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'FINISHED'

export interface Raffle {
  id: number
  title: string
  description: string | null
  price_per_number: number
  min_number: number
  max_number: number
  total_numbers: number
  status: RaffleStatus
  starts_at: string | null
  ends_at: string | null
  numbers_count?: number
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
