<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, rangeBounds, type EChartsOption, type Range } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import RangeSelector from '@/components/ui/RangeSelector.vue'

interface PricePoint { date: string; priceMinor: string; currency: string }
interface Response { isin: string; points: PricePoint[] }

const props = defineProps<{ isin: string }>()

const data = ref<Response | null>(null)
const loading = ref(false)
const range = ref<Range>('ytd')

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(range.value)
    data.value = await api.get<Response>(
      `/api/assets/${props.isin}/prices?from=${from}&to=${to}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([() => props.isin, range], load)

const points = computed(() => data.value?.points ?? [])
const empty = computed(() => points.value.length === 0)
const currency = computed(() => points.value[0]?.currency ?? 'USD')

// Direction over the visible window — finance-app convention (green if the price
// ended higher than it started, red if lower). Mirrors NetWorthChart.
const direction = computed(() => {
  const pts = points.value
  if (pts.length < 2) {
    return { line: chartColors.accent, areaTop: 'rgba(250,204,21,0.30)', areaBottom: 'rgba(250,204,21,0.00)' }
  }
  const delta = Number(pts[pts.length - 1]!.priceMinor) - Number(pts[0]!.priceMinor)
  return delta >= 0
    ? { line: chartColors.positive, areaTop: 'rgba(34,197,94,0.30)', areaBottom: 'rgba(34,197,94,0.00)' }
    : { line: chartColors.negative, areaTop: 'rgba(248,113,113,0.30)', areaBottom: 'rgba(248,113,113,0.00)' }
})

// Latest price + change across the window, shown in the card subheader.
const summary = computed(() => {
  const pts = points.value
  if (pts.length === 0) return null
  const last = pts[pts.length - 1]!
  const first = pts[0]!
  const changeMinor = BigInt(last.priceMinor) - BigInt(first.priceMinor)
  const firstNum = Number(first.priceMinor)
  const pct = firstNum !== 0 ? (Number(changeMinor) / firstNum) * 100 : null
  return {
    latestMinor: last.priceMinor,
    changeMinor,
    pct,
    up: changeMinor >= 0n,
  }
})

const option = computed<EChartsOption>(() => {
  const pts = points.value
  if (pts.length === 0) return {}
  const dir = direction.value
  return {
    backgroundColor: 'transparent',
    grid: { top: 20, right: 10, bottom: 30, left: 60, containLabel: true },
    tooltip: {
      trigger: 'axis',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; dataIndex: number }>
        const d = arr[0]
        if (!d) return ''
        const p = pts[d.dataIndex]
        const price = p ? formatMinor(p.priceMinor, p.currency) : ''
        return `<div style="font-weight:600">${d.axisValue}</div><div style="color:${chartColors.textMuted}">${price}</div>`
      },
    },
    xAxis: {
      type: 'category',
      data: pts.map((p) => p.date),
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
      boundaryGap: false,
    },
    yAxis: {
      type: 'value',
      scale: true,
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
    series: [{
      name: 'Price',
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
      data: pts.map((p) => Number(p.priceMinor) / 100),
    }],
  }
})
</script>

<template>
  <ChartCard title="Price" :loading="loading" :empty="empty">
    <template #actions>
      <RangeSelector v-model="range" />
    </template>
    <template #subactions>
      <div v-if="summary" class="flex items-baseline gap-3 text-xs tabular">
        <span class="text-sm font-medium text-[var(--color-text)]">{{ formatMinor(summary.latestMinor, currency) }}</span>
        <span :class="summary.up ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'">
          {{ summary.up ? '+' : '' }}{{ formatMinor(summary.changeMinor.toString(), currency) }}<template v-if="summary.pct !== null"> · {{ summary.up ? '+' : '' }}{{ summary.pct.toFixed(2) }}%</template>
        </span>
        <span class="text-[var(--color-text-dim)]">over range</span>
      </div>
    </template>
    <VChart :option="option" class="w-full" style="height: 18rem" autoresize />
  </ChartCard>
</template>
