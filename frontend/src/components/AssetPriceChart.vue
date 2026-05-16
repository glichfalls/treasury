<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { formatMinor } from '@/lib/money'

interface PricePoint { date: string; priceMinor: string; currency: string }
interface Response { isin: string; points: PricePoint[] }

const props = defineProps<{ isin: string }>()

type Range = '1w' | '1m' | '3m' | '6m' | 'ytd' | '1y' | '2y' | '5y' | 'all'

const data = ref<Response | null>(null)
const loading = ref(false)
const range = ref<Range>('ytd')

async function load() {
  loading.value = true
  try {
    const to = new Date()
    const from = new Date()
    if (range.value === '1w') from.setDate(from.getDate() - 7)
    else if (range.value === '1m') from.setMonth(from.getMonth() - 1)
    else if (range.value === '3m') from.setMonth(from.getMonth() - 3)
    else if (range.value === '6m') from.setMonth(from.getMonth() - 6)
    else if (range.value === 'ytd') from.setMonth(0, 1)
    else if (range.value === '1y') from.setFullYear(from.getFullYear() - 1)
    else if (range.value === '2y') from.setFullYear(from.getFullYear() - 2)
    else if (range.value === '5y') from.setFullYear(from.getFullYear() - 5)
    else from.setFullYear(from.getFullYear() - 20)
    data.value = await api.get<Response>(
      `/api/assets/${props.isin}/prices?from=${from.toISOString().slice(0, 10)}&to=${to.toISOString().slice(0, 10)}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([() => props.isin, range], load)

const option = computed<EChartsOption>(() => {
  if (!data.value || data.value.points.length === 0) return {}
  const currency = data.value.points[0]?.currency ?? 'USD'
  return {
    backgroundColor: 'transparent',
    grid: { top: 20, right: 10, bottom: 40, left: 50, containLabel: true },
    tooltip: {
      trigger: 'axis',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; data: number }>
        const d = arr[0]
        if (!d) return ''
        return `<div style="font-weight:600">${d.axisValue}</div><div style="color:${chartColors.textMuted}">${formatMinor(String(Math.round(d.data * 100)), currency)}</div>`
      },
    },
    xAxis: {
      type: 'category',
      data: data.value.points.map((p) => p.date),
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 10 },
      boundaryGap: false,
    },
    yAxis: {
      type: 'value',
      scale: true,
      axisLine: { show: false },
      splitLine: { lineStyle: { color: chartColors.border, opacity: 0.4 } },
      axisLabel: { color: chartColors.textMuted, fontSize: 10 },
    },
    series: [{
      type: 'line',
      smooth: true,
      showSymbol: false,
      sampling: 'lttb',
      lineStyle: { color: chartColors.accent, width: 2 },
      data: data.value.points.map((p) => Number(p.priceMinor) / 100),
    }],
  }
})
</script>

<template>
  <div>
    <div class="flex justify-end gap-1 mb-1">
      <button
        v-for="r in (['1w','1m','3m','6m','ytd','1y','2y','5y','all'] as const)"
        :key="r"
        :class="['text-xs px-2 py-0.5 rounded transition-colors',
          r === range
            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
            : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
        @click="range = r"
      >{{ r.toUpperCase() }}</button>
    </div>
    <div v-if="loading" class="h-40 flex items-center justify-center text-[var(--color-text-muted)] text-xs">Loading…</div>
    <div v-else-if="!data || data.points.length === 0" class="h-40 flex items-center justify-center text-[var(--color-text-muted)] text-xs">No price data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 10rem" autoresize />
  </div>
</template>
