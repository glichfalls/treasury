<script setup lang="ts">
import { formatMinor, formatQuantity } from '@/lib/money'
import type { Holding } from '@/stores/accounts'
import { TrendingUp } from 'lucide-vue-next'

defineProps<{ holdings: Holding[]; baseCurrency: string }>()

function formatPrice(h: Holding): string {
  if (h.priceMinor === null || h.priceCurrency === null) return '—'
  return formatMinor(h.priceMinor, h.priceCurrency)
}

function formatValue(h: Holding): string {
  if (h.valueBaseMinor === null) return '—'
  return formatMinor(h.valueBaseMinor, h.baseCurrency)
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <TrendingUp :size="18" class="text-[var(--color-accent)]" />
        Holdings
      </h2>
    </div>

    <div v-if="holdings.length === 0" class="card p-8 text-center text-[var(--color-text-muted)] text-sm">
      No holdings.
    </div>
    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Ticker</th>
            <th>Name</th>
            <th class="text-right w-24">Quantity</th>
            <th class="text-right w-32">Last price</th>
            <th class="text-right w-32">Value</th>
            <th class="w-24">As of</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in holdings" :key="h.isin">
            <td>
              <div class="font-medium">{{ h.ticker ?? '—' }}</div>
              <div class="text-xs text-[var(--color-text-dim)]">{{ h.isin }}</div>
            </td>
            <td class="text-[var(--color-text-muted)] truncate max-w-xs">{{ h.name ?? '—' }}</td>
            <td class="text-right tabular">{{ formatQuantity(h.quantity) }}</td>
            <td class="text-right tabular text-[var(--color-text-muted)]">{{ formatPrice(h) }}</td>
            <td class="text-right tabular font-medium">{{ formatValue(h) }}</td>
            <td class="text-xs text-[var(--color-text-dim)]">{{ h.priceAsOf ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
