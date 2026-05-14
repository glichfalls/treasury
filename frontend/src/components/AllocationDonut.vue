<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { formatMinor } from '@/lib/money'

interface Slice {
  label: string
  isin: string | null
  valueBaseMinor: string
}
interface AllocationResponse {
  baseCurrency: string
  slices: Slice[]
}

const props = defineProps<{ endpoint: string }>()

const data = ref<AllocationResponse | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.get<AllocationResponse>(props.endpoint)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.endpoint, load)

const option = computed<EChartsOption>(() => {
  if (!data.value) return {}
  const total = data.value.slices.reduce((s, x) => s + BigInt(x.valueBaseMinor), 0n)
  const currency = data.value.baseCurrency
  const slices = [...data.value.slices].sort(
    (a, b) => Number(BigInt(b.valueBaseMinor) - BigInt(a.valueBaseMinor)),
  )

  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'item',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (p: unknown) => {
        const param = p as { name: string; value: number; percent: number }
        const minor = String(Math.round(param.value * 100))
        return `<div style="font-weight:600">${param.name}</div>
                <div style="color:${chartColors.textMuted}">${formatMinor(minor, currency)} · ${param.percent.toFixed(1)}%</div>`
      },
    },
    legend: {
      orient: 'vertical',
      right: 0,
      top: 'middle',
      textStyle: { color: chartColors.textMuted, fontSize: 12 },
      itemWidth: 10,
      itemHeight: 10,
    },
    series: [{
      type: 'pie',
      radius: ['55%', '80%'],
      center: ['35%', '50%'],
      avoidLabelOverlap: true,
      itemStyle: { borderRadius: 4, borderColor: chartColors.bg, borderWidth: 2 },
      label: { show: false },
      data: slices.map((s, i) => ({
        name: s.label,
        value: Number(s.valueBaseMinor) / 100,
        itemStyle: { color: chartColors.palette[i % chartColors.palette.length] },
      })),
    }],
  }
})

const totalFormatted = computed(() => {
  if (!data.value) return ''
  const total = data.value.slices.reduce((s, x) => s + BigInt(x.valueBaseMinor), 0n)
  return formatMinor(total.toString(), data.value.baseCurrency)
})
</script>

<template>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-2">
      <h3 class="text-sm font-medium">Allocation</h3>
      <span class="text-xs text-[var(--color-text-muted)] tabular">{{ totalFormatted }}</span>
    </div>
    <div v-if="loading" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">Loading…</div>
    <div v-else-if="!data || data.slices.length === 0" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">No holdings.</div>
    <VChart v-else :option="option" class="w-full" style="height: 18rem" autoresize />
  </div>
</template>
