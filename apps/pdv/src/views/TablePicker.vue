<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useTablesStore } from '../stores/tables'

const props = defineProps<{
  modelValue: number | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: number | null): void
  (e: 'open-occupied', tableId: number): void
}>()


const store = useTablesStore()
const q = ref('')
let timer: number | null = null

const filtered = computed(() => {
  const term = q.value.trim().toLowerCase()
  if (!term) return store.items
  return store.items.filter(t =>
    t.label.toLowerCase().includes(term) || String(t.id).includes(term)
  )
})

function clear() {
  emit('update:modelValue', null)
}

function select(id: number) {
  const t = store.items.find(x => x.id === id)

  if (t && t.status === 'occupied') {
    emit('open-occupied', id)
    return
  }

  emit('update:modelValue', id)
}


async function boot() {
  // Render rápido com cache
  await store.loadLocal()

  // Tenta atualizar (se online)
  if (navigator.onLine) {
    await store.refresh()
  }

  // Auto-refresh a cada 10s quando online
  timer = window.setInterval(() => {
    if (navigator.onLine) store.refresh()
  }, 10_000)
}

onMounted(() => {
  boot()
})

onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<template>
  <div style="border:1px solid #e6e6e6;border-radius:8px;padding:12px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <strong>Mesas</strong>

      <button @click="store.refresh" :disabled="store.loading" style="padding:8px 10px;">
        {{ store.loading ? 'Atualizando...' : 'Atualizar' }}
      </button>

      <span v-if="store.error" style="color:#c33;">{{ store.error }}</span>

      <span style="margin-left:auto;"></span>

      <button v-if="modelValue" @click="clear" style="padding:8px 10px;">
        Limpar seleção
      </button>
    </div>

    <div style="margin-top:10px;">
      <input v-model="q" placeholder="Buscar mesa..." style="width:100%;padding:10px;" />
    </div>

    <div style="margin-top:10px;max-height:260px;overflow:auto;border:1px solid #f0f0f0;border-radius:6px;">
      <div
        v-for="t in filtered"
        :key="t.id"
        @click="select(t.id)"
        style="display:flex;justify-content:space-between;gap:10px;padding:10px;border-bottom:1px solid #f5f5f5;"
       :style="{
  background: modelValue === t.id ? '#eef6ff' : 'transparent',
  opacity: t.status === 'occupied' ? 0.85 : 1,
  cursor: 'pointer'
}"

      >
        <div>
          <div>
            <strong>{{ t.label }}</strong>
            <span style="color:#888;">(#{{ t.id }})</span>
          </div>
        </div>

        <div :style="{ color: t.status === 'free' ? '#0a7' : '#c33' }">
          {{ t.status === 'free' ? 'Livre' : 'Ocupada' }}
        </div>
      </div>

      <div v-if="filtered.length === 0" style="padding:12px;color:#666;">
        Nenhuma mesa encontrada no cache local.
      </div>
    </div>

    <div v-if="modelValue" style="margin-top:10px;">
      Selecionada: <strong>#{{ modelValue }}</strong>
    </div>

    <div style="margin-top:10px;color:#666;font-size:12px;">
      Dica: mesas “ocupadas” ficam bloqueadas para seleção (evita conflito operacional).
    </div>
  </div>
</template>
