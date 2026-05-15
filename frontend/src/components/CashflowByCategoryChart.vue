<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { categoryMeta } from '@/lib/categories'

interface Point {
  month: string         // 'YYYY-MM'
  category: string | null
  amountMinor: string   // signed (positive = income, negative = expense)
}

const props = withDefaults(
  defineProps<{ months?: number }>(),
  { months: 12 },
)

const rows = ref<Point[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const to = new Date()
    const from = new Date()
    from.setMonth(from.getMonth() - (props.months - 1))
    from.setDate(1)
    const params = new URLSearchParams({
      from: from.toISOString().slice(0, 10),
      to: to.toISOString().slice(0, 10),
    })
    rows.value = await api.get<Point[]>(`/api/cashflow/by-category?${params}`)
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Reshape: list of months, list of categories present, and a value per (month, category).
// Income (positive) and expenses (negative) stack separately so the chart shows both directions.
const option = computed<EChartsOption>(() => {
  if (rows.value.length === 0) {
    return { backgroundColor: 'transparent' }
  }

  // Build month axis: all months between min and max, even empty ones.
  const monthSet = new Set(rows.value.map((r) => r.month))
  const months = [...monthSet].sort()

  // Get all categories actually present.
  const categorySet = new Set<string>()
  for (const r of rows.value) {
    categorySet.add(r.category ?? '__none__')
  }
  const categoryKeys = [...categorySet]

  // Index: monthsCount × categoriesCount.
  // We split each (month, category) cell into a positive bucket (income) and a
  // negative bucket (expense); a single category can have both within a month.
  const data: Record<string, { pos: number; neg: number }> = {}
  for (const r of rows.value) {
    const key = `${r.month}|${r.category ?? '__none__'}`
    const amount = Number(r.amountMinor) / 100
    if (!data[key]) data[key] = { pos: 0, neg: 0 }
    if (amount >= 0) data[key].pos += amount
    else data[key].neg += amount
  }

  // For each category, produce two series: positive stack and negative stack.
  const series: EChartsOption['series'] = []
  for (const key of categoryKeys) {
    const meta = key === '__none__' ? null : categoryMeta(key)
    const label = meta?.label ?? 'Uncategorized'
    const color = meta?.color ?? chartColors.textDim

    const pos = months.map((m) => data[`${m}|${key}`]?.pos ?? 0)
    const neg = months.map((m) => data[`${m}|${key}`]?.neg ?? 0)

    // Only emit a series if it actually has any non-zero values, so the legend
    // doesn't get cluttered with categories the user has none of.
    if (pos.some((v) => v !== 0)) {
      series.push({
        name: label,
        type: 'bar' as const,
        stack: 'income',
        data: pos,
        itemStyle: { color, borderRadius: [2, 2, 0, 0] },
        emphasis: { focus: 'series' },
      })
    }
    if (neg.some((v) => v !== 0)) {
      series.push({
        name: label,
        type: 'bar' as const,
        stack: 'expense',
        data: neg,
        itemStyle: { color, borderRadius: [0, 0, 2, 2] },
        emphasis: { focus: 'series' },
        // Mark the negative-stack series as a continuation of the positive one
        // for legend purposes — duplicate-name series share a legend entry.
      })
    }
  }

  return {
    backgroundColor: 'transparent',
    grid: { top: 50, right: 10, bottom: 30, left: 60, containLabel: true },
    tooltip: {
      trigger: 'axis' as const,
      axisPointer: { type: 'shadow' as const },
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
    },
    legend: {
      top: 4,
      type: 'scroll' as const,
      textStyle: { color: chartColors.textMuted, fontSize: 11 },
      itemWidth: 10,
      itemHeight: 10,
    },
    xAxis: {
      type: 'category' as const,
      data: months,
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
        formatter: (v: number) => {
          const abs = Math.abs(v)
          if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${(v / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    series,
  }
})

const hasData = computed(() => rows.value.length > 0)
</script>

<template>
  <div class="card p-4">
    <div class="flex items-baseline justify-between mb-2">
      <h3 class="text-sm font-medium">Cashflow by category</h3>
      <span class="text-xs text-[var(--color-text-muted)]">Last {{ months }} months</span>
    </div>
    <div v-if="loading" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">Loading…</div>
    <div v-else-if="!hasData" class="h-72 flex items-center justify-center text-[var(--color-text-muted)] text-sm">No data.</div>
    <VChart v-else :option="option" class="w-full" style="height: 18rem" autoresize />
  </div>
</template>
