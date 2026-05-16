<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useToastsStore } from '@/stores/toasts'
import { Key } from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'

const route = useRoute()
const router = useRouter()
const toasts = useToastsStore()

const token = computed(() => String(route.query.token ?? ''))

const newPassword = ref('')
const confirmPassword = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

async function submit() {
  error.value = null
  if (newPassword.value !== confirmPassword.value) {
    error.value = "Passwords don't match"
    return
  }
  if (newPassword.value.length < 8) {
    error.value = 'Password must be at least 8 characters'
    return
  }

  submitting.value = true
  try {
    const res = await fetch('/api/password/reset', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token.value, newPassword: newPassword.value }),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Failed (${res.status})`)
    }
    toasts.success('Password reset — please sign in')
    await router.push({ name: 'login' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <div class="auth-glow" aria-hidden="true" />

    <div class="relative z-10 w-full max-w-sm mx-auto px-6 py-12 space-y-8 fade-in">
      <div class="flex flex-col items-center gap-3">
        <RouterLink :to="{ name: 'landing' }" class="brand-mark">
          <BrandMark :size="40" />
        </RouterLink>
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Set new password</h1>
          <p class="text-sm text-[var(--color-text-muted)]">
            Pick a new password for your Treasury account.
          </p>
        </div>
      </div>

      <div v-if="!token" class="card p-6 text-center space-y-2">
        <p class="font-medium">Reset link is missing</p>
        <p class="text-sm text-[var(--color-text-muted)]">
          Open the link from your email, or request a new one.
        </p>
        <RouterLink :to="{ name: 'forgot-password' }" class="btn btn-secondary mt-2">
          Request a new link
        </RouterLink>
      </div>

      <form v-else class="card p-6 space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <label class="label" for="rp-new">New password</label>
          <input
            id="rp-new"
            v-model="newPassword"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            class="input"
          />
          <p class="text-xs text-[var(--color-text-dim)]">Min. 8 characters.</p>
        </div>
        <div class="space-y-1.5">
          <label class="label" for="rp-confirm">Confirm password</label>
          <input
            id="rp-confirm"
            v-model="confirmPassword"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            class="input"
          />
        </div>

        <button type="submit" class="btn btn-primary w-full text-base py-2.5" :disabled="submitting">
          <Key :size="16" />
          <span>{{ submitting ? 'Saving…' : 'Set password' }}</span>
        </button>

        <p v-if="error" class="text-sm text-[var(--color-negative)] text-center pt-1">{{ error }}</p>
      </form>

      <p class="text-center text-sm text-[var(--color-text-muted)]">
        <RouterLink :to="{ name: 'login' }" class="text-[var(--color-accent)] hover:underline font-medium">
          Back to sign in
        </RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped>
.auth-page {
  position: relative;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: var(--color-bg);
  overflow: hidden;
}
.auth-glow {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  background:
    radial-gradient(40rem 40rem at 20% 0%, rgba(250, 204, 21, 0.18), transparent 60%),
    radial-gradient(35rem 35rem at 80% 100%, rgba(167, 139, 250, 0.16), transparent 60%);
}
.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 4rem;
  height: 4rem;
  border-radius: 1rem;
  background: color-mix(in srgb, var(--color-accent) 10%, var(--color-surface));
  border: 1px solid color-mix(in srgb, var(--color-accent) 30%, var(--color-border));
  transition: background-color 150ms ease, border-color 150ms ease;
}
.brand-mark:hover {
  background: color-mix(in srgb, var(--color-accent) 15%, var(--color-surface));
  border-color: color-mix(in srgb, var(--color-accent) 50%, var(--color-border));
}
.fade-in {
  animation: fade-up 0.5s cubic-bezier(.2,.8,.2,1) both;
}
@keyframes fade-up {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
  .fade-in { animation: none; }
}
.auth-page :deep(.input:focus) {
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 25%, transparent);
}
</style>
