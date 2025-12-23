<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMetaStore } from '../stores/meta'
import { useSyncStore } from '../stores/sync'
import { getDB } from '../core/db'

const auth = useAuthStore()
const meta = useMetaStore()
const sync = useSyncStore()

const online = ref(navigator.onLine)
const pendingCount = ref(0)

async function refreshPending() {
  const db = await getDB()
  const pending = await db.getAllFromIndex('outbox', 'by_status', 'pending')
  const sending = await db.getAllFromIndex('outbox', 'by_status', 'sending')
  pendingCount.value = pending.length + sending.length
}

onMounted(async () => {
  window.addEventListener('online', () => (online.value = true))
  window.addEventListener('offline', () => (online.value = false))

  await refreshPending()

  // Atualiza pendências periodicamente
  setInterval(refreshPending, 1200)
})

const last = computed(() => sync.lastResult)
</script>

<template>
  <div style="display:flex;gap:14px;align-items:center;padding:10px 14px;border-bottom:1px solid #e6e6e6;">
    <strong>ZaionGC PDV</strong>

    <span>Terminal: <strong>{{ meta.terminalId ?? '-' }}</strong></span>

    <span>
      Status:
      <strong :style="{color: online ? '#0a7' : '#c33'}">
        {{ online ? 'Online' : 'Offline' }}
      </strong>
    </span>

    <span>Pendências: <strong>{{ pendingCount }}</strong></span>

    <span v-if="last?.error" style="color:#c33;">
      Último erro: {{ last.error }}
    </span>

    <span v-else-if="last?.rejected" style="color:#c33;">
      Ação rejeitada (ver conflito)
    </span>

    <span v-else style="color:#666;">
      Sync: {{ last?.processed ? 'rodando' : 'idle' }}
    </span>

    <span style="margin-left:auto;"></span>

    <span v-if="auth.user?.name" style="color:#444;">
      {{ auth.user.name }}
    </span>
  </div>
</template>
