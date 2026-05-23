<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { KeyRound, Save } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

interface SettingItem {
  key: string
  label: string
  group: string
  secret: boolean
  configured: boolean
  hint: string | null
}

const toasts = useToastsStore()
const items = ref<SettingItem[]>([])
const drafts = ref<Record<string, string>>({})
const loading = ref(false)
const saving = ref(false)

async function load() {
  loading.value = true
  try {
    items.value = await api.get<SettingItem[]>('/api/admin/settings')
  } finally {
    loading.value = false
  }
}

onMounted(load)

const groups = computed(() => {
  const map = new Map<string, SettingItem[]>()
  for (const item of items.value) {
    if (!map.has(item.group)) map.set(item.group, [])
    map.get(item.group)!.push(item)
  }
  return [...map.entries()].map(([name, entries]) => ({ name, entries }))
})

async function save() {
  const payload: Record<string, string> = {}
  for (const [key, value] of Object.entries(drafts.value)) {
    if (value.trim() !== '') payload[key] = value.trim()
  }
  if (Object.keys(payload).length === 0) {
    toasts.info('Nothing to save — enter a value first.')
    return
  }
  saving.value = true
  try {
    items.value = await api.patch<SettingItem[]>('/api/admin/settings', payload)
    drafts.value = {}
    toasts.success('Integration settings saved.')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

async function clearKey(item: SettingItem) {
  try {
    items.value = await api.patch<SettingItem[]>('/api/admin/settings', { [item.key]: null })
    toasts.success(`${item.label} cleared.`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}
</script>

<template>
  <div class="card p-6 space-y-5">
    <div>
      <h2 class="text-lg font-medium">Integrations</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        API keys for news providers and AI. Stored server-side — enter a value to set or replace a key; leave blank to
        keep the current one.
      </p>
    </div>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>

    <form v-else class="space-y-5 max-w-md" @submit.prevent="save">
      <div v-for="group in groups" :key="group.name" class="space-y-3">
        <h3 class="label">{{ group.name }}</h3>
        <div v-for="item in group.entries" :key="item.key" class="space-y-1">
          <div class="flex items-center justify-between">
            <label class="text-sm">{{ item.label }}</label>
            <span
              v-if="item.configured"
              class="text-xs text-[var(--color-positive)] flex items-center gap-1"
            >
              <KeyRound :size="12" /> {{ item.hint }}
              <button type="button" class="ml-2 text-[var(--color-text-dim)] hover:text-[var(--color-negative)]" @click="clearKey(item)">
                Clear
              </button>
            </span>
            <span v-else class="text-xs text-[var(--color-text-dim)]">Not set</span>
          </div>
          <input
            v-model="drafts[item.key]"
            type="password"
            autocomplete="off"
            :placeholder="item.configured ? 'Enter to replace…' : 'Enter key…'"
            class="w-full px-3 py-1.5 text-sm rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] focus:outline-none focus:border-[var(--color-accent)]"
          />
        </div>
      </div>

      <Button type="submit" variant="primary" :loading="saving" loading-text="Saving…">
        <Save :size="14" />
        <span>Save</span>
      </Button>
    </form>
  </div>
</template>
