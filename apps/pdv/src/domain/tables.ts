import { getDB } from '../core/db'
import { apiFetch, type ApiConfig } from '../core/api'

type TableStatus = 'free' | 'occupied'

function nowIso() { return new Date().toISOString() }

function normalizeStatus(s: any): TableStatus {
  const v = String(s ?? '').toLowerCase().trim()
  if (v === 'free' || v === 'livre') return 'free'
  if (v === 'occupied' || v === 'ocupada' || v === 'ocupado') return 'occupied'
  // fallback conservador: se não souber, trate como occupied (evita abrir mesa indevida)
  return 'occupied'
}

function buildLabel(t: any): string {
  // tenta campos comuns
  if (t?.name) return String(t.name)
  if (t?.label) return String(t.label)
  if (t?.number != null) return `Mesa ${t.number}`
  if (t?.code) return `Mesa ${t.code}`
  if (t?.id != null) return `Mesa #${t.id}`
  return 'Mesa'
}

export async function syncTables(cfg: ApiConfig): Promise<{ ok: boolean; count?: number; error?: string }> {
  try {
    const list = await apiFetch<any[]>(cfg, '/tables', { method: 'GET' })
    const tables = Array.isArray(list) ? list : []

    const db = await getDB()
    const tx = db.transaction('tables', 'readwrite')

    for (const t of tables) {
      // precisa ter id
      const id = Number(t?.id)
      if (!Number.isFinite(id)) continue

      await tx.store.put({
        id,
        label: buildLabel(t),
        status: normalizeStatus(t?.status),
        updated_at: nowIso(),
        raw: t,
      })
    }

    await tx.done

    return { ok: true, count: tables.length }
  } catch (e: any) {
    return { ok: false, error: e?.message ?? 'Falha ao sincronizar mesas' }
  }
}

export async function listTablesLocal(): Promise<Array<{ id: number; label: string; status: TableStatus }>> {
  const db = await getDB()
  const all = await db.getAll('tables')
  return all
    .map(t => ({ id: t.id, label: t.label, status: t.status }))
    .sort((a, b) => a.label.localeCompare(b.label, 'pt-BR'))
}
