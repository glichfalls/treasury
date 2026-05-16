<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { VChart, chartColors, rangeBounds, type EChartsOption, type Range } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'

interface MonthlyPoint { month: string; amountBaseMinor: string }
interface ByAccountRow {
  accountId: string
  accountName: string
  amountBaseMinor: string
  count: number
}
interface Response {
  monthly: MonthlyPoint[]
  byAccount: ByAccountRow[]
  totalBaseMinor: string
  ytdBaseMinor: string
  lifetimeBaseMinor: string
  baseCurrency: string
}

const props = defineProps<{ range: Range }>()

const data = ref<Response | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { from, to } = rangeBounds(props.range)
    data.value = await api.get<Response>(
      `/api/insights/fees?from=${from}&to=${to}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.range, load)

const currency = computed(() => data.value?.baseCurrency ?? 'CHF')

// Fees are stored as negative amounts (money out). Display as positive bars
// in negative color — clearer than negative bars going downward.
const chartOption = computed<EChartsOption>(() => {
  const months = data.value?.monthly ?? []
  return {
    backgroundColor: 'transparent',
    grid: { top: 20, right: 10, bottom: 40, left: 60, containLabel: true },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (params: unknown) => {
        const arr = params as Array<{ axisValue: string; data: number; color: string }>
        if (arr.length === 0) return ''
        const p = arr[0]!
        return `<div style="font-weight:600">${p.axisValue}</div>` +
          `<div style="color:${p.color}">${formatMinor(String(Math.round(Math.abs(p.data) * 100)), currency.value)}</div>`
      },
    },
    xAxis: {
      type: 'category',
      data: months.map((m) => m.month),
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
          if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${(v / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    series: [{
      type: 'bar',
      itemStyle: { color: chartColors.negative, borderRadius: [2, 2, 0, 0] },
      data: months.map((m) => Math.abs(Number(m.amountBaseMinor) / 100)),
    }],
  }
})
</script>

<template>
  <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>
  <div v-else-if="!data" class="text-[var(--color-text-muted)]">No data.</div>
  <div v-else class="space-y-4">
    <!-- Totals strip -->
    <div class="grid grid-cols-3 gap-px overflow-hidden rounded-lg"
         style="background-color: var(--color-border);">
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Range total</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-negative)]">
          {{ formatMinor(data.totalBaseMinor, currency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">YTD</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-negative)]">
          {{ formatMinor(data.ytdBaseMinor, currency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Lifetime</p>
        <p class="text-lg font-medium tabular mt-1 text-[var(--color-negative)]">
          {{ formatMinor(data.lifetimeBaseMinor, currency) }}
        </p>
      </div>
    </div>

    <ChartCard title="Fees paid by month" :empty="data.monthly.length === 0">
      <VChart :option="chartOption" class="w-full" style="height: 18rem" autoresize />
    </ChartCard>

    <ChartCard title="By account" :empty="data.byAccount.length === 0" empty-text="No fees in this range.">
      <div class="space-y-2 text-sm">
        <div class="flex items-center justify-between text-xs text-[var(--color-text-muted)] pb-1 border-b" style="border-color: var(--color-border);">
          <span class="flex-1">Account</span>
          <span class="w-16 text-right">Count</span>
          <span class="w-28 text-right">Total</span>
        </div>
        <div
          v-for="row in data.byAccount"
          :key="row.accountId"
          class="flex items-center justify-between"
        >
          <RouterLink
            :to="{ name: 'account', params: { id: row.accountId } }"
            class="flex-1 font-medium hover:text-[var(--color-accent)] transition-colors truncate"
          >
            {{ row.accountName }}
          </RouterLink>
          <span class="w-16 text-right tabular text-[var(--color-text-muted)]">{{ row.count }}</span>
          <span class="w-28 text-right tabular text-[var(--color-negative)] font-medium">
            {{ formatMinor(row.amountBaseMinor, currency) }}
          </span>
        </div>
      </div>
    </ChartCard>
  </div>
</template>
