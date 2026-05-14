import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface AuthUser {
  id: number
  email: string
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const ready = ref(false)

  async function fetchMe(): Promise<AuthUser | null> {
    const res = await fetch('/api/me', { credentials: 'include' })
    if (res.ok) {
      user.value = (await res.json()) as AuthUser
    } else {
      user.value = null
    }
    ready.value = true
    return user.value
  }

  async function login(email: string, password: string): Promise<AuthUser> {
    const res = await fetch('/api/login', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    })
    if (!res.ok) {
      throw new Error(`Login failed (${res.status})`)
    }
    user.value = (await res.json()) as AuthUser
    return user.value
  }

  async function logout(): Promise<void> {
    await fetch('/api/logout', { method: 'POST', credentials: 'include' })
    user.value = null
  }

  return { user, ready, fetchMe, login, logout }
})
