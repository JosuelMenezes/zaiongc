<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getDB } from '../core/db'
import { addLocalItem } from '../domain/orders'
import { createPendingPayment } from '../domain/payments'
import { useAuthStore } from '../stores/auth'
import { apiFetch } from '../core/api'
import { useMetaStore } from '../stores/meta'
import { cancelLocalItem } from '../domain/cancelItem'


const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const meta = useMetaStore()

const orderClientUid = route.params.orderClientUid as string

const loading = ref(false)
const error = ref('')
const order = ref<any>(null)
const items = ref<any[]>([])
const payments = ref<any[]>([])

const newItemName = ref('')
const newItemQty = ref<number>(1)
const newItemPrice = ref<number>(0)
const newItemNotes = ref<string>('')

const payMethod = ref<'cash'|'pix'|'card'|'voucher'>('pix')
const payAmount = ref<number>(0)

function money(n: number) {
  return (Math.round(n * 100) / 100).toFixed(2)
}

async function loadLocal() {
  const db = await getDB()
  order.value = await db.get('orders', orderClientUid)
  items.value = await db.getAllFromIndex('order_items', 'by_order_client_uid', orderClientUid)
  payments.value = await db.getAllFromIndex('payments', 'by_order_client_uid', orderClientUid)

  // recalcula totals local
  const subtotal = items.value
    .filter((i) => i.status !== 'canceled')
    .reduce((acc, i) => acc + (i.total ?? 0), 0)

  if (order.value) {
    order.value.subtotal = Math.round(subtotal * 100) / 100
    order.value.total = order.value.subtotal // MVP sem taxas/descontos
    order.value.updated_at = new Date().toISOString()
    await db.put('orders', order.value)
  }

  // default do pagamento = total - pago local
  const paidLocal = payments.value.reduce((acc, p) => acc + (p.amount ?? 0), 0)
  payAmount.value = Math.max(0, (order.value?.total ?? 0) - paidLocal)
}

async function addItem() {
  error.value = ''
  if (!newItemName.value || newItemQty.value <= 0 || newItemPrice.value < 0) {
    error.value = 'Informe nome, quantidade e preço válidos.'
    return
  }

  await addLocalItem(orderClientUid, {
    name: newItemName.value,
    quantity: newItemQty.value,
    unit_price: newItemPrice.value,
    notes: newItemNotes.value || null,
  })

  newItemName.value = ''
  newItemQty.value = 1
  newItemPrice.value = 0
  newItemNotes.value = ''

  await loadLocal()
}

const online = computed(() => navigator.onLine)

async function pay() {
  error.value = ''

  if (!online.value) {
    error.value = 'Pagamento no MVP é somente online. Conecte à internet para concluir.'
    return
  }

  if (!payAmount.value || payAmount.value <= 0) {
    error.value = 'Informe um valor de pagamento válido.'
    return
  }

  // Registra local + outbox (idempotente por ULID no backend)
  await addLocalPayment(orderClientUid, {
    method: payMethod.value,
    amount: payAmount.value,
  })

  await loadLocal()

  // Opcional: se já tiver server_id, buscar estado atualizado do pedido
  // (isso ajuda a refletir status "paid" mais rápido)
  const db = await getDB()
  const o = await db.get('orders', orderClientUid)
  if (o?.server_id) {
    try {
      const cfg = { baseUrl: auth.baseUrl, token: auth.token }
      const serverOrder = await apiFetch<any>(cfg, `/orders/${o.server_id}`, { method: 'GET' })
      // você pode usar serverOrder.status para exibir “Pago”
      // Aqui mantemos simples
    } catch {
      // não bloqueia
    }
  }


  async function cancelItem(it: any) {
  if (!confirm('Cancelar este item?')) return

  const reason = prompt('Motivo do cancelamento (obrigatório):')
  if (!reason || reason.trim().length < 3) {
    alert('Informe um motivo válido.')
    return
  }

  await cancelLocalItem(orderClientUid, it.client_uid, reason.trim())
  await loadLocal()
}

}

onMounted(async () => {
  await auth.load()
  await meta.load()

  if (!auth.token) {
    router.replace('/login')
    return
  }

  loading.value = true
  try {
    await loadLocal()
    if (!order.value) {
      error.value = 'Pedido local não encontrado.'
    }
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div style="max-width:860px;margin:22px auto;padding:16px;">
    <button @click="router.push('/shift')">← Voltar</button>

    <h2 style="margin-top:10px;">Pedido</h2>

    <div v-if="loading">Carregando...</div>
    <p v-if="error" style="color:#c33;">{{ error }}</p>

    <div v-if="order" style="margin-top:12px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div><strong>Client UID:</strong> {{ order.client_uid }}</div>
        <div><strong>Server ID:</strong> {{ order.server_id ?? '— (pendente sync)' }}</div>
        <div><strong>Tipo:</strong> {{ order.type }}</div>
        <div><strong>Terminal:</strong> {{ order.terminal_id }}</div>
        <div v-if="order.table_id"><strong>Mesa:</strong> {{ order.table_id }}</div>
      </div>

      <div style="margin-top:10px;">
        <strong>Subtotal:</strong> R$ {{ money(order.subtotal ?? 0) }} |
        <strong>Total:</strong> R$ {{ money(order.total ?? 0) }}
      </div>
    </div>

    <div style="margin-top:18px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <h3>Adicionar Item</h3>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <input v-model="newItemName" placeholder="Item" style="padding:10px;flex:1;min-width:220px;" />
        <input type="number" v-model.number="newItemQty" min="0.001" step="0.001" placeholder="Qtd"
               style="padding:10px;width:120px;" />
        <input type="number" v-model.number="newItemPrice" min="0" step="0.01" placeholder="Preço"
               style="padding:10px;width:140px;" />
      </div>

      <textarea v-model="newItemNotes" placeholder="Observações" style="width:100%;padding:10px;margin-top:10px;"></textarea>

      <button @click="addItem" style="margin-top:10px;padding:10px 14px;">
        Adicionar
      </button>
    </div>

    <div style="margin-top:18px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <h3>Itens</h3>

      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #e6e6e6;">
            <th style="padding:8px;">Nome</th>
            <th style="padding:8px;">Qtd</th>
            <th style="padding:8px;">Preço</th>
            <th style="padding:8px;">Total</th>
            <th style="padding:8px;">Sync</th>
            <th style="padding:8px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="it in items" :key="it.client_uid" style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:8px;">{{ it.name }}</td>
            <td style="padding:8px;">{{ it.quantity }}</td>
            <td style="padding:8px;">R$ {{ money(it.unit_price) }}</td>
            <td style="padding:8px;">R$ {{ money(it.total) }}</td>
            <td style="padding:8px;">
              {{ it.server_id ? 'OK' : 'pendente' }}
            </td>
            <td style="padding:8px;">
  <button
    v-if="it.status !== 'canceled' && !it.server_id"
    @click="() => cancelItem(it)"
    style="padding:6px 10px;"
  >
    Remover (pendente)
  </button>

  <button
    v-else-if="it.status !== 'canceled'"
    @click="() => cancelItem(it)"
    style="padding:6px 10px;"
  >
    Cancelar
  </button>

  <span v-else style="color:#c33;">
    Cancelado
  </span>
</td>

          </tr>
        </tbody>
      </table>
    </div>

    <div style="margin-top:18px;padding:12px;border:1px solid #e6e6e6;border-radius:8px;">
      <h3>Pagamento (MVP: online)</h3>

      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <select v-model="payMethod" style="padding:10px;">
          <option value="pix">PIX</option>
          <option value="cash">Dinheiro</option>
          <option value="card">Cartão</option>
          <option value="voucher">Voucher</option>
        </select>

        <input type="number" v-model.number="payAmount" min="0.01" step="0.01"
               placeholder="Valor"
               style="padding:10px;width:160px;" />

        <button @click="pay" style="padding:10px 14px;">
          Confirmar Pagamento
        </button>

        <span :style="{color: online ? '#0a7' : '#c33'}">
          {{ online ? 'Online' : 'Offline' }}
        </span>
      </div>

      <div style="margin-top:10px;">
        <h4>Pagamentos Locais</h4>
        <div v-if="payments.length === 0" style="color:#666;">Nenhum pagamento registrado.</div>
        <ul v-else>
          <li v-for="p in payments" :key="p.client_uid">
            {{ p.method }} — R$ {{ money(p.amount) }} — {{ p.server_id ? 'OK' : 'pendente sync' }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
