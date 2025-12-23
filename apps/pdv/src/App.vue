<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from './stores/auth'
import { useMetaStore } from './stores/meta'
import { useSyncStore } from './stores/sync'
import SyncStatusBar from './views/SyncStatusBar.vue'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const meta = useMetaStore()
const sync = useSyncStore()
const router = useRouter()

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

  sync.start()
})
</script>

<template>
  <div>
    <SyncStatusBar />
    <router-view />
  </div>
</template>
