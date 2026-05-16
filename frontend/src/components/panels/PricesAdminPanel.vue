<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { RefreshCw } from 'lucide-vue-next'

const toasts = useToastsStore()
const busy = ref(false)

async function reload() {
  busy.value = true
  try {
    await api.post('/api/admin/prices/refresh', {})
    toasts.success('Price refresh queued — the worker will pick it up shortly.')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Prices</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Manually trigger a price &amp; FX refresh. Runs the same job the nightly
        scheduler runs (latest quotes + a one-month backfill for any missed days).
      </p>
    </div>

    <button type="button" class="btn btn-primary" :disabled="busy" @click="reload">
      <RefreshCw :size="14" :class="busy ? 'animate-spin' : ''" />
      {{ busy ? 'Queuing…' : 'Reload prices now' }}
    </button>
  </div>
</template>
