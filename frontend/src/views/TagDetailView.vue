<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { ChevronLeft, Tag as TagIcon, Inbox } from 'lucide-vue-next'

interface TagTransaction {
  id: string
  accountId: string
  accountName: string
  occurredAt: string
  amountMinor: string
  currency: string
  amountBaseMinor: string
  description: string | null
  type: string
  category: string | null
  tags: string[]
}
interface MonthlyPoint { month: string; amountMinor: string }
interface TagDetail {
  tag: string
  baseCurrency: string
  count: number
  totalSignedMinor: string
  totalSpentMinor: string
  totalIncomeMinor: string
  monthly: MonthlyPoint[]
  transactions: TagTransaction[]
}

const route = useRoute()
const tag = computed(() => decodeURIComponent(String(route.params.tag)))

const data = ref<TagDetail | null>(null)
const loading = ref(false)

async function load() {
  if (!tag.value) return
  loading.value = true
  try {
    data.value = await api.get<TagDetail>(`/api/tags/${encodeURIComponent(tag.value)}`)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(tag, load)

const typeLabels: Record<string, string> = {
  deposit: 'Deposit',
  withdrawal: 'Withdrawal',
  trade_buy: 'Buy',
  trade_sell: 'Sell',
  fee: 'Fee',
  interest: 'Interest',
  dividend: 'Dividend',
  fx_conversion: 'FX',
  other: 'Other',
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const chartOption = computed<EChartsOption>(() => {
  if (!data.value || data.value.monthly.length === 0) return { backgroundColor: 'transparent' }
  const ccy = data.value.baseCurrency
  const months = data.value.monthly.map((m) => m.month)
  // Split signed totals into income (positive) and spending (negative) bars.
  const income = data.value.monthly.map((m) => Math.max(0, Number(m.amountMinor) / 100))
  const spending = data.value.monthly.map((m) => Math.min(0, Number(m.amountMinor) / 100))

  return {
    backgroundColor: 'transparent',
    grid: { top: 30, right: 10, bottom: 30, left: 60, containLabel: true },
    tooltip: {
      trigger: 'axis' as const,
      axisPointer: { type: 'shadow' as const },
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      valueFormatter: (v: unknown) =>
        formatMinor(String(Math.round(Number(v) * 100)), ccy),
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
    series: [
      {
        name: 'Income',
        type: 'bar' as const,
        color: chartColors.positive,
        itemStyle: { color: chartColors.positive, borderRadius: [2, 2, 0, 0] },
        data: income,
      },
      {
        name: 'Spending',
        type: 'bar' as const,
        color: chartColors.negative,
        itemStyle: { color: chartColors.negative, borderRadius: [0, 0, 2, 2] },
        data: spending,
      },
    ],
  }
})

const hasMonthly = computed(() => (data.value?.monthly?.length ?? 0) > 0)
</script>

<template>
  <div class="space-y-8 max-w-5xl mx-auto">
    <RouterLink
      :to="{ name: 'settings' }"
      class="inline-flex items-center gap-1 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <ChevronLeft :size="16" />
      <span>Back to settings</span>
    </RouterLink>

    <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>

    <template v-else-if="data">
      <!-- Header -->
      <header class="space-y-2">
        <p class="label inline-flex items-center gap-1.5">
          <TagIcon :size="12" /> Tag
        </p>
        <h1 class="text-3xl font-semibold tracking-tight">{{ data.tag }}</h1>
      </header>

      <!-- Stats -->
      <section class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
               style="background-color: var(--color-border);">
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Transactions</p>
          <p class="text-2xl font-semibold tracking-tight tabular mt-1">{{ data.count }}</p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Net</p>
          <p
            class="text-2xl font-semibold tracking-tight tabular mt-1"
            :class="BigInt(data.totalSignedMinor) >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
          >
            {{ formatMinor(data.totalSignedMinor, data.baseCurrency) }}
          </p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Spent</p>
          <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-negative)]">
            {{ formatMinor(data.totalSpentMinor, data.baseCurrency) }}
          </p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Received</p>
          <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-positive)]">
            {{ formatMinor(data.totalIncomeMinor, data.baseCurrency) }}
          </p>
        </div>
      </section>

      <!-- Monthly chart -->
      <section v-if="hasMonthly" class="card p-4">
        <h2 class="text-sm font-medium mb-2">Monthly totals ({{ data.baseCurrency }})</h2>
        <VChart :option="chartOption" class="w-full" style="height: 18rem" autoresize />
      </section>

      <!-- Transactions -->
      <section class="space-y-3">
        <h2 class="text-lg font-medium">Transactions</h2>

        <div v-if="data.transactions.length === 0" class="card p-10 text-center space-y-2">
          <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
          <p class="font-medium">No transactions with this tag.</p>
        </div>

        <div v-else class="card overflow-hidden">
          <table class="table">
            <thead>
              <tr>
                <th class="w-28">Date</th>
                <th class="w-28">Type</th>
                <th>Account / description</th>
                <th class="text-right w-32">Amount</th>
                <th class="text-right w-32">In {{ data.baseCurrency }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in data.transactions" :key="t.id">
                <td class="text-[var(--color-text-muted)] tabular">{{ shortDate(t.occurredAt) }}</td>
                <td>
                  <span class="badge">{{ typeLabels[t.type] ?? t.type }}</span>
                </td>
                <td>
                  <RouterLink
                    :to="{ name: 'transaction', params: { accountId: t.accountId, id: t.id } }"
                    class="font-medium hover:text-[var(--color-accent)] transition-colors"
                  >
                    {{ t.description ?? '—' }}
                  </RouterLink>
                  <div class="text-xs text-[var(--color-text-dim)] mt-0.5 flex flex-wrap items-center gap-1.5">
                    <RouterLink
                      :to="{ name: 'account', params: { id: t.accountId } }"
                      class="hover:text-[var(--color-accent)] transition-colors"
                    >
                      {{ t.accountName }}
                    </RouterLink>
                    <span
                      v-if="categoryMeta(t.category)"
                      class="inline-flex items-center gap-1"
                    >
                      <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(t.category)!.color }"></span>
                      <span>{{ categoryMeta(t.category)!.label }}</span>
                    </span>
                  </div>
                </td>
                <td
                  class="text-right tabular font-medium"
                  :class="BigInt(t.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                >
                  {{ formatMinor(t.amountMinor, t.currency) }}
                </td>
                <td
                  class="text-right tabular text-[var(--color-text-muted)]"
                  :class="BigInt(t.amountBaseMinor) < 0n ? 'text-[var(--color-negative)]' : ''"
                >
                  {{ formatMinor(t.amountBaseMinor, data.baseCurrency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Tag not found.</p>
  </div>
</template>
