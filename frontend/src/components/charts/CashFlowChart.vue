<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'

interface Point { month: string; incomeMinor: string; expenseMinor: string }

const props = withDefaults(defineProps<{ months?: number; currency?: string }>(), {
  months: 12,
  currency: 'CHF',
})

const points = ref<Point[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const to = new Date()
    const from = new Date()
    from.setMonth(from.getMonth() - props.months + 1)
    points.value = await api.get<Point[]>(
      `/api/cashflow?from=${from.toISOString().slice(0, 10)}&to=${to.toISOString().slice(0, 10)}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.months, load)

const summary = computed(() => {
  let income = 0n
  let expense = 0n
  for (const p of points.value) {
    income += BigInt(p.incomeMinor)
    expense += BigInt(p.expenseMinor)
  }
  const net = income + expense
  return { income, expense, net }
})

const option = computed<EChartsOption>(() => ({
  backgroundColor: 'transparent',
  grid: { top: 30, right: 10, bottom: 30, left: 60, containLabel: true },
  tooltip: {
    trigger: 'axis',
    axisPointer: { type: 'shadow' },
    backgroundColor: chartColors.surface,
    borderColor: chartColors.border,
    textStyle: { color: chartColors.text },
    formatter: (params: unknown) => {
      const arr = params as Array<{ axisValue: string; data: number; seriesName: string; color: string }>
      if (arr.length === 0) return ''
      const label = arr[0]!.axisValue
      const lines = arr
        .map((p) => {
          const minor = String(Math.round(Math.abs(p.data) * 100))
          const formatted = formatMinor(p.data < 0 ? '-' + minor : minor, props.currency)
          return `<div style="color:${p.color}">● ${p.seriesName}: ${formatted}</div>`
        })
        .join('')
      return `<div style="font-weight:600">${label}</div>${lines}`
    },
  },
  xAxis: {
    type: 'category',
    data: points.value.map((p) => p.month),
    axisLine: { lineStyle: { color: chartColors.border } },
    axisLabel: { color: chartColors.textMuted, fontSize: 11 },
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
  series: [
    {
      name: 'Income',
      type: 'bar',
      stack: 'total',
      itemStyle: { color: chartColors.positive, borderRadius: [2, 2, 0, 0] },
      data: points.value.map((p) => Number(p.incomeMinor) / 100),
    },
    {
      name: 'Spending',
      type: 'bar',
      stack: 'total',
      itemStyle: { color: chartColors.negative, borderRadius: [0, 0, 2, 2] },
      data: points.value.map((p) => Number(p.expenseMinor) / 100),
    },
  ],
}))
</script>

<template>
  <ChartCard title="Cash flow" :loading="loading" :empty="points.length === 0">
    <template #actions>
      <div class="flex items-baseline gap-4 text-xs tabular">
        <span class="text-[var(--color-positive)]">+<MoneyDisplay :minor="summary.income.toString()" :currency="currency" sensitive /></span>
        <MoneyDisplay :minor="summary.expense.toString()" :currency="currency" sensitive class="text-[var(--color-negative)]" />
        <span
          :class="summary.net < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-text)]'"
          class="font-medium"
        >Net <MoneyDisplay :minor="summary.net.toString()" :currency="currency" sensitive /></span>
      </div>
    </template>
    <VChart :option="option" class="w-full" style="height: 18rem" autoresize />
  </ChartCard>
</template>
