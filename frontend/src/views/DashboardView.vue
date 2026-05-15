<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { formatMinor } from '@/lib/money'
import NetWorthChart from '@/components/NetWorthChart.vue'
import CashFlowChart from '@/components/CashFlowChart.vue'
import AllocationDonut from '@/components/AllocationDonut.vue'
import PerformanceChart from '@/components/PerformanceChart.vue'

const accounts = useAccountsStore()
const range = ref<'1w' | '1m' | '6mo' | '1y' | '2y' | '5y' | 'all'>('2y')
const networthMode = ref<'total' | 'stacked'>('total')

onMounted(() => {
  if (!accounts.loaded) {
    accounts.fetchAll()
  }
})

const netWorthByCurrency = computed(() => {
  const totals = new Map<string, bigint>()
  for (const a of accounts.accounts) {
    totals.set(a.currency, (totals.get(a.currency) ?? 0n) + BigInt(a.balanceMinor))
  }
  return [...totals.entries()].map(([currency, minor]) => ({ currency, minor: minor.toString() }))
})
</script>

<template>
  <div class="space-y-10">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">A bird's-eye view of your portfolio.</p>
    </header>

    <section>
      <h2 class="label mb-3">Net worth</h2>
      <div v-if="netWorthByCurrency.length === 0" class="text-[var(--color-text-muted)]">
        Add an account from the Accounts page to get started.
      </div>
      <div v-else class="flex flex-wrap gap-x-10 gap-y-3">
        <div v-for="t in netWorthByCurrency" :key="t.currency">
          <div class="text-3xl font-semibold tracking-tight tabular">
            {{ formatMinor(t.minor, t.currency) }}
          </div>
        </div>
      </div>
    </section>

    <section v-if="accounts.accounts.length > 0" class="space-y-4">
      <div class="flex items-center justify-end gap-1 -mb-2">
        <button
          v-for="m in (['total','stacked'] as const)"
          :key="m"
          :class="['text-xs px-2 py-0.5 rounded transition-colors',
            m === networthMode
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
          @click="networthMode = m"
        >{{ m === 'total' ? 'Total' : 'Cash + holdings' }}</button>
      </div>
      <NetWorthChart
        endpoint="/api/networth/timeseries"
        title="Net worth over time"
        :range="range"
        :mode="networthMode"
        granularity="weekly"
        @update:range="range = $event"
      />

      <PerformanceChart
        endpoint="/api/performance"
        title="Portfolio performance"
        :range="range"
        granularity="weekly"
        @update:range="range = $event"
      />

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <CashFlowChart :months="18" />
        <AllocationDonut endpoint="/api/allocation" />
      </div>
    </section>
  </div>
</template>
