<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { VChart, chartColors, rangeBounds, type EChartsOption, type Range } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'

interface MonthlyPoint { month: string; amountBaseMinor: string }
interface ByAssetRow {
  isin: string
  ticker: string | null
  name: string | null
  amountBaseMinor: string
  count: number
}
interface Response {
  monthly: MonthlyPoint[]
  byAsset: ByAssetRow[]
  forecast: MonthlyPoint[]
  totalBaseMinor: string
  ytdBaseMinor: string
  lifetimeBaseMinor: string
  forecastTotalBaseMinor: string
  baseCurrency: string
}

const props = defineProps<{ range: Range }>()

const data = ref<Response | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(props.range)
    data.value = await api.get<Response>(
      `/api/insights/dividends?from=${from}&to=${to}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.range, load)

const currency = computed(() => data.value?.baseCurrency ?? 'CHF')

// Combine actuals (positive bars) and forecast (lighter bars) on the same chart
// so the user sees the income trajectory in one place.
const chartOption = computed<EChartsOption>(() => {
  const actuals = data.value?.monthly ?? []
  const forecast = data.value?.forecast ?? []
  const months = [...actuals.map((m) => m.month), ...forecast.map((m) => m.month)]
  const actualData = [
    ...actuals.map((m) => Number(m.amountBaseMinor) / 100),
    ...forecast.map(() => 0),
  ]
  const forecastData = [
    ...actuals.map(() => 0),
    ...forecast.map((m) => Number(m.amountBaseMinor) / 100),
  ]
  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 40, left: 60, containLabel: true },
    legend: {
      top: 4,
      right: 10,
      textStyle: { color: chartColors.textMuted, fontSize: 11 },
      itemWidth: 10,
      itemHeight: 10,
    },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; data: number; seriesName: string; color: string }>
        if (arr.length === 0) return ''
        const lines = arr
          .filter((p) => p.data !== 0)
          .map((p) => `<div style="color:${p.color}">● ${p.seriesName}: ${formatMinor(String(Math.round(p.data * 100)), currency.value)}</div>`)
          .join('')
        return `<div style="font-weight:600">${arr[0]!.axisValue}</div>${lines}`
      },
    },
    xAxis: {
      type: 'category',
      data: months,
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
    },
    yAxis: {
      type: 'value',
      axisLine: { show: false },
      splitLine: { lineStyle: { color: chartColors.border, opacity: 0.4 } },
      axisLabel: {
        color: chartColors.textMuted,
        fontSize: 11,
        formatter: (v: number) => {
          const abs = Math.abs(v)
          if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${(v / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    series: [
      {
        name: 'Received',
        type: 'bar',
        itemStyle: { color: chartColors.positive, borderRadius: [2, 2, 0, 0] },
        data: actualData,
      },
      {
        name: 'Forecast (12mo)',
        type: 'bar',
        itemStyle: {
          color: chartColors.positive,
          opacity: 0.35,
          borderRadius: [2, 2, 0, 0],
        },
        data: forecastData,
      },
    ],
  }
})
</script>

<template>
  <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>
  <div v-else-if="!data" class="text-[var(--color-text-muted)]">No data.</div>
  <div v-else class="space-y-4">
    <!-- Totals strip -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
         style="background-color: var(--color-border);">
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Range total</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-positive)]">
          <MoneyDisplay :minor="data.totalBaseMinor" :currency="currency" sensitive />
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">YTD</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-positive)]">
          <MoneyDisplay :minor="data.ytdBaseMinor" :currency="currency" sensitive />
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Lifetime</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-positive)]">
          <MoneyDisplay :minor="data.lifetimeBaseMinor" :currency="currency" sensitive />
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Next 12mo (est.)</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-text-muted)]">
          <MoneyDisplay :minor="data.forecastTotalBaseMinor" :currency="currency" sensitive />
        </p>
      </div>
    </div>

    <ChartCard title="Dividends received + forecast" :empty="data.monthly.length === 0 && data.forecast.length === 0">
      <VChart :option="chartOption" class="w-full" style="height: 18rem" autoresize />
    </ChartCard>

    <ChartCard title="By asset" :empty="data.byAsset.length === 0" empty-text="No dividends in this range.">
      <div class="space-y-2 text-sm">
        <div class="flex items-center justify-between text-xs text-[var(--color-text-muted)] pb-1 border-b" style="border-color: var(--color-border);">
          <span class="flex-1">Asset</span>
          <span class="w-16 text-right">Count</span>
          <span class="w-28 text-right">Total</span>
        </div>
        <div
          v-for="row in data.byAsset"
          :key="row.isin"
          class="flex items-center justify-between gap-2"
        >
          <RouterLink
            :to="{ name: 'asset', params: { isin: row.isin } }"
            class="flex-1 min-w-0 hover:text-[var(--color-accent)] transition-colors"
          >
            <div class="font-medium truncate">{{ row.ticker ?? row.name ?? row.isin }}</div>
            <div class="text-xs text-[var(--color-text-dim)] truncate">{{ row.isin }}</div>
          </RouterLink>
          <span class="w-16 text-right tabular text-[var(--color-text-muted)]">{{ row.count }}</span>
          <MoneyDisplay :minor="row.amountBaseMinor" :currency="currency" sensitive class="w-28 text-right tabular text-[var(--color-positive)] font-medium" />
        </div>
      </div>
    </ChartCard>

    <p class="text-xs text-[var(--color-text-dim)]">
      Forecast: for each currently-held position, projects future dividends using the median
      payment cadence and average payment size from past history. Estimates only — actual
      payouts depend on the issuer and may change.
    </p>
  </div>
</template>
