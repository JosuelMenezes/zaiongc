import { defineStore } from 'pinia'
import { listTablesLocal, syncTables } from '../domain/tables'
import { useAuthStore } from './auth'

export type TableVM = { id: number; label: string; status: 'free' | 'occupied' }

export const useTablesStore = defineStore('tables', {
  state: () => ({
    loading: false,
    error: '' as string,
    items: [] as TableVM[],
    lastSyncAt: '' as string,
  }),
  actions: {
    async loadLocal() {
      this.items = await listTablesLocal()
    },
    async refresh() {
      const auth = useAuthStore()
      if (!auth.token) return

      this.loading = true
      this.error = ''

      const cfg = { baseUrl: auth.baseUrl, token: auth.token }
      const res = await syncTables(cfg)

      await this.loadLocal()

      if (!res.ok) {
        this.error = res.error ?? 'Falha ao atualizar mesas'
      } else {
        this.lastSyncAt = new Date().toISOString()
      }

      this.loading = false
    }
  }
})
