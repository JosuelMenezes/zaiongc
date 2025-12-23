import { getDB } from './db'
import { apiFetch, type ApiConfig } from './api'
import type { OutboxCommand } from './schema'

type SyncResult = {
  processed: boolean
  error?: string
  rejected?: boolean
  last_cmd_id?: string
  last_cmd_type?: string
}

function nowIso(): string {
  return new Date().toISOString()
}

function addMs(date: Date, ms: number): string {
  return new Date(date.getTime() + ms).toISOString()
}

function isBeforeNow(iso: string | null | undefined): boolean {
  if (!iso) return true
  return new Date(iso).getTime() <= Date.now()
}

/**
 * Backoff exponencial simples: 1s, 2s, 4s, 8s, ... até 60s
 */
function computeBackoffMs(attempts: number): number {
  const base = Math.min(60_000, 1000 * Math.pow(2, Math.max(0, attempts - 1)))
  // jitter leve (0-300ms)
  const jitter = Math.floor(Math.random() * 300)
  return base + jitter
}

async function getMeta<T>(key: string): Promise<T | null> {
  const db = await getDB()
  const row = await db.get('meta', key)
  return (row?.value ?? null) as T | null
}

async function setMeta(key: string, value: any): Promise<void> {
  const db = await getDB()
  await db.put('meta', { key, value }, key)
}

/**
 * Dependências suportadas:
 * - meta:<key>              ex: meta:terminal_id
 * - order:<client_uid>:server_id
 */
async function dependencySatisfied(dep: string): Promise<boolean> {
  const db = await getDB()

  if (dep.startsWith('meta:')) {
    const metaKey = dep.slice('meta:'.length).trim()
    if (!metaKey) return false
    const val = await getMeta<any>(metaKey)
    return val !== null && val !== undefined
  }

  if (dep.startsWith('order:')) {
    const parts = dep.split(':')
    if (parts.length !== 3) return false

    const clientUid = parts[1]
    const field = parts[2]

    if (!clientUid || !field) return false

    // MVP: só suportamos server_id como dependência estável
    if (field !== 'server_id') return false

    const order = await db.get('orders', clientUid)
    if (!order) return false
    return order.server_id !== null && order.server_id !== undefined
  }

  return false
}

async function isEligible(cmd: OutboxCommand): Promise<boolean> {
  const deps = cmd.depends_on ?? []
  if (deps.length === 0) return true

  for (const dep of deps) {
    const ok = await dependencySatisfied(dep)
    if (!ok) return false
  }
  return true
}

function normalizeHttpStatus(e: any): number | null {
  // apiFetch pode lançar Error custom com status
  if (typeof e?.status === 'number') return e.status
  if (typeof e?.httpStatus === 'number') return e.httpStatus
  if (typeof e?.response?.status === 'number') return e.response.status
  return null
}

function normalizeErrorMessage(e: any): string {
  return e?.message ? String(e.message) : 'Erro desconhecido'
}

async function markSending(cmdId: string): Promise<void> {
  const db = await getDB()
  const cmd = await db.get('outbox', cmdId)
  if (!cmd) return
  cmd.status = 'sending'
  cmd.updated_at = nowIso()
  await db.put('outbox', cmd)
}

async function markSent(cmdId: string): Promise<void> {
  const db = await getDB()
  const cmd = await db.get('outbox', cmdId)
  if (!cmd) return
  cmd.status = 'sent'
  cmd.last_error = null
  cmd.updated_at = nowIso()
  cmd.sent_at = nowIso()
  await db.put('outbox', cmd)
}

async function markFailed(cmdId: string, err: string): Promise<void> {
  const db = await getDB()
  const cmd = await db.get('outbox', cmdId)
  if (!cmd) return

  cmd.attempts = (cmd.attempts ?? 0) + 1
  cmd.last_error = err
  cmd.status = 'failed'
  cmd.updated_at = nowIso()

  const backoff = computeBackoffMs(cmd.attempts)
  cmd.next_retry_at = addMs(new Date(), backoff)

  // volta para pending (para reprocessar automaticamente)
  cmd.status = 'pending'

  await db.put('outbox', cmd)
}

async function markRejected(cmdId: string, err: string): Promise<void> {
  const db = await getDB()
  const cmd = await db.get('outbox', cmdId)
  if (!cmd) return
  cmd.status = 'rejected'
  cmd.last_error = err
  cmd.updated_at = nowIso()
  await db.put('outbox', cmd)
}

/**
 * Tenta buscar o shift atual (por terminal) para alimentar meta.shift_id.
 * Isso evita ficar preso em depends_on meta:shift_id quando já existe shift no server.
 */
async function ensureShiftForTerminal(cfg: ApiConfig, terminalId: number | null): Promise<void> {
  if (!terminalId) return

  try {
    const shift = await apiFetch<any>(cfg, `/shifts/current?terminal_id=${terminalId}`, { method: 'GET' })
    if (shift?.id) {
      await setMeta('shift_id', shift.id)
    }
  } catch {
    // best effort
  }
}

/**
 * Processa UM comando.
 * Se sucesso -> marca sent.
 * Se erro 409/422/404 -> marca rejected (conflito/regra de negócio / não encontrado).
 * Outros erros -> backoff e retry (pending).
 */
async function processCommand(cfg: ApiConfig, cmd: OutboxCommand): Promise<void> {
  const db = await getDB()

  switch (cmd.type) {
    case 'shift.open': {
      const { terminal_id, opening_cash } = cmd.payload ?? {}
      await apiFetch(cfg, `/shifts/open`, {
        method: 'POST',
        body: JSON.stringify({ terminal_id, opening_cash }),
      })

      // após abrir, tenta setar shift_id atual
      await ensureShiftForTerminal(cfg, Number(terminal_id) || null)
      return
    }

    case 'order.open': {
      // payload esperado: { type, terminal_id, table_id? }
      const payload = cmd.payload ?? {}
      const created = await apiFetch<any>(cfg, `/orders/open`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })

      // Quando o servidor cria/retorna pedido, gravar server_id no order local
      // cmd.meta pode carregar { order_client_uid }
      const orderClientUid = cmd.meta?.order_client_uid as string | undefined
      if (orderClientUid && created?.id) {
        const o = await db.get('orders', orderClientUid)
        if (o) {
          o.server_id = created.id
          o.updated_at = nowIso()
          await db.put('orders', o)
        }
      }
      return
    }

    case 'order.item.add': {
      // payload esperado: { order_client_uid, name, quantity, unit_price, notes? }
      const payload = cmd.payload ?? {}
      const orderClientUid = String(payload.order_client_uid ?? '')
      if (!orderClientUid) throw new Error('order_client_uid ausente no payload')

      const order = await db.get('orders', orderClientUid)
      if (!order?.server_id) throw new Error('Order sem server_id (dependência não satisfeita).')

      const createdItem = await apiFetch<any>(cfg, `/orders/${order.server_id}/items`, {
        method: 'POST',
        body: JSON.stringify({
          name: payload.name,
          quantity: payload.quantity,
          unit_price: payload.unit_price,
          notes: payload.notes ?? null,
        }),
      })

      // Se você salvou o item local no cmd.meta.item_client_uid, podemos vincular server_id no item local
      const itemClientUid = cmd.meta?.item_client_uid as string | undefined
      if (itemClientUid && createdItem?.id) {
        const it = await db.get('order_items', itemClientUid)
        if (it) {
          it.server_id = createdItem.id
          it.updated_at = nowIso()
          await db.put('order_items', it)
        }
      }

      return
    }

    case 'payment.add': {
      // payload esperado: { order_client_uid, method, amount }
      const payload = cmd.payload ?? {}
      const orderClientUid = String(payload.order_client_uid ?? '')
      if (!orderClientUid) throw new Error('order_client_uid ausente no payload')

      const order = await db.get('orders', orderClientUid)
      if (!order?.server_id) throw new Error('Order sem server_id (dependência não satisfeita).')

      const createdPayment = await apiFetch<any>(cfg, `/orders/${order.server_id}/payments`, {
        method: 'POST',
        body: JSON.stringify({
          method: payload.method,
          amount: payload.amount,
        }),
      })

      const payClientUid = cmd.meta?.payment_client_uid as string | undefined
      if (payClientUid && createdPayment?.[0]?.id) {
        // dependendo do seu backend, pode retornar array; se retornar objeto, ajuste aqui.
      }

      return
    }

    /**
     * ✅ O QUE VOCÊ PEDIU: cancelamento de item
     * Payload esperado:
     * { order_server_id, item_server_id, reason }
     */
    case 'order.item.cancel': {
      const payload = cmd.payload ?? {}
      const orderServerId = Number(payload.order_server_id)
      const itemServerId = Number(payload.item_server_id)
      const reason = String(payload.reason ?? '').trim()

      if (!Number.isFinite(orderServerId) || orderServerId <= 0) {
        throw new Error('order_server_id inválido')
      }
      if (!Number.isFinite(itemServerId) || itemServerId <= 0) {
        throw new Error('item_server_id inválido')
      }
      if (!reason) {
        throw new Error('reason obrigatório para cancelamento')
      }

      await apiFetch(cfg, `/orders/${orderServerId}/items/${itemServerId}/cancel`, {
        method: 'POST',
        body: JSON.stringify({ reason }),
      })

      return
    }

    default:
      throw new Error(`Tipo de comando não suportado: ${cmd.type}`)
  }
}

/**
 * Processa no máximo 1 comando por tick (mais previsível para UI).
 * Você pode aumentar para processar N por ciclo se quiser.
 */
export async function syncOnce(cfg: ApiConfig): Promise<SyncResult> {
  const db = await getDB()

  // Primeiro, tentamos manter shift_id atual do terminal (best effort).
  const terminalId = await getMeta<number>('terminal_id')
  await ensureShiftForTerminal(cfg, terminalId ?? null)

  // Buscar pendentes
  const pending = await db.getAllFromIndex('outbox', 'by_status', 'pending')
  if (!pending.length) return { processed: false }

  // Ordena por next_retry_at e id (determinístico)
  pending.sort((a, b) => {
    const ta = a.next_retry_at ? new Date(a.next_retry_at).getTime() : 0
    const tb = b.next_retry_at ? new Date(b.next_retry_at).getTime() : 0
    if (ta !== tb) return ta - tb
    return String(a.id).localeCompare(String(b.id))
  })

  for (const cmd of pending) {
    // Respeitar retry_at
    if (!isBeforeNow(cmd.next_retry_at)) continue

    // Dependências
    const ok = await isEligible(cmd)
    if (!ok) continue

    // Processar 1
    await markSending(cmd.id)

    try {
      await processCommand(cfg, cmd)
      await markSent(cmd.id)

      return {
        processed: true,
        last_cmd_id: cmd.id,
        last_cmd_type: cmd.type,
      }
    } catch (e: any) {
      const status = normalizeHttpStatus(e)
      const msg = normalizeErrorMessage(e)

      // Conflito/regra de negócio: não adianta retry automático
      if (status === 409 || status === 422 || status === 404) {
        await markRejected(cmd.id, msg)
        return {
          processed: true,
          rejected: true,
          error: msg,
          last_cmd_id: cmd.id,
          last_cmd_type: cmd.type,
        }
      }

      // Erro transitório (rede/500/etc): retry com backoff
      await markFailed(cmd.id, msg)

      return {
        processed: true,
        error: msg,
        last_cmd_id: cmd.id,
        last_cmd_type: cmd.type,
      }
    }
  }

  return { processed: false }
}
