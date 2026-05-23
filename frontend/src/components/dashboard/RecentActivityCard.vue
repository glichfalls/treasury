<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api } from '@/lib/api'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'
import ChartCard from '@/components/ui/ChartCard.vue'

interface ActivityItem {
  id: string
  accountId: string
  accountName: string
  occurredAt: string
  amountMinor: string
  currency: string
  type: string
  description: string | null
  assetIsin: string | null
  assetQuantity: string | null
}

const items = ref<ActivityItem[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    items.value = await api.get<ActivityItem[]>('/api/dashboard/activity?limit=8')
  } finally {
    loading.value = false
  }
}

onMounted(load)

const empty = computed(() => items.value.length === 0)

const typeLabels: Record<string, string> = {
  deposit: 'Deposit',
  opening_balance: 'Opening balance',
  withdrawal: 'Withdrawal',
  trade_buy: 'Buy',
  trade_sell: 'Sell',
  fee: 'Fee',
  interest: 'Interest',
  dividend: 'Dividend',
  fx_conversion: 'FX',
  other: 'Other',
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { month: 'short', day: '2-digit' })
}

function amountClass(t: ActivityItem): string {
  const n = Number(t.amountMinor)
  if (n > 0) return 'text-[var(--color-positive)]'
  if (n < 0) return 'text-[var(--color-negative)]'
  return ''
}
</script>

<template>
  <ChartCard
    title="Recent activity"
    :loading="loading"
    :empty="empty"
    empty-text="No transactions yet."
    height="auto"
  >
    <ul class="divide-y divide-[var(--color-border)] -mx-2">
      <li v-for="t in items" :key="t.id">
        <router-link
          :to="{ name: 'transaction', params: { accountId: t.accountId, id: t.id } }"
          class="flex items-center gap-3 py-2 px-2 hover:bg-[var(--color-surface)]"
        >
          <span class="text-xs text-[var(--color-text-muted)] tabular w-12 shrink-0">
            {{ shortDate(t.occurredAt) }}
          </span>
          <span class="text-xs text-[var(--color-text-muted)] uppercase tracking-wide w-16 shrink-0">
            {{ typeLabels[t.type] ?? t.type }}
          </span>
          <span class="flex-1 min-w-0 truncate text-sm">
            <span class="font-medium">{{ t.description || t.assetIsin || '—' }}</span>
            <span class="text-[var(--color-text-muted)] text-xs ml-2">{{ t.accountName }}</span>
          </span>
          <span class="shrink-0 tabular text-sm font-medium" :class="amountClass(t)">
            <MoneyDisplay :minor="t.amountMinor" :currency="t.currency" sensitive />
          </span>
        </router-link>
      </li>
    </ul>
  </ChartCard>
</template>
