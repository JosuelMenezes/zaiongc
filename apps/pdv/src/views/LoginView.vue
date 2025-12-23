<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

async function login() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${auth.baseUrl}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email: email.value, password: password.value })
    })
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    auth.token = data.token
    auth.user = data.user
    await auth.save()
    router.push('/setup-terminal')
  } catch (e: any) {
    error.value = e.message ?? 'Falha no login'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div style="max-width:420px;margin:40px auto;padding:16px;">
    <h2>ZaionGC PDV</h2>

    <label>API Base URL</label>
    <input v-model="auth.baseUrl" style="width:100%;padding:8px;margin:6px 0;" />

    <label>Email</label>
    <input v-model="email" style="width:100%;padding:8px;margin:6px 0;" />

    <label>Senha</label>
    <input v-model="password" type="password" style="width:100%;padding:8px;margin:6px 0;" />

    <button :disabled="loading" @click="login" style="width:100%;padding:10px;margin-top:10px;">
      {{ loading ? 'Entrando...' : 'Entrar' }}
    </button>

    <p v-if="error" style="color:#c33;margin-top:10px;">{{ error }}</p>
  </div>
</template>
