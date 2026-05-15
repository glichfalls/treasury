<script setup lang="ts">
import { ref } from 'vue'
import { useToastsStore } from '@/stores/toasts'
import { Key } from 'lucide-vue-next'

const toasts = useToastsStore()

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

function reset() {
  currentPassword.value = ''
  newPassword.value = ''
  confirmPassword.value = ''
  error.value = null
}

async function submit() {
  error.value = null
  if (newPassword.value !== confirmPassword.value) {
    error.value = "New password and confirmation don't match"
    return
  }
  if (newPassword.value.length < 8) {
    error.value = 'New password must be at least 8 characters'
    return
  }

  submitting.value = true
  try {
    const res = await fetch('/api/me/password', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        currentPassword: currentPassword.value,
        newPassword: newPassword.value,
      }),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Failed (${res.status})`)
    }
    toasts.success('Password changed')
    reset()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Change password</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        You'll stay signed in after changing your password.
      </p>
    </div>

    <form class="space-y-4 max-w-md" @submit.prevent="submit">
      <div class="space-y-1.5">
        <label class="label" for="cp-current">Current password</label>
        <input
          id="cp-current"
          v-model="currentPassword"
          type="password"
          required
          autocomplete="current-password"
          class="input"
        />
      </div>
      <div class="space-y-1.5">
        <label class="label" for="cp-new">New password</label>
        <input
          id="cp-new"
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
        <label class="label" for="cp-confirm">Confirm new password</label>
        <input
          id="cp-confirm"
          v-model="confirmPassword"
          type="password"
          required
          minlength="8"
          autocomplete="new-password"
          class="input"
        />
      </div>

      <div class="flex items-center gap-3 pt-1">
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          <Key :size="14" />
          <span>{{ submitting ? 'Saving…' : 'Update password' }}</span>
        </button>
        <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
      </div>
    </form>
  </div>
</template>
