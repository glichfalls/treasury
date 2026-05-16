<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, rangeBounds, type EChartsOption, type Range } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
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

const empty = computed(() => !data.value || data.value.points.length === 0)
</script>

<template>
  <div>
    <div class="flex justify-end gap-1 mb-1">
      <RangeSelector v-model="range" />
    </div>
    <div v-if="loading" class="h-40 flex items-center justify-center text-[var(--color-text-muted)] text-xs">Loading…</div>
    <div v-else-if="empty" class="h-40 flex items-center justify-center text-[var(--color-text-muted)] text-xs">No price data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 10rem" autoresize />
  </div>
</template>
