<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'
import NetWorthChart from '@/components/charts/NetWorthChart.vue'
import CashFlowChart from '@/components/charts/CashFlowChart.vue'
import CashflowByCategoryChart from '@/components/charts/CashflowByCategoryChart.vue'
import AllocationDonut from '@/components/charts/AllocationDonut.vue'
import PerformanceChart from '@/components/charts/PerformanceChart.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import TodayPnl from '@/components/dashboard/TodayPnl.vue'
import TopMoversCard from '@/components/dashboard/TopMoversCard.vue'
import RecentActivityCard from '@/components/dashboard/RecentActivityCard.vue'
import NewsWidget from '@/components/dashboard/NewsWidget.vue'

const accounts = useAccountsStore()
const range = ref<'1w' | '1m' | '3m' | '6m' | 'ytd' | '1y' | '2y' | '5y' | 'all'>('ytd')
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
  <div class="space-y-4">
    <!-- Hero: the headline number, given room to breathe and a subtle brand tint. -->
    <section
      class="rounded-xl border border-[var(--color-border)] p-6"
      style="background-image: linear-gradient(135deg, color-mix(in srgb, var(--color-accent) 9%, var(--color-surface)), var(--color-surface) 60%);"
    >
      <div class="label">Net worth</div>
      <div v-if="netWorthByCurrency.length === 0" class="text-[var(--color-text-muted)] mt-2">
        Add an account from the Accounts page to get started.
      </div>
      <template v-else>
        <div class="flex flex-wrap items-end gap-x-10 gap-y-1 mt-1.5">
          <div
            v-for="t in netWorthByCurrency"
            :key="t.currency"
            class="text-4xl sm:text-5xl font-semibold tracking-tight tabular"
          >
            <MoneyDisplay :minor="t.minor" :currency="t.currency" sensitive />
          </div>
        </div>
        <TodayPnl class="mt-2" />
      </template>
    </section>

    <template v-if="accounts.accounts.length > 0">
      <!-- Primary tiles: net-worth trend (wide) + allocation. -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 space-y-2">
          <div class="flex items-center justify-end">
            <SegmentedControl
              v-model="networthMode"
              :options="[
                { value: 'total', label: 'Total' },
                { value: 'stacked', label: 'Cash + holdings' },
              ]"
            />
          </div>
          <NetWorthChart
            endpoint="/api/networth/timeseries"
            title="Net worth over time"
            :range="range"
            :mode="networthMode"
            @update:range="range = $event"
          />
        </div>
        <AllocationDonut endpoint="/api/allocation" class="lg:col-span-1" />
      </div>

      <!-- Movers (narrow) + performance (wide). -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <TopMoversCard class="lg:col-span-1" />
        <PerformanceChart
          endpoint="/api/performance"
          title="Portfolio performance"
          :range="range"
          class="lg:col-span-2"
          @update:range="range = $event"
        />
      </div>

      <!-- Holdings news, full width. -->
      <NewsWidget />

      <!-- Cash flow + recent activity. -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <CashFlowChart :months="18" />
        <RecentActivityCard />
      </div>

      <CashflowByCategoryChart :months="12" />
    </template>
  </div>
</template>
