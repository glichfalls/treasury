<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight, MailCheck } from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'
import Button from '@/components/ui/Button.vue'

const email = ref('')
const submitting = ref(false)
const sent = ref(false)
const error = ref<string | null>(null)

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const res = await fetch('/api/password/forgot', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.value.trim() }),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Failed (${res.status})`)
    }
    sent.value = true
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
          <h1 class="text-2xl font-semibold tracking-tight">Reset password</h1>
          <p class="text-sm text-[var(--color-text-muted)]">
            We'll send a reset link to your email.
          </p>
        </div>
      </div>

      <div v-if="sent" class="card p-6 text-center space-y-3">
        <div class="flex justify-center text-[var(--color-positive)]">
          <MailCheck :size="32" />
        </div>
        <h2 class="font-medium">Check your inbox</h2>
        <p class="text-sm text-[var(--color-text-muted)]">
          If <span class="text-[var(--color-text)]">{{ email }}</span> is registered, a reset link
          is on its way. It's valid for one hour.
        </p>
        <p class="text-xs text-[var(--color-text-dim)] pt-2">
          Didn't receive it? Ask the admin to check the server logs — they can share the link
          directly if mail delivery is down.
        </p>
      </div>

      <form v-else class="card p-6 space-y-4" @submit.prevent="submit">
        <div class="space-y-1.5">
          <label class="label" for="forgot-email">Email</label>
          <input
            id="forgot-email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="input"
            placeholder="you@example.com"
          />
        </div>

        <Button
          type="submit"
          variant="primary"
          size="lg"
          full-width
          :loading="submitting"
          loading-text="Sending…"
        >
          <span>Send reset link</span>
          <ArrowRight :size="16" />
        </Button>

        <p v-if="error" class="text-sm text-[var(--color-negative)] text-center pt-1">{{ error }}</p>
      </form>

      <p class="text-center text-sm text-[var(--color-text-muted)]">
        Remembered it?
        <RouterLink :to="{ name: 'login' }" class="text-[var(--color-accent)] hover:underline font-medium">
          Sign in
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
