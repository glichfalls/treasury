<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { Wallet, LogIn } from 'lucide-vue-next'

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

const auth = useAuthStore()
const router = useRouter()

async function submit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    await router.push({ name: 'home' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-6">
    <div class="w-full max-w-sm">
      <div class="flex items-center justify-center gap-2 mb-8">
        <Wallet :size="28" class="text-[var(--color-accent)]" />
        <span class="text-2xl font-semibold tracking-tight">Treasury</span>
      </div>

      <form class="card p-6 space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <label class="label">Email</label>
          <input v-model="email" type="email" required autocomplete="username" class="input" />
        </div>
        <div class="space-y-1.5">
          <label class="label">Password</label>
          <input v-model="password" type="password" required autocomplete="current-password" class="input" />
        </div>

        <button type="submit" class="btn btn-primary w-full" :disabled="loading">
          <LogIn v-if="!loading" :size="16" />
          <span>{{ loading ? 'Signing in…' : 'Sign in' }}</span>
        </button>

        <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
      </form>
    </div>
  </div>
</template>
