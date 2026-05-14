<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { LogOut, Wallet } from 'lucide-vue-next'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const showShell = computed(() => route.name !== 'login')

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header
      v-if="showShell"
      class="sticky top-0 z-10 backdrop-blur"
      style="background-color: color-mix(in srgb, var(--color-bg) 80%, transparent); border-bottom: 1px solid var(--color-border);"
    >
      <div class="mx-auto max-w-6xl px-6 py-3 flex items-center justify-between">
        <RouterLink :to="{ name: 'home' }" class="flex items-center gap-2 font-semibold tracking-tight">
          <Wallet :size="20" class="text-[var(--color-accent)]" />
          <span>Treasury</span>
        </RouterLink>

        <div v-if="auth.user" class="flex items-center gap-3 text-sm">
          <span class="text-[var(--color-text-muted)] hidden sm:inline">{{ auth.user.email }}</span>
          <button class="btn btn-ghost" @click="signOut">
            <LogOut :size="16" />
            <span>Sign out</span>
          </button>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <slot />
    </main>
  </div>
</template>
