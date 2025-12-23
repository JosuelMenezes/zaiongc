import { syncOnce } from './sync'
import { useAuthStore } from '../stores/auth'

let timer: number | null = null

export function startSyncRunner() {
  if (timer) return

  timer = window.setInterval(async () => {
    try {
      const auth = useAuthStore()
      if (!auth.token || !auth.baseUrl) return
      if (!navigator.onLine) return

      await syncOnce({ baseUrl: auth.baseUrl, token: auth.token })
    } catch {
      // best effort
    }
  }, 1200)
}

export function stopSyncRunner() {
  if (timer) window.clearInterval(timer)
  timer = null
}
