<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMetaStore } from '../stores/meta'
import { apiFetch } from '../core/api'
import { useRouter } from 'vue-router'
import { useSyncStore } from '../stores/sync'

const auth = useAuthStore()
const meta = useMetaStore()
const sync = useSyncStore()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const terminals = ref<any[]>([])
const selected = ref<number | null>(meta.terminalId)

async function loadTerminals() {
  loading.value = true
  error.value = ''
  try {
    const cfg = { baseUrl: auth.baseUrl, token: auth.token }
    const res = await apiFetch<any[]>(cfg, '/terminals', { method: 'GET' })
    terminals.value = res ?? []
  } catch (e: any) {
    error.value = e.message ?? 'Falha ao carregar terminais'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!selected.value) return
  await meta.setTerminalId(selected.value)
  sync.start()
  router.replace('/shift')
}

onMounted(async () => {
  await auth.load()
  await meta.load()

  if (!auth.token) {
    router.replace('/login')
    return
  }

  await loadTerminals()
})
</script>

<template>
  <div style="max-width:640px;margin:22px auto;padding:16px;">
    <h2>Selecionar Terminal</h2>
    <p style="color:#666;margin-top:4px;">
      Este PDV ficará vinculado a um terminal. Isso é importante para caixa/turno e concorrência.
    </p>

    <div v-if="loading">Carregando...</div>
    <p v-if="error" style="color:#c33;">{{ error }}</p>

    <div v-if="!loading && terminals.length">
      <label>Terminal</label>
      <select v-model="selected" style="width:100%;padding:10px;margin-top:6px;">
        <option :value="null">Selecione...</option>
        <option v-for="t in terminals" :key="t.id" :value="t.id">
          #{{ t.id }} — {{ t.name ?? t.code ?? 'Terminal' }}
        </option>
      </select>

      <button :disabled="!selected" @click="save" style="width:100%;padding:12px;margin-top:12px;">
        Confirmar Terminal
      </button>
    </div>

    <div v-else-if="!loading">
      <p style="color:#666;">
        Nenhum terminal cadastrado. Cadastre um terminal via API/Backoffice.
      </p>
    </div>
  </div>
</template>
