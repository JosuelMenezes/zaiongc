import { defineStore } from 'pinia'
import { getDB } from '../core/db'

export const useMetaStore = defineStore('meta', {
  state: () => ({
    deviceId: 'pdv-01',
    terminalId: null as number | null,
    shiftId: null as number | null,
  }),
  actions: {
    async load() {
      const db = await getDB()
      this.deviceId = (await db.get('meta', 'device_id'))?.value ?? this.deviceId
      this.terminalId = (await db.get('meta', 'terminal_id'))?.value ?? null
      this.shiftId = (await db.get('meta', 'shift_id'))?.value ?? null
    },
    async setTerminalId(id: number) {
      const db = await getDB()
      this.terminalId = id
      await db.put('meta', { key: 'terminal_id', value: id }, 'terminal_id')
    },
    async setShiftId(id: number | null) {
      const db = await getDB()
      this.shiftId = id
      await db.put('meta', { key: 'shift_id', value: id }, 'shift_id')
    }
  }
})
