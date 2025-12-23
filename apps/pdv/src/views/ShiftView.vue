<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMetaStore } from '../stores/meta'
import { apiFetch } from '../core/api'
import { enqueue } from '../core/outbox'
import { useRouter } from 'vue-router'
import { getDB } from '../core/db'
import { createLocalOrder } from '../domain/orders'
import TablePicker from './TablePicker.vue'
import { ulid } from 'ulid'

const auth = useAuthStore()
const meta = useMetaStore()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const openingCash = ref<number>(0)

const shiftId = computed(() => meta.shiftId)
const terminalId = computed(() => meta.terminalId)

const tableId = ref<number | null>(null)

async function refreshShift() {
  if (!terminalId.value) return

  loading.value = true
  error.value = ''
  try {
    const cfg = { baseUrl: auth.baseUrl, token: auth.token }
    const shift = await apiFetch<any>(
      cfg,
      `/shifts/current?terminal_id=${terminalId.value}`,
      { method: 'GET' }
    )
    await meta.setShiftId(shift?.id ?? null)
  } catch (e: any) {
    // Se estiver offline ou API indisponível, não bloqueia
    error.value = e.message ?? 'Falha ao consultar shift atual'
  } finally {
    loading.value = false
  }
}

async function openShift() {
  if (!terminalId.value) return

  // Upgrade #1: persistir opening_cash_last no meta
  try {
    const db = await getDB()
    await db.put('meta', { key: 'opening_cash_last', value: openingCash.value }, 'opening_cash_last')
  } catch {
    // best effort, não bloqueia
  }

  // Abrir caixa via outbox (idempotente no backend)
  await enqueue('shift.open', { terminal_id: terminalId.value, opening_cash: openingCash.value }, [])

  // tenta atualizar logo em seguida (se online)
  await refreshShift()
}

async function newCounterOrder() {
  if (!terminalId.value) return

  // Regra: counter precisa de shift open. Se não tiver, o comando ficará pendente.
  const uid = await createLocalOrder({ type: 'counter', terminal_id: terminalId.value })
  router.push(`/order/${uid}`)
}

// Upgrade #2: bloquear abrir pedido em mesa ocupada (lendo cache local)
async function assertTableIsFree(selectedTableId: number): Promise<boolean> {
  try {
    const db = await getDB()
    const t = await db.get('tables', selectedTableId)
    if (!t) return true // se não tem cache, não bloqueia (best effort)
    if (t.status === 'free') return true
    return false
  } catch {
    return true // se der qualquer erro, não bloqueia
  }
}

async function newTableOrder() {
  if (!terminalId.value) return

  if (!tableId.value) {
    alert('Selecione uma mesa.')
    return
  }

  const isFree = await assertTableIsFree(tableId.value)
  if (!isFree) {
    alert('Mesa está ocupada. Se já existe pedido aberto, o sistema deve retorná-lo ao sincronizar.')
    return
  }

  const uid = await createLocalOrder({
    type: 'table',
    terminal_id: terminalId.value,
    table_id: tableId.value,
  })

  router.push(`/order/${uid}`)
}

async function openExistingOrderFromTable(tableServerId: number) {
  if (!terminalId.value) return

  try {
    const cfg = { baseUrl: auth.baseUrl, token: auth.token }

    // 1) Descobrir o pedido atual da mesa
    const res = await apiFetch<any>(cfg, `/tables/${tableServerId}/current-order`, { method: 'GET' })
    const order = res?.order

    if (!order?.id) {
      alert('Não encontrei comanda em aberto para esta mesa.')
      return
    }

    const db = await getDB()

    // 2) Ver se já existe um Order local espelho para esse server_id
    const existing = await db.getFromIndex('orders', 'by_server_id', order.id)
    if (existing?.client_uid) {
      // opcional: hidratar antes de abrir
      await hydrateOrderFromServer(existing.client_uid, order.id)
      router.push(`/order/${existing.client_uid}`)
      return
    }

    // 3) Criar Order local espelho (server_id preenchido)
    const orderClientUid = ulid()

    await db.put('orders', {
      client_uid: orderClientUid,
      server_id: order.id,
      type: order.type ?? 'table',
      table_id: order.table_id ?? tableServerId,
      terminal_id: order.terminal_id ?? terminalId.value,
      shift_id: order.shift_id ?? null,
      status_local: 'synced',
      subtotal: 0,
      total: 0,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    })

    // 4) Hidratar itens/pagamentos do servidor no IndexedDB (para a OrderView)
    await hydrateOrderFromServer(orderClientUid, order.id)

    // 5) Abrir a tela do pedido
    router.push(`/order/${orderClientUid}`)
  } catch (e: any) {
    alert(e?.message ?? 'Falha ao abrir comanda existente.')
  }
}

async function hydrateOrderFromServer(orderClientUid: string, orderServerId: number) {
  const cfg = { baseUrl: auth.baseUrl, token: auth.token }
  const full = await apiFetch<any>(cfg, `/orders/${orderServerId}`, { method: 'GET' })

  const db = await getDB()
  const tx = db.transaction(['orders', 'order_items', 'payments'], 'readwrite')

  // Atualiza totals do order local
  const localOrder = await tx.objectStore('orders').get(orderClientUid)
  if (localOrder) {
    localOrder.subtotal = Number(full?.subtotal ?? localOrder.subtotal ?? 0)
    localOrder.total = Number(full?.total ?? localOrder.total ?? 0)
    localOrder.updated_at = new Date().toISOString()
    await tx.objectStore('orders').put(localOrder)
  }

  // Itens
  const items = Array.isArray(full?.items) ? full.items : []
  for (const it of items) {
    if (!it?.id) continue

    // chave estável para evitar duplicar ao abrir a comanda várias vezes
    const client_uid = `srv_item_${it.id}`

    await tx.objectStore('order_items').put({
      client_uid,
      order_client_uid: orderClientUid,
      server_id: it.id,
      name: it.name,
      quantity: Number(it.quantity ?? 0),
      unit_price: Number(it.unit_price ?? 0),
      notes: it.notes ?? null,
      status: it.status ?? 'pending',
      total: Number(it.total ?? 0),
      created_at: (it.created_at ?? new Date().toISOString()),
    })
  }

  // Pagamentos
  const pays = Array.isArray(full?.payments) ? full.payments : []
  for (const p of pays) {
    if (!p?.id) continue

    const client_uid = `srv_pay_${p.id}`

    await tx.objectStore('payments').put({
      client_uid,
      order_client_uid: orderClientUid,
      server_id: p.id,
      method: p.method,
      amount: Number(p.amount ?? 0),
      status: p.status ?? 'confirmed',
      created_at: (p.created_at ?? new Date().toISOString()),
    })
  }

  await tx.done
}


onMounted(async () => {
  await auth.load()
  await meta.load()

  if (!auth.token) {
    router.replace('/login')
    return
  }
  if (!meta.terminalId) {
    router.replace('/setup-terminal')
    return
  }

  await refreshShift()

  // Carrega opening_cash salvo
  const db = await getDB()
  const last = await db.get('meta', 'opening_cash_last')
  if (last?.value != null) openingCash.value = last.value
})
</script>

<template>
  <div style="max-width:760px;margin:22px auto;padding:16px;">
    <h2>Caixa / Turno</h2>

    <p style="color:#666;">
      Terminal atual: <strong>{{ terminalId }}</strong>
    </p>

    <div style="margin-top:10px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <div>
          Shift atual:
          <strong>{{ shiftId ?? 'Nenhum shift aberto' }}</strong>
        </div>

        <button @click="refreshShift" :disabled="loading">
          Atualizar
        </button>
      </div>

      <div style="margin-top:12px;">
        <h3>Abrir Caixa</h3>
        <p style="color:#666;margin-top:4px;">
          Se já houver um shift aberto para este terminal, o backend retorna o mesmo (idempotente).
        </p>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;">
          <input
            type="number"
            v-model.number="openingCash"
            min="0"
            step="0.01"
            placeholder="Troco inicial"
            style="padding:10px; width:220px;"
          />
          <button @click="openShift">
            Abrir Caixa
          </button>
        </div>
      </div>

      <p v-if="error" style="color:#c33;margin-top:10px;">{{ error }}</p>
    </div>

    <div style="margin-top:18px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <h3>Novo Pedido</h3>

      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <button @click="newCounterOrder">
          Pedido Balcão
        </button>
      </div>

      <div style="margin-top:14px;">
        <h4>Pedido Mesa</h4>

        <!-- 5.2 aplicado: picker em vez de input -->
       <TablePicker v-model="tableId" @open-occupied="openExistingOrderFromTable" />


        <div style="margin-top:12px;">
          <button @click="newTableOrder" :disabled="!tableId">
            Abrir Pedido da Mesa
          </button>
        </div>

        <p style="color:#666;margin-top:10px;">
          As mesas são carregadas do cache local e atualizadas via <strong>GET /tables</strong>.
          Se estiver offline, o PDV usa o último cache.
        </p>
      </div>
    </div>
  </div>
</template>
