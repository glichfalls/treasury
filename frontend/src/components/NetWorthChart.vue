<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { formatMinor } from '@/lib/money'

interface Point {
  date: string
  cashMinor: string
  holdingsMinor: string
  totalMinor: string
  netDepositsMinor?: string
}

const props = withDefaults(
  defineProps<{
    endpoint: string
    currency?: string
    granularity?: 'daily' | 'weekly' | 'monthly'
    range?: '1w' | '1m' | '6mo' | '1y' | '2y' | '5y' | 'all'
    title?: string
    // Single line of total value (default), stacked cash+holdings, or value plus net deposits.
    mode?: 'total' | 'stacked' | 'vs-deposits'
  }>(),
  {
    currency: 'CHF',
    granularity: 'weekly',
    range: '2y',
    title: 'Net worth',
    mode: 'total',
  },
)

const points = ref<Point[]>([])
const loading = ref(false)

function rangeBounds(range: string): { from: string; to: string } {
  const to = new Date()
  const from = new Date()
  if (range === '1w') from.setDate(from.getDate() - 7)
  else if (range === '1m') from.setMonth(from.getMonth() - 1)
  else if (range === '6mo') from.setMonth(from.getMonth() - 6)
  else if (range === '1y') from.setFullYear(from.getFullYear() - 1)
  else if (range === '2y') from.setFullYear(from.getFullYear() - 2)
  else if (range === '5y') from.setFullYear(from.getFullYear() - 5)
  else from.setFullYear(from.getFullYear() - 20)
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}

// Whole-line color based on the direction over the visible window.
// Matches finance-app convention (Robinhood/Google Finance): green if you're up
// vs. the start of the range, red if down. Falls back to accent when there's
// not enough data to compute a delta.
function directionColor(values: number[]): { line: string; areaTop: string; areaBottom: string } {
  if (values.length < 2) {
    return {
      line: chartColors.accent,
      areaTop: 'rgba(250,204,21,0.35)',
      areaBottom: 'rgba(250,204,21,0.00)',
    }
  }
  const delta = values[values.length - 1]! - values[0]!
  if (delta >= 0) {
    return {
      line: chartColors.positive,
      areaTop: 'rgba(34,197,94,0.35)',
      areaBottom: 'rgba(34,197,94,0.00)',
    }
  }
  return {
    line: chartColors.negative,
    areaTop: 'rgba(248,113,113,0.35)',
    areaBottom: 'rgba(248,113,113,0.00)',
  }
}

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(props.range)
    points.value = await api.get<Point[]>(
      `${props.endpoint}?from=${from}&to=${to}&granularity=${props.granularity}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.endpoint, props.granularity, props.range], load)

function fmt(v: number): string {
  return formatMinor(String(Math.round(v * 100)), props.currency)
}

const option = computed<EChartsOption>(() => {
  const dates = points.value.map((p) => p.date)

  const baseAxisStyle = {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 50, left: 60, containLabel: true },
    xAxis: {
      type: 'category' as const,
      data: dates,
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
      boundaryGap: false,
    },
    yAxis: {
      type: 'value' as const,
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
    tooltip: {
      trigger: 'axis' as const,
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
  }

  if (props.mode === 'stacked') {
    return {
      ...baseAxisStyle,
      legend: {
        top: 4,
        right: 70,
        textStyle: { color: chartColors.textMuted, fontSize: 11 },
        itemWidth: 10,
        itemHeight: 10,
      },
      series: [
        {
          name: 'Cash',
          type: 'line',
          stack: 'total',
          smooth: true,
          showSymbol: false,
          sampling: 'lttb',
          lineStyle: { width: 0 },
          areaStyle: { color: 'rgba(250,204,21,0.45)' },
          data: points.value.map((p) => Number(p.cashMinor) / 100),
        },
        {
          name: 'Holdings',
          type: 'line',
          stack: 'total',
          smooth: true,
          showSymbol: false,
          sampling: 'lttb',
          lineStyle: { width: 0 },
          areaStyle: { color: 'rgba(167,139,250,0.45)' },
          data: points.value.map((p) => Number(p.holdingsMinor) / 100),
        },
      ],
    }
  }

  if (props.mode === 'vs-deposits') {
    const totals = points.value.map((p) => Number(p.totalMinor) / 100)
    const dir = directionColor(totals)
    return {
      ...baseAxisStyle,
      legend: {
        top: 4,
        right: 70,
        textStyle: { color: chartColors.textMuted, fontSize: 11 },
        itemWidth: 10,
        itemHeight: 10,
      },
      series: [
        {
          name: 'Account value',
          type: 'line',
          smooth: true,
          showSymbol: false,
          sampling: 'lttb',
          lineStyle: { color: dir.line, width: 2 },
          areaStyle: {
            color: {
              type: 'linear',
              x: 0, y: 0, x2: 0, y2: 1,
              colorStops: [
                { offset: 0, color: dir.areaTop },
                { offset: 1, color: dir.areaBottom },
              ],
            },
          },
          data: totals,
          z: 2,
        },
        {
          name: 'Net deposits',
          type: 'line',
          smooth: true,
          showSymbol: false,
          sampling: 'lttb',
          lineStyle: { color: chartColors.textMuted, width: 1.5, type: 'dashed' },
          data: points.value.map((p) => Number(p.netDepositsMinor ?? '0') / 100),
          z: 1,
        },
      ],
    }
  }

  // default: single area
  const totals = points.value.map((p) => Number(p.totalMinor) / 100)
  const dir = directionColor(totals)
  return {
    ...baseAxisStyle,
    series: [{
      name: props.title,
      type: 'line',
      smooth: true,
      showSymbol: false,
      sampling: 'lttb',
      lineStyle: { color: dir.line, width: 2 },
      areaStyle: {
        color: {
          type: 'linear',
          x: 0, y: 0, x2: 0, y2: 1,
          colorStops: [
            { offset: 0, color: dir.areaTop },
            { offset: 1, color: dir.areaBottom },
          ],
        },
      },
      data: totals,
    }],
  }
})
</script>

<template>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-2">
      <h3 class="text-sm font-medium">{{ title }}</h3>
      <div class="flex gap-1">
        <button
          v-for="r in (['1w','1m','6mo','1y','2y','5y','all'] as const)"
          :key="r"
          :class="['text-xs px-2 py-0.5 rounded transition-colors',
            r === range
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
          @click="$emit('update:range', r)"
        >{{ r }}</button>
      </div>
    </div>
    <div v-if="loading" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">Loading…</div>
    <div v-else-if="points.length === 0" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">No data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 18rem" autoresize />
  </div>
</template>
