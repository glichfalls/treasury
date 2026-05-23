<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'

interface PnlResponse {
  baseCurrency: string
  pnlBaseMinor: string
  previousValueBaseMinor: string
  pnlPct: number | null
  coveredAssets: number
  asOf: string | null
}

const data = ref<PnlResponse | null>(null)
const loading = ref(false)
const failed = ref(false)

async function load() {
  loading.value = true
  failed.value = false
  try {
    data.value = await api.get<PnlResponse>('/api/dashboard/pnl-today')
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)

const sign = computed(() => {
  if (!data.value) return 0
  const n = Number(data.value.pnlBaseMinor)
  return n > 0 ? 1 : n < 0 ? -1 : 0
})

const colorClass = computed(() => {
  if (sign.value > 0) return 'text-[var(--color-positive)]'
  if (sign.value < 0) return 'text-[var(--color-negative)]'
  return 'text-[var(--color-text-muted)]'
})

const prefix = computed(() => (sign.value > 0 ? '+' : ''))
const visible = computed(() => data.value !== null && data.value.coveredAssets > 0 && !failed.value)
</script>

<template>
  <div v-if="loading" class="text-xs text-[var(--color-text-muted)]">Loading today…</div>
  <div v-else-if="visible && data" class="text-sm tabular" :class="colorClass">
    <span class="font-medium">
      {{ prefix }}<MoneyDisplay :minor="data.pnlBaseMinor" :currency="data.baseCurrency" sensitive />
    </span>
    <span v-if="data.pnlPct !== null" class="ml-2">
      ({{ prefix }}{{ data.pnlPct.toFixed(2) }}%)
    </span>
    <span class="text-[var(--color-text-muted)] ml-2">today</span>
  </div>
</template>
