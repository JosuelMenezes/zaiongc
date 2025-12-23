import { defineStore } from 'pinia'
import { syncOnce } from '../core/sync'
import { useAuthStore } from './auth'

export const useSyncStore = defineStore('sync', {
  state: () => ({
    running: false,
    lastResult: null as any,
  }),
  actions: {
    async tick() {
      const auth = useAuthStore()
      if (!auth.token) return

      const cfg = { baseUrl: auth.baseUrl, token: auth.token }

      try {
        this.lastResult = await syncOnce(cfg)
      } catch (e: any) {
        this.lastResult = { processed: false, error: e?.message ?? 'sync error' }
      }
    },
    start() {
      if (this.running) return
      this.running = true

      const loop = async () => {
        if (!this.running) return
        await this.tick()
        // ciclo rápido, mas seguro
        setTimeout(loop, 600)
      }
      loop()
    },
    stop() {
      this.running = false
    }
  }
})
