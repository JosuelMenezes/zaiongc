// src/core/schema.ts

export type SyncStatus = 'pending' | 'sending' | 'sent' | 'failed' | 'rejected'

export type OutboxCommandType =
  | 'shift.open'
  | 'order.open'
  | 'order.item.add'
  | 'payment.add'
  | 'order.item.cancel'

export type OutboxCommand = {
  id: string
  type: OutboxCommandType
  status: SyncStatus
  payload: any
  depends_on?: string[]
  attempts: number
  last_error: string | null
  next_retry_at: string | null
  created_at: string
  updated_at: string
  sent_at?: string | null
  meta?: Record<string, any>
}

// Tipos locais (mínimos) — ajuste conforme seus arquivos reais se já existirem
export type MetaRecord = any

export type OrderLocal = {
  client_uid: string
  server_id?: number | null
  type: 'table' | 'counter' | 'delivery'
  table_id?: number | null
  terminal_id?: number | null
  shift_id?: number | null
  status_local: 'draft' | 'queued' | 'synced'
  subtotal: number
  total: number
  discount?: number
  service_fee?: number
  created_at: string
  updated_at: string
}

export type OrderItemLocal = {
  client_uid: string
  order_client_uid: string
  server_id?: number | null
  name: string
  quantity: number
  unit_price: number
  total: number
  status: 'pending' | 'done' | 'canceled'
  notes?: string | null
  cancel_reason?: string | null
  canceled_at?: string | null
  created_at: string
  updated_at?: string
}

export type PaymentLocal = {
  client_uid: string
  order_client_uid: string
  server_id?: number | null
  method: 'cash' | 'pix' | 'card' | 'voucher'
  amount: number
  status: 'pending' | 'confirmed' | 'canceled'
  created_at: string
  updated_at?: string
}
