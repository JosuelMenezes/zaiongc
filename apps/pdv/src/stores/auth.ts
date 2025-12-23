import { defineStore } from 'pinia'
import { getDB } from '../core/db'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    baseUrl: 'http://127.0.0.1:8000/api',
    token: '' as string,
    user: null as any,
  }),
  actions: {
    async load() {
      const db = await getDB()
      const token = await db.get('meta', 'token')
      const baseUrl = await db.get('meta', 'baseUrl')
      this.token = token?.value ?? ''
      this.baseUrl = baseUrl?.value ?? this.baseUrl
    },
    async save() {
      const db = await getDB()
      await db.put('meta', { key: 'token', value: this.token }, 'token')
      await db.put('meta', { key: 'baseUrl', value: this.baseUrl }, 'baseUrl')
    },
    logout() {
      this.token = ''
      this.user = null
      this.save()
    }
  }
})
