import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export interface AuthUser {
  id: string
  email: string
  roles?: string[]
  baseCurrency?: string
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const ready = ref(false)

  const isAdmin = computed(() => user.value?.roles?.includes('ROLE_ADMIN') ?? false)

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

  async function login(email: string, password: string, rememberMe = false): Promise<AuthUser> {
    // Remember-me flag goes in the query string because json_login doesn't read
    // the JSON body for it (Symfony's RememberMeFactory only inspects request
    // params via ParameterBagUtils, which doesn't parse JSON).
    const url = rememberMe ? '/api/login?_remember_me=on' : '/api/login'
    const res = await fetch(url, {
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

  async function register(email: string, password: string, code: string): Promise<AuthUser> {
    const res = await fetch('/api/register', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password, code }),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Registration failed (${res.status})`)
    }
    // After register, auto-login so the session cookie is set.
    return login(email, password)
  }

  async function logout(): Promise<void> {
    await fetch('/api/logout', { method: 'POST', credentials: 'include' })
    user.value = null
  }

  async function updatePreferences(input: { baseCurrency?: string }): Promise<AuthUser> {
    const res = await fetch('/api/me/preferences', {
      method: 'PATCH',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(input),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Failed (${res.status})`)
    }
    user.value = (await res.json()) as AuthUser
    return user.value
  }

  return { user, ready, isAdmin, fetchMe, login, register, logout, updatePreferences }
})
