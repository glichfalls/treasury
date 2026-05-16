<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'

type Range = '1w' | '1m' | '3m' | '6m' | 'ytd' | '1y' | '2y' | '5y' | 'all'
type Metric = 'vsDeposits' | 'twr'

interface Point {
  date: string
  returnVsDepositsPct: number | null
  twrPct: number
}

const props = withDefaults(
  defineProps<{
    endpoint: string
    title?: string
    range?: Range
    granularity?: 'daily' | 'weekly' | 'monthly'
  }>(),
  {
    title: 'Performance',
    range: 'ytd',
  },
)

// Auto-pick granularity from range so short windows don't show only 2 points.
function granularityFor(range: Range): 'daily' | 'weekly' | 'monthly' {
  if (range === '1w' || range === '1m' || range === '3m') return 'daily'
  if (range === '6m' || range === 'ytd' || range === '1y' || range === '2y') return 'weekly'
  return 'monthly'
}

const emit = defineEmits<{ 'update:range': [Range] }>()

const points = ref<Point[]>([])
const loading = ref(false)
// TWR is the right metric for "performance during this window" — it's
// rebased to the window by construction. vsDeposits is a lifetime number;
// useful for "all" range, misleading on short windows.
const metric = ref<Metric>('twr')

function rangeBounds(range: Range): { from: string; to: string } {
  const to = new Date()
  const from = new Date()
  if (range === '1w') from.setDate(from.getDate() - 7)
  else if (range === '1m') from.setMonth(from.getMonth() - 1)
  else if (range === '3m') from.setMonth(from.getMonth() - 3)
  else if (range === '6m') from.setMonth(from.getMonth() - 6)
  else if (range === 'ytd') from.setMonth(0, 1)
  else if (range === '1y') from.setFullYear(from.getFullYear() - 1)
  else if (range === '2y') from.setFullYear(from.getFullYear() - 2)
  else if (range === '5y') from.setFullYear(from.getFullYear() - 5)
  else from.setFullYear(from.getFullYear() - 20)
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(props.range)
    const granularity = props.granularity ?? granularityFor(props.range)
    points.value = await api.get<Point[]>(
      `${props.endpoint}?from=${from}&to=${to}&granularity=${granularity}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.endpoint, props.granularity, props.range], load)

const latest = computed<number | null>(() => {
  if (points.value.length === 0) return null
  const last = points.value[points.value.length - 1]!
  return metric.value === 'twr' ? last.twrPct : last.returnVsDepositsPct
})

function fmtPct(v: number | null): string {
  if (v === null) return '—'
  return `${v >= 0 ? '+' : ''}${v.toFixed(2)}%`
}

type TimePoint = [string, number | null]

// Splits the series into a green "positive" track and a red "negative" track
// on a time axis. When the value crosses zero between two adjacent samples we
// compute the exact zero-crossing time by linear interpolation and insert a
// 0-point at that timestamp into BOTH tracks — so the two coloured lines
// genuinely meet at one (x, 0) point instead of drawing parallel descents
// either side of the crossing.
function splitBySign(dates: string[], values: Array<number | null>): { pos: TimePoint[]; neg: TimePoint[] } {
  const pos: TimePoint[] = []
  const neg: TimePoint[] = []
  for (let i = 0; i < values.length; i++) {
    const v = values[i]!
    const d = dates[i]!
    if (i > 0 && v !== null) {
      const pv = values[i - 1]
      const pd = dates[i - 1]!
      if (pv != null && ((pv >= 0) !== (v >= 0))) {
        const t = pv / (pv - v)
        const prevTs = new Date(pd).getTime()
        const currTs = new Date(d).getTime()
        const crossIso = new Date(prevTs + (currTs - prevTs) * t).toISOString()
        pos.push([crossIso, 0])
        neg.push([crossIso, 0])
      }
    }
    if (v === null) {
      pos.push([d, null])
      neg.push([d, null])
    } else if (v >= 0) {
      pos.push([d, v])
      neg.push([d, null])
    } else {
      pos.push([d, null])
      neg.push([d, v])
    }
  }
  return { pos, neg }
}

const option = computed<EChartsOption>(() => {
  const dates = points.value.map((p) => p.date)
  const values: Array<number | null> = points.value.map((p) =>
    metric.value === 'twr' ? p.twrPct : p.returnVsDepositsPct,
  )
  const defined = values.filter((v): v is number => v !== null)
  const crossesZero = defined.some((v) => v < 0) && defined.some((v) => v > 0)
  const { pos, neg } = splitBySign(dates, values)
  const seriesName = metric.value === 'twr' ? 'TWR' : 'Return vs deposits'

  const trackDefaults = {
    type: 'line' as const,
    smooth: true,
    showSymbol: false,
    connectNulls: false,
    lineStyle: { width: 2 },
  }

  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 30, left: 60, containLabel: true },
    xAxis: {
      type: 'time' as const,
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
    },
    yAxis: {
      type: 'value' as const,
      axisLine: { show: false },
      splitLine: { lineStyle: { color: chartColors.border, opacity: 0.4 } },
      axisLabel: {
        color: chartColors.textMuted,
        fontSize: 11,
        formatter: (v: number) => `${v.toFixed(1)}%`,
      },
    },
    tooltip: {
      trigger: 'axis' as const,
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      // Both tracks fire the axis tooltip; one of them is null at each x.
      // Read the surviving value from the [date, value] tuple and colour the
      // popup to match.
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValueLabel: string; value: [string, number | null] }>
        if (arr.length === 0) return ''
        const hit = arr.find((p) => Array.isArray(p.value) && p.value[1] !== null) ?? arr[0]!
        const v = Array.isArray(hit.value) ? hit.value[1] : null
        const c = v === null
          ? chartColors.textMuted
          : v >= 0 ? chartColors.positive : chartColors.negative
        const label = hit.axisValueLabel
        return `<div style="font-weight:600">${label}</div>` +
          `<div style="color:${c}">● ${fmtPct(v)}</div>`
      },
    },
    series: [
      {
        ...trackDefaults,
        name: seriesName,
        data: pos,
        color: chartColors.positive,
        itemStyle: { color: chartColors.positive },
        lineStyle: { color: chartColors.positive, width: 2 },
        areaStyle: { color: chartColors.positive, opacity: 0.2, origin: 0 },
        markLine: crossesZero ? {
          silent: true,
          symbol: 'none',
          lineStyle: { color: chartColors.border, type: 'dashed', opacity: 0.6 },
          data: [{ yAxis: 0 }],
          label: { show: false },
        } : undefined,
      },
      {
        ...trackDefaults,
        name: seriesName,
        data: neg,
        color: chartColors.negative,
        itemStyle: { color: chartColors.negative },
        lineStyle: { color: chartColors.negative, width: 2 },
        areaStyle: { color: chartColors.negative, opacity: 0.2, origin: 0 },
      },
    ],
  }
})
</script>

<template>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-2">
      <div class="flex items-baseline gap-3">
        <h3 class="text-sm font-medium">{{ title }}</h3>
        <span
          class="text-sm tabular font-medium"
          :class="latest === null ? 'text-[var(--color-text-muted)]'
            : latest >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
        >{{ fmtPct(latest) }}</span>
      </div>
      <div class="flex gap-1">
        <button
          v-for="r in (['1w','1m','3m','6m','ytd','1y','2y','5y','all'] as const)"
          :key="r"
          :class="['text-xs px-2 py-0.5 rounded transition-colors',
            r === range
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
          @click="emit('update:range', r)"
        >{{ r.toUpperCase() }}</button>
      </div>
    </div>
    <div class="flex items-center gap-1 mb-2">
      <button
        v-for="m in (['vsDeposits','twr'] as const)"
        :key="m"
        :class="['text-xs px-2 py-0.5 rounded transition-colors',
          m === metric
            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
            : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
        @click="metric = m"
      >{{ m === 'vsDeposits' ? 'Lifetime return vs deposits' : 'Period return (TWR)' }}</button>
    </div>
    <div v-if="loading" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">Loading…</div>
    <div v-else-if="points.length === 0" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">No data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 18rem" autoresize />
  </div>
</template>
