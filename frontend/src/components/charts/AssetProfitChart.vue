<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import {
  VChart, chartColors, granularityFor, rangeBounds,
  type EChartsOption, type Range,
} from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import RangeSelector from '@/components/ui/RangeSelector.vue'

interface Point {
  date: string
  costBasisMinor: string
  marketValueMinor: string
  dividendsCumMinor: string
  unrealizedPnlMinor: string
  totalReturnMinor: string
}
interface Response {
  isin: string
  baseCurrency: string
  points: Point[]
}

const props = defineProps<{ isin: string }>()

const data = ref<Response | null>(null)
const loading = ref(false)
const range = ref<Range>('1y')

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(range.value)
    const granularity = granularityFor(range.value)
    data.value = await api.get<Response>(
      `/api/assets/${props.isin}/profit-series?from=${from}&to=${to}&granularity=${granularity}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([() => props.isin, range], load)

const currency = computed(() => data.value?.baseCurrency ?? 'CHF')

function fmt(v: number): string {
  const minor = Math.round(Math.abs(v) * 100)
  return formatMinor(v < 0 ? '-' + minor : String(minor), currency.value)
}

const summary = computed(() => {
  const last = data.value?.points[data.value.points.length - 1]
  if (!last) return null
  return {
    unrealized: BigInt(last.unrealizedPnlMinor),
    dividends: BigInt(last.dividendsCumMinor),
    total: BigInt(last.totalReturnMinor),
  }
})

const option = computed<EChartsOption>(() => {
  const points = data.value?.points ?? []
  const dates = points.map((p) => p.date)
  const priceSeries = points.map((p) => Number(p.unrealizedPnlMinor) / 100)
  const totalSeries = points.map((p) => Number(p.totalReturnMinor) / 100)

  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 50, left: 60, containLabel: true },
    legend: {
      top: 4,
      right: 70,
      textStyle: { color: chartColors.textMuted, fontSize: 11 },
      itemWidth: 10,
      itemHeight: 10,
    },
    xAxis: {
      type: 'category',
      data: dates,
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
      boundaryGap: false,
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
          const sign = v < 0 ? '-' : ''
          if (abs >= 1_000_000) return `${sign}${(abs / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${sign}${(abs / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; data: number; seriesName: string; color: string }>
        if (arr.length === 0) return ''
        const lines = arr
          .map((p) => `<div style="color:${p.color}">● ${p.seriesName}: ${fmt(p.data)}</div>`)
          .join('')
        return `<div style="font-weight:600">${arr[0]!.axisValue}</div>${lines}`
      },
    },
    series: [
      {
        name: 'Price P&L',
        type: 'line',
        smooth: true,
        showSymbol: false,
        sampling: 'lttb',
        color: chartColors.accent,
        itemStyle: { color: chartColors.accent },
        lineStyle: { color: chartColors.accent, width: 2 },
        data: priceSeries,
        // Mark zero so up/down is unambiguous.
        markLine: {
          symbol: 'none',
          silent: true,
          lineStyle: { color: chartColors.border, type: 'dashed', width: 1 },
          data: [{ yAxis: 0 }],
          label: { show: false },
        },
        z: 2,
      },
      {
        name: 'Total return (incl. dividends)',
        type: 'line',
        smooth: true,
        showSymbol: false,
        sampling: 'lttb',
        color: chartColors.highlight,
        itemStyle: { color: chartColors.highlight },
        lineStyle: { color: chartColors.highlight, width: 2, type: 'dashed' },
        data: totalSeries,
        z: 1,
      },
    ],
  }
})

const empty = computed(() => !data.value || data.value.points.length === 0)
</script>

<template>
  <ChartCard title="Profit over time" :loading="loading" :empty="empty">
    <template #actions>
      <RangeSelector v-model="range" />
    </template>
    <template #subactions>
      <div v-if="summary" class="flex items-baseline gap-4 text-xs tabular">
        <span
          :class="summary.unrealized >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
        >
          Price P&L {{ formatMinor(summary.unrealized.toString(), currency) }}
        </span>
        <span v-if="summary.dividends !== 0n" class="text-[var(--color-text-muted)]">
          + Dividends {{ formatMinor(summary.dividends.toString(), currency) }}
        </span>
        <span
          :class="summary.total >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
          class="font-medium"
        >
          Total {{ formatMinor(summary.total.toString(), currency) }}
        </span>
      </div>
    </template>
    <VChart :option="option" class="w-full" style="height: 18rem" autoresize />
  </ChartCard>
</template>
