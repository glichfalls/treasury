<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import {
  VChart, chartColors, granularityFor, rangeBounds,
  type EChartsOption, type Granularity, type Range,
} from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import RangeSelector from '@/components/ui/RangeSelector.vue'

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
    granularity?: Granularity
    range?: Range
    title?: string
    // Single line of total value (default), stacked cash+holdings, or value plus net deposits.
    mode?: 'total' | 'stacked' | 'vs-deposits'
    // When true, line color tracks direction (green up / red down). Disable for
    // cash-style accounts where "going down" is just spending, not a loss.
    directionColoring?: boolean
  }>(),
  {
    currency: 'CHF',
    range: 'ytd',
    title: 'Net worth',
    mode: 'total',
    directionColoring: true,
  },
)

const emit = defineEmits<{ 'update:range': [Range] }>()

const points = ref<Point[]>([])
const loading = ref(false)

// Yellow accent + matching area gradient. Used when direction coloring is off
// (bank accounts etc.) and as the fallback for direction coloring when there's
// not enough data to compute a delta.
const ACCENT_FILL = {
  line: chartColors.accent,
  areaTop: 'rgba(250,204,21,0.35)',
  areaBottom: 'rgba(250,204,21,0.00)',
}

// Whole-line color based on the direction over the visible window.
// Finance-app convention (Robinhood/Google Finance): green if you're up vs. the
// start of the range, red if down.
function directionColor(values: number[]): { line: string; areaTop: string; areaBottom: string } {
  if (values.length < 2) return ACCENT_FILL
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

function fillFor(values: number[]): { line: string; areaTop: string; areaBottom: string } {
  return props.directionColoring ? directionColor(values) : ACCENT_FILL
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
          showSymbol: false,
          sampling: 'lttb',
          // Setting `color` (and matching itemStyle) gives ECharts the right
          // legend-marker color. Without these, ECharts falls back to its
          // built-in palette (blue) for the marker, even though the area is yellow.
          color: '#facc15',
          itemStyle: { color: '#facc15' },
          lineStyle: { width: 0 },
          areaStyle: { color: 'rgba(250,204,21,0.45)' },
          data: points.value.map((p) => Number(p.cashMinor) / 100),
        },
        {
          name: 'Holdings',
          type: 'line',
          stack: 'total',
          showSymbol: false,
          sampling: 'lttb',
          color: '#a78bfa',
          itemStyle: { color: '#a78bfa' },
          lineStyle: { width: 0 },
          areaStyle: { color: 'rgba(167,139,250,0.45)' },
          data: points.value.map((p) => Number(p.holdingsMinor) / 100),
        },
      ],
    }
  }

  if (props.mode === 'vs-deposits') {
    const totals = points.value.map((p) => Number(p.totalMinor) / 100)
    const dir = fillFor(totals)
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
          showSymbol: false,
          sampling: 'lttb',
          // `color` controls the legend marker; ECharts ignores lineStyle.color for it.
          color: dir.line,
          itemStyle: { color: dir.line },
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
          showSymbol: false,
          sampling: 'lttb',
          color: chartColors.textMuted,
          itemStyle: { color: chartColors.textMuted },
          lineStyle: { color: chartColors.textMuted, width: 1.5, type: 'dashed' },
          data: points.value.map((p) => Number(p.netDepositsMinor ?? '0') / 100),
          z: 1,
        },
      ],
    }
  }

  const totals = points.value.map((p) => Number(p.totalMinor) / 100)
  const dir = fillFor(totals)
  return {
    ...baseAxisStyle,
    series: [{
      name: props.title,
      type: 'line',
      showSymbol: false,
      sampling: 'lttb',
      color: dir.line,
      itemStyle: { color: dir.line },
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
  <ChartCard :title="title" :loading="loading" :empty="points.length === 0">
    <template #actions>
      <RangeSelector :model-value="range" @update:model-value="emit('update:range', $event)" />
    </template>
    <VChart :option="option" class="w-full" style="height: 18rem" autoresize />
  </ChartCard>
</template>
