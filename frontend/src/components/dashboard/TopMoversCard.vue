<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'
import ChartCard from '@/components/ui/ChartCard.vue'

interface Mover {
  isin: string
  ticker: string | null
  name: string | null
  quantity: string
  priceCurrency: string | null
  latestPriceMinor: string | null
  previousPriceMinor: string | null
  dayChangePct: number
  dayPnlBaseMinor: string
  valueBaseMinor: string
  previousValueBaseMinor: string
}

interface MoversResponse {
  baseCurrency: string
  gainers: Mover[]
  losers: Mover[]
}

const data = ref<MoversResponse | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.get<MoversResponse>('/api/dashboard/movers?limit=5')
  } finally {
    loading.value = false
  }
}

onMounted(load)

const empty = computed(() => !data.value || (data.value.gainers.length === 0 && data.value.losers.length === 0))

function label(m: Mover): string {
  return m.ticker ?? m.name ?? m.isin
}
</script>

<template>
  <ChartCard
    title="Top movers today"
    :loading="loading"
    :empty="empty"
    empty-text="No price moves yet today."
    height="auto"
  >
    <div v-if="data" class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 mt-1">
      <div>
        <h4 class="label text-[var(--color-positive)] mb-1">Gainers</h4>
        <ul v-if="data.gainers.length > 0" class="space-y-1">
          <li v-for="m in data.gainers" :key="'g-' + m.isin">
            <router-link
              :to="{ name: 'asset', params: { isin: m.isin } }"
              class="flex items-baseline justify-between gap-2 py-1 px-2 -mx-2 rounded hover:bg-[var(--color-surface)]"
            >
              <span class="truncate min-w-0">
                <span class="font-medium">{{ label(m) }}</span>
                <span v-if="m.ticker && m.name" class="text-[var(--color-text-muted)] text-xs ml-1 truncate">
                  {{ m.name }}
                </span>
              </span>
              <span class="shrink-0 tabular text-sm flex items-baseline gap-2">
                <span class="text-[var(--color-positive)] font-medium">
                  +{{ m.dayChangePct.toFixed(2) }}%
                </span>
                <span class="text-[var(--color-text-muted)] text-xs">
                  +<MoneyDisplay :minor="m.dayPnlBaseMinor" :currency="data.baseCurrency" sensitive />
                </span>
              </span>
            </router-link>
          </li>
        </ul>
        <div v-else class="text-xs text-[var(--color-text-muted)] py-1">No gainers.</div>
      </div>

      <div>
        <h4 class="label text-[var(--color-negative)] mb-1">Losers</h4>
        <ul v-if="data.losers.length > 0" class="space-y-1">
          <li v-for="m in data.losers" :key="'l-' + m.isin">
            <router-link
              :to="{ name: 'asset', params: { isin: m.isin } }"
              class="flex items-baseline justify-between gap-2 py-1 px-2 -mx-2 rounded hover:bg-[var(--color-surface)]"
            >
              <span class="truncate min-w-0">
                <span class="font-medium">{{ label(m) }}</span>
                <span v-if="m.ticker && m.name" class="text-[var(--color-text-muted)] text-xs ml-1 truncate">
                  {{ m.name }}
                </span>
              </span>
              <span class="shrink-0 tabular text-sm flex items-baseline gap-2">
                <span class="text-[var(--color-negative)] font-medium">
                  {{ m.dayChangePct.toFixed(2) }}%
                </span>
                <span class="text-[var(--color-text-muted)] text-xs">
                  <MoneyDisplay :minor="m.dayPnlBaseMinor" :currency="data.baseCurrency" sensitive />
                </span>
              </span>
            </router-link>
          </li>
        </ul>
        <div v-else class="text-xs text-[var(--color-text-muted)] py-1">No losers.</div>
      </div>
    </div>
  </ChartCard>
</template>
