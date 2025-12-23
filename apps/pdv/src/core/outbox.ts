// src/core/outbox.ts

import { ulid } from 'ulid'
import { getDB } from './db'
import type { OutboxCommand, OutboxCommandType } from './schema'

function nowIso() {
  return new Date().toISOString()
}

export async function enqueue(
  type: OutboxCommandType,
  payload: any,
  depends_on: string[] = [],
  meta?: Record<string, any>
) {
  const db = await getDB()

  const cmd: OutboxCommand = {
    id: ulid(),
    type,
    payload,
    depends_on,
    meta,
    status: 'pending',
    attempts: 0,
    next_retry_at: null,
    last_error: null,
    created_at: nowIso(),
    updated_at: nowIso(),
    sent_at: null,
  }

  await db.put('outbox', cmd)
  return cmd.id
}

export async function listPendingEligible(): Promise<OutboxCommand[]> {
  const db = await getDB()
  const pending = await db.getAllFromIndex('outbox', 'by_status', 'pending')
  const now = Date.now()

  return pending.filter(c => {
    if (!c.next_retry_at) return true
    return Date.parse(c.next_retry_at) <= now
  })
}

export async function markSending(id: string) {
  const db = await getDB()
  const cmd = await db.get('outbox', id)
  if (!cmd) return

  cmd.status = 'sending'
  cmd.updated_at = nowIso()
  await db.put('outbox', cmd)
}

export async function markSent(id: string) {
  const db = await getDB()
  const cmd = await db.get('outbox', id)
  if (!cmd) return

  cmd.status = 'sent'
  cmd.sent_at = nowIso()
  cmd.last_error = null
  cmd.updated_at = nowIso()
  await db.put('outbox', cmd)
}

export async function markRejected(id: string, error: string) {
  const db = await getDB()
  const cmd = await db.get('outbox', id)
  if (!cmd) return

  cmd.status = 'rejected'
  cmd.last_error = error
  cmd.updated_at = nowIso()
  await db.put('outbox', cmd)
}

export async function markRetry(id: string, error: string) {
  const db = await getDB()
  const cmd = await db.get('outbox', id)
  if (!cmd) return

  cmd.attempts = (cmd.attempts ?? 0) + 1
  cmd.last_error = error

  // backoff exponencial simples: 1s, 2s, 4s, 8s... max ~60s
  const delaySec = Math.min(60, Math.pow(2, Math.min(10, cmd.attempts)))
  cmd.next_retry_at = new Date(Date.now() + delaySec * 1000).toISOString()

  // mantemos como pending para reprocessar
  cmd.status = 'pending'
  cmd.updated_at = nowIso()

  await db.put('outbox', cmd)
}
