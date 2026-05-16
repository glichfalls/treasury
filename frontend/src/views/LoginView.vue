<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ArrowRight } from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'
import Button from '@/components/ui/Button.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const rememberMe = ref(true)
const error = ref<string | null>(null)
const loading = ref(false)

async function submit() {
  error.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value, rememberMe.value)
    const next = typeof route.query.next === 'string' ? route.query.next : '/dashboard'
    await router.push(next)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <!-- Decorative glow (cheap radial-gradient, no fixed positioning, no animation
         to avoid the scroll/repaint cost the landing page had before). -->
    <div class="auth-glow" aria-hidden="true" />

    <div class="relative z-10 w-full max-w-sm mx-auto px-6 py-12 space-y-8 fade-in">
      <!-- Brand mark -->
      <div class="flex flex-col items-center gap-3">
        <RouterLink :to="{ name: 'landing' }" class="brand-mark">
          <BrandMark :size="40" />
        </RouterLink>
        <div class="text-center space-y-1">
          <h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
          <p class="text-sm text-[var(--color-text-muted)]">Sign in to your Treasury account</p>
        </div>
      </div>

      <!-- Form card -->
      <form class="card p-6 space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <label class="label" for="login-email">Email</label>
          <input
            id="login-email"
            v-model="email"
            type="email"
            required
            autocomplete="username"
            class="input"
            placeholder="you@example.com"
          />
        </div>

        <div class="space-y-1.5">
          <div class="flex items-baseline justify-between">
            <label class="label" for="login-password">Password</label>
            <RouterLink
              :to="{ name: 'forgot-password' }"
              class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-accent)] transition-colors"
            >Forgot?</RouterLink>
          </div>
          <input
            id="login-password"
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            class="input"
            placeholder="••••••••"
          />
        </div>

        <label class="flex items-center gap-2 text-sm text-[var(--color-text-muted)] cursor-pointer select-none">
          <input
            v-model="rememberMe"
            type="checkbox"
            class="accent-[var(--color-accent)] w-4 h-4 rounded"
          />
          <span>Keep me signed in for 14 days</span>
        </label>

        <Button
          type="submit"
          variant="primary"
          size="lg"
          full-width
          :loading="loading"
          loading-text="Signing in…"
        >
          <span>Sign in</span>
          <ArrowRight :size="16" />
        </Button>

        <p v-if="error" class="text-sm text-[var(--color-negative)] text-center pt-1">{{ error }}</p>
      </form>

      <!-- Switch to register -->
      <p class="text-center text-sm text-[var(--color-text-muted)]">
        New to Treasury?
        <RouterLink :to="{ name: 'register' }" class="text-[var(--color-accent)] hover:underline font-medium">
          Create an account
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

/* Subtle background glow — same palette as landing, scoped to the auth pages.
   No fixed positioning, no mask, no animation: just an absolutely-positioned
   block painted with two radial gradients so it scrolls / repaints once. */
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

/* Subtle fade-in on mount so the page doesn't snap into existence */
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

/* Focus ring on inputs — uses accent yellow for the new palette */
.auth-page :deep(.input:focus) {
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 25%, transparent);
}
</style>
