<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { LogOut, Wallet, Menu, X, LayoutDashboard, Settings, Target } from 'lucide-vue-next'
import HeaderSearch from '@/components/HeaderSearch.vue'
import BrandMark from '@/components/BrandMark.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const showShell = computed(() => !route.meta.hideShell)
const activeName = computed(() => String(route.name ?? ''))

const navItems = [
  { to: { name: 'dashboard' }, label: 'Dashboard', icon: LayoutDashboard, match: (n: string) => n === 'dashboard' },
  { to: { name: 'accounts' }, label: 'Accounts', icon: Wallet, match: (n: string) => n === 'accounts' || n === 'account' || n === 'asset' },
  { to: { name: 'plan' }, label: 'Plan', icon: Target, match: (n: string) => n === 'plan' },
  { to: { name: 'settings' }, label: 'Settings', icon: Settings, match: (n: string) => n === 'settings' },
]

const mobileOpen = ref(false)

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}

function isActive(item: (typeof navItems)[number]): boolean {
  return item.match(activeName.value)
}
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header
      v-if="showShell"
      class="sticky top-0 z-20 backdrop-blur"
      style="background-color: color-mix(in srgb, var(--color-bg) 80%, transparent); border-bottom: 1px solid var(--color-border);"
    >
      <div class="mx-auto max-w-6xl px-6 py-3 flex items-center gap-6">
        <RouterLink :to="{ name: 'dashboard' }" class="flex items-center gap-2 font-semibold tracking-tight shrink-0">
          <BrandMark :size="22" />
          <span>Treasury</span>
        </RouterLink>

        <nav class="hidden sm:flex items-center gap-1">
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="px-3 py-1.5 rounded-md text-sm transition-colors"
            :class="isActive(item)
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]'"
          >{{ item.label }}</RouterLink>
        </nav>

        <div v-if="auth.user" class="ml-auto hidden md:block flex-1 max-w-md">
          <HeaderSearch />
        </div>

        <div v-if="auth.user" class="hidden sm:flex items-center gap-3 text-sm" :class="{ 'ml-auto': true }">
          <button class="btn btn-ghost" @click="signOut">
            <LogOut :size="16" />
            <span>Sign out</span>
          </button>
        </div>

        <button
          v-if="auth.user"
          type="button"
          class="ml-auto sm:hidden p-2 rounded-md"
          aria-label="Open menu"
          @click="mobileOpen = true"
        >
          <Menu :size="18" />
        </button>
      </div>
    </header>

    <!-- Mobile menu sheet -->
    <Teleport to="body">
      <div v-if="mobileOpen" class="sm:hidden fixed inset-0 z-40 bg-black/60" @click="mobileOpen = false" />
      <aside
        v-if="mobileOpen"
        class="sm:hidden fixed top-0 right-0 bottom-0 z-50 w-64 flex flex-col"
        style="background-color: var(--color-surface); border-left: 1px solid var(--color-border);"
      >
        <div class="flex items-center justify-between px-5 py-4 border-b" style="border-color: var(--color-border);">
          <span class="font-semibold tracking-tight">Menu</span>
          <button type="button" class="p-1 text-[var(--color-text-muted)]" aria-label="Close menu" @click="mobileOpen = false">
            <X :size="16" />
          </button>
        </div>
        <nav class="px-3 py-3 flex-1 space-y-0.5">
          <RouterLink
            v-for="item in navItems"
            :key="item.label"
            :to="item.to"
            class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors"
            :class="isActive(item)
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]'"
            @click="mobileOpen = false"
          >
            <component :is="item.icon" :size="16" />
            <span>{{ item.label }}</span>
          </RouterLink>
        </nav>
        <div v-if="auth.user" class="px-5 py-4 border-t" style="border-color: var(--color-border);">
          <div class="text-xs text-[var(--color-text-dim)] truncate mb-2">{{ auth.user.email }}</div>
          <button class="btn btn-ghost w-full justify-start text-sm" @click="signOut">
            <LogOut :size="14" />
            <span>Sign out</span>
          </button>
        </div>
      </aside>
    </Teleport>

    <main class="flex-1">
      <div v-if="showShell" class="mx-auto max-w-6xl px-6 py-10">
        <slot />
      </div>
      <slot v-else />
    </main>
  </div>
</template>
