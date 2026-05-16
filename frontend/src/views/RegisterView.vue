<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { UserPlus } from 'lucide-vue-next'
import BrandMark from '@/components/ui/BrandMark.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastsStore()

const email = ref('')
const password = ref('')
const code = ref((route.query.code as string | undefined) ?? '')
const error = ref<string | null>(null)
const submitting = ref(false)

async function submit() {
  error.value = null
  submitting.value = true
  try {
    await auth.register(email.value.trim(), password.value, code.value.trim().toUpperCase())
    toasts.success('Account created — welcome!')
    await router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center px-6 py-10">
    <div class="w-full max-w-sm">
      <div class="flex items-center justify-center gap-2 mb-8">
        <BrandMark :size="36" />
        <span class="text-2xl font-semibold tracking-tight">Treasury</span>
      </div>

      <form class="card p-6 space-y-4" @submit.prevent="submit">
        <div class="space-y-1">
          <h2 class="text-lg font-medium">Create account</h2>
          <p class="text-xs text-[var(--color-text-muted)]">
            Sign-up on the hosted instance is by invite code only — ask the admin if
            you don't have one, or self-host the open-source app instead.
          </p>
        </div>

        <div class="space-y-1.5">
          <label class="label">Invite code</label>
          <input
            v-model="code"
            required
            placeholder="ABCD-EFGH-JKLM"
            class="input uppercase tabular tracking-widest"
            autocomplete="off"
          />
        </div>

        <div class="space-y-1.5">
          <label class="label">Email</label>
          <input v-model="email" type="email" required autocomplete="email" class="input" />
        </div>

        <div class="space-y-1.5">
          <label class="label">Password</label>
          <input
            v-model="password"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
            class="input"
          />
          <p class="text-xs text-[var(--color-text-dim)]">Min. 8 characters.</p>
        </div>

        <button type="submit" class="btn btn-primary w-full" :disabled="submitting">
          <UserPlus v-if="!submitting" :size="16" />
          <span>{{ submitting ? 'Creating…' : 'Create account' }}</span>
        </button>

        <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>

        <p class="text-xs text-center text-[var(--color-text-muted)] pt-2 border-t" style="border-color: var(--color-border);">
          Already have an account?
          <RouterLink :to="{ name: 'login' }" class="text-[var(--color-accent)] hover:underline">Sign in</RouterLink>
        </p>
      </form>
    </div>
  </div>
</template>
