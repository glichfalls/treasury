<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, granularityFor, rangeBounds, type EChartsOption, type Range } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'

interface Snapshot { currency: string; valueNativeMinor: string; valueBaseMinor: string }
interface FxGain {
  currency: string
  exposureNativeMinor: string
  exposureBaseMinor: string
  acquiredNativeMinor: string
  committedCostBaseMinor: string
  avgFxRate: number | null
  todayFxRate: number | null
  fxPnlBaseMinor: string
}
interface HistorySeries { currency: string; values: string[] }
interface History { dates: string[]; series: HistorySeries[] }
interface Response {
  baseCurrency: string
  snapshot: Snapshot[]
  fxGain: FxGain[]
  history: History
}

const props = defineProps<{ range: Range }>()

const data = ref<Response | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(props.range)
    const granularity = granularityFor(props.range)
    data.value = await api.get<Response>(
      `/api/insights/currencies?from=${from}&to=${to}&granularity=${granularity}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.range, load)

const currency = computed(() => data.value?.baseCurrency ?? 'CHF')

const totalBaseMinor = computed(() => {
  if (!data.value) return 0n
  return data.value.snapshot.reduce((s, x) => s + BigInt(x.valueBaseMinor), 0n)
})

function colorFor(i: number): string {
  return chartColors.palette[i % chartColors.palette.length]!
}

const pieOption = computed<EChartsOption>(() => {
  const slices = data.value?.snapshot ?? []
  const byName = new Map(slices.map((s) => [s.currency, s]))
  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'item',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (p: unknown) => {
        const param = p as { name: string; value: number; percent: number }
        const s = byName.get(param.name)
        const native = s ? formatMinor(s.valueNativeMinor, s.currency) : ''
        const base = formatMinor(String(Math.round(param.value * 100)), currency.value)
        return `<div style="font-weight:600">${param.name}</div>` +
          (native ? `<div>${native}</div>` : '') +
          `<div style="color:${chartColors.textMuted}">${base} · ${param.percent.toFixed(1)}%</div>`
      },
    },
    series: [{
      type: 'pie',
      radius: ['55%', '80%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: true,
      itemStyle: { borderRadius: 4, borderColor: chartColors.bg, borderWidth: 2 },
      label: { show: false },
      data: slices.map((s, i) => ({
        name: s.currency,
        // Slice size is sorted by base value (so currencies are comparable visually).
        value: Number(s.valueBaseMinor) / 100,
        itemStyle: { color: colorFor(i) },
      })),
    }],
  }
})

const historyOption = computed<EChartsOption>(() => {
  const h = data.value?.history
  if (!h || h.dates.length === 0) return {}
  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 50, left: 60, containLabel: true },
    legend: {
      top: 4,
      right: 10,
      textStyle: { color: chartColors.textMuted, fontSize: 11 },
      itemWidth: 10,
      itemHeight: 10,
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
          .filter((p) => p.data !== 0)
          .map((p) => `<div style="color:${p.color}">● ${p.seriesName}: ${formatMinor(String(Math.round(p.data * 100)), currency.value)}</div>`)
          .join('')
        return `<div style="font-weight:600">${arr[0]!.axisValue}</div>${lines}`
      },
    },
    xAxis: {
      type: 'category',
      data: h.dates,
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
          if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${(v / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    series: h.series.map((s, i) => ({
      name: s.currency,
      type: 'line',
      stack: 'total',
      smooth: true,
      showSymbol: false,
      sampling: 'lttb',
      color: colorFor(i),
      itemStyle: { color: colorFor(i) },
      lineStyle: { width: 0 },
      areaStyle: { color: colorFor(i), opacity: 0.7 },
      data: s.values.map((v) => Number(v) / 100),
    })),
  }
})

function pctOf(minor: string): number {
  if (totalBaseMinor.value === 0n) return 0
  return (Number(minor) / Number(totalBaseMinor.value)) * 100
}
</script>

<template>
  <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>
  <div v-else-if="!data || data.snapshot.length === 0" class="text-[var(--color-text-muted)]">
    No currency exposure yet.
  </div>
  <div v-else class="space-y-4">
    <ChartCard title="Currency exposure">
      <template #actions>
        <MoneyDisplay :minor="totalBaseMinor.toString()" :currency="currency" sensitive class="text-xs text-[var(--color-text-muted)] tabular" />
      </template>
      <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-4 items-center">
        <VChart :option="pieOption" style="height: 18rem" autoresize />
        <ul class="space-y-1.5 text-sm overflow-y-auto" style="max-height: 18rem">
          <li v-for="(s, i) in data.snapshot" :key="s.currency" class="flex items-center gap-2">
            <span
              class="w-2.5 h-2.5 rounded-sm shrink-0"
              :style="{ backgroundColor: colorFor(i) }"
            />
            <span class="w-12 font-medium shrink-0">{{ s.currency }}</span>
            <div class="flex-1 min-w-0 text-right">
              <div class="tabular"><MoneyDisplay :minor="s.valueNativeMinor" :currency="s.currency" sensitive /></div>
              <div class="tabular text-xs text-[var(--color-text-dim)]">
                <MoneyDisplay :minor="s.valueBaseMinor" :currency="currency" sensitive />
              </div>
            </div>
            <span class="tabular text-[var(--color-text-muted)] w-14 text-right shrink-0">
              {{ pctOf(s.valueBaseMinor).toFixed(1) }}%
            </span>
          </li>
        </ul>
      </div>
    </ChartCard>

    <ChartCard title="FX P&amp;L per currency">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs text-[var(--color-text-muted)] text-left">
              <th class="pb-2 font-normal">Currency</th>
              <th class="pb-2 font-normal text-right">Exposure (native)</th>
              <th class="pb-2 font-normal text-right">Exposure (in {{ currency }})</th>
              <th class="pb-2 font-normal text-right">Avg buy FX</th>
              <th class="pb-2 font-normal text-right">Today's FX</th>
              <th class="pb-2 font-normal text-right">FX P&amp;L</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in data.fxGain"
              :key="row.currency"
              class="border-t"
              style="border-color: var(--color-border);"
            >
              <td class="py-2 font-medium">{{ row.currency }}</td>
              <td class="py-2 text-right tabular text-[var(--color-text-muted)]">
                <MoneyDisplay :minor="row.exposureNativeMinor" :currency="row.currency" sensitive />
              </td>
              <td class="py-2 text-right tabular">
                <MoneyDisplay :minor="row.exposureBaseMinor" :currency="currency" sensitive />
              </td>
              <td
                v-if="row.currency === currency || row.avgFxRate === null"
                class="py-2 text-right tabular text-[var(--color-text-dim)]"
              >—</td>
              <td v-else class="py-2 text-right tabular text-[var(--color-text-muted)]">
                {{ row.avgFxRate.toFixed(4) }}
              </td>
              <td
                v-if="row.currency === currency || row.todayFxRate === null"
                class="py-2 text-right tabular text-[var(--color-text-dim)]"
              >—</td>
              <td v-else class="py-2 text-right tabular text-[var(--color-text-muted)]">
                {{ row.todayFxRate.toFixed(4) }}
              </td>
              <td
                v-if="row.currency === currency"
                class="py-2 text-right tabular text-[var(--color-text-dim)]"
              >—</td>
              <td
                v-else
                class="py-2 text-right tabular font-medium"
                :class="BigInt(row.fxPnlBaseMinor) >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
              >
                <MoneyDisplay :minor="row.fxPnlBaseMinor" :currency="currency" sensitive />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-xs text-[var(--color-text-dim)]">
        Exposure = cash + holdings priced in this currency, valued at today's native price.
        Avg buy FX = weighted-average {{ currency }} actually paid per unit of native, across all
        acquisitions (deposits, dividends, FX conversions, and the cash leg of cross-currency stock
        buys). FX P&amp;L = current exposure × (today's FX − avg buy FX), so stock appreciation
        in a strong currency amplifies the FX gain.
      </p>
    </ChartCard>

    <ChartCard title="Exposure over time" :empty="!data.history || data.history.dates.length === 0">
      <VChart :option="historyOption" class="w-full" style="height: 22rem" autoresize />
    </ChartCard>
  </div>
</template>
