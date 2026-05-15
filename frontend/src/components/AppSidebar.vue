<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { LayoutDashboard, Wallet, Settings, Menu, X } from 'lucide-vue-next'

const route = useRoute()

const items = [
  { to: { name: 'dashboard' }, label: 'Dashboard', icon: LayoutDashboard, match: (n: string) => n === 'dashboard' },
  { to: { name: 'accounts' }, label: 'Accounts', icon: Wallet, match: (n: string) => n === 'accounts' || n === 'account' },
  { to: { name: 'settings' }, label: 'Settings', icon: Settings, match: (n: string) => n === 'settings' },
]

const open = ref(false) // mobile drawer
const activeName = computed(() => String(route.name ?? ''))

function isActive(item: (typeof items)[number]): boolean {
  return item.match(activeName.value)
}
</script>

<template>
  <!-- Mobile toggle: shown only below sm breakpoint -->
  <button
    type="button"
    class="sm:hidden fixed top-3 left-3 z-30 p-2 rounded-md bg-[var(--color-surface)] border border-[var(--color-border)]"
    aria-label="Open navigation"
    @click="open = true"
  >
    <Menu :size="18" />
  </button>

  <!-- Backdrop for mobile drawer -->
  <div
    v-if="open"
    class="sm:hidden fixed inset-0 z-30 bg-black/60"
    @click="open = false"
  />

  <aside
    class="fixed top-0 bottom-0 left-0 z-40 w-56 flex flex-col border-r transition-transform sm:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full sm:translate-x-0'"
    style="background-color: var(--color-surface); border-color: var(--color-border);"
  >
    <div class="flex items-center justify-between px-5 pt-5 pb-6">
      <RouterLink :to="{ name: 'dashboard' }" class="flex items-center gap-2 font-semibold tracking-tight">
        <Wallet :size="18" class="text-[var(--color-accent)]" />
        <span>Treasury</span>
      </RouterLink>
      <button
        type="button"
        class="sm:hidden p-1 text-[var(--color-text-muted)]"
        aria-label="Close navigation"
        @click="open = false"
      >
        <X :size="16" />
      </button>
    </div>

    <nav class="px-3 flex-1 space-y-0.5">
      <RouterLink
        v-for="item in items"
        :key="item.label"
        :to="item.to"
        class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors"
        :class="isActive(item)
          ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
          : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]'"
        @click="open = false"
      >
        <component :is="item.icon" :size="16" />
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>

    <slot name="footer" />
  </aside>
</template>
