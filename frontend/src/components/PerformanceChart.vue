<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'

type Range = '1w' | '1m' | '6mo' | '1y' | '2y' | '5y' | 'all'
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
    range: '1y',
    granularity: 'weekly',
  },
)

const emit = defineEmits<{ 'update:range': [Range] }>()

const points = ref<Point[]>([])
const loading = ref(false)
const metric = ref<Metric>('vsDeposits')

function rangeBounds(range: Range): { from: string; to: string } {
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

const latest = computed<number | null>(() => {
  if (points.value.length === 0) return null
  const last = points.value[points.value.length - 1]!
  return metric.value === 'twr' ? last.twrPct : last.returnVsDepositsPct
})

function directionColor(values: Array<number | null>): { line: string; areaTop: string; areaBottom: string } {
  const defined = values.filter((v): v is number => v !== null)
  if (defined.length < 2) {
    return {
      line: chartColors.accent,
      areaTop: 'rgba(99,102,241,0.35)',
      areaBottom: 'rgba(99,102,241,0.00)',
    }
  }
  const delta = defined[defined.length - 1]! - defined[0]!
  if (delta >= 0) {
    return {
      line: chartColors.positive,
      areaTop: 'rgba(16,185,129,0.35)',
      areaBottom: 'rgba(16,185,129,0.00)',
    }
  }
  return {
    line: chartColors.negative,
    areaTop: 'rgba(239,68,68,0.35)',
    areaBottom: 'rgba(239,68,68,0.00)',
  }
}

function fmtPct(v: number | null): string {
  if (v === null) return '—'
  return `${v >= 0 ? '+' : ''}${v.toFixed(2)}%`
}

const option = computed<EChartsOption>(() => {
  const dates = points.value.map((p) => p.date)
  const values: Array<number | null> = points.value.map((p) =>
    metric.value === 'twr' ? p.twrPct : p.returnVsDepositsPct,
  )
  const dir = directionColor(values)

  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 30, left: 60, containLabel: true },
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
        formatter: (v: number) => `${v.toFixed(1)}%`,
      },
    },
    tooltip: {
      trigger: 'axis' as const,
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; data: number | null; color: string }>
        if (arr.length === 0) return ''
        const p = arr[0]!
        return `<div style="font-weight:600">${p.axisValue}</div>` +
          `<div style="color:${p.color}">● ${fmtPct(p.data)}</div>`
      },
    },
    series: [{
      name: metric.value === 'twr' ? 'TWR' : 'Return vs deposits',
      type: 'line',
      smooth: true,
      showSymbol: false,
      sampling: 'lttb',
      connectNulls: false,
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
      data: values,
    }],
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
          v-for="r in (['1w','1m','6mo','1y','2y','5y','all'] as const)"
          :key="r"
          :class="['text-xs px-2 py-0.5 rounded transition-colors',
            r === range
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
          @click="emit('update:range', r)"
        >{{ r }}</button>
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
      >{{ m === 'vsDeposits' ? 'Return vs deposits' : 'Time-weighted return' }}</button>
    </div>
    <div v-if="loading" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">Loading…</div>
    <div v-else-if="points.length === 0" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">No data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 18rem" autoresize />
  </div>
</template>
