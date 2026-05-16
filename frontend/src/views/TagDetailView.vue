<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import DateField from '@/components/ui/DateField.vue'
import DataTable from '@/components/ui/DataTable.vue'
import type { ColumnDef, SortingState } from '@tanstack/vue-table'
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
interface TransactionsPage {
  items: TagTransaction[]
  total: number
  page: number
  pageSize: number
}
interface TagDetail {
  tag: string
  baseCurrency: string
  count: number
  totalSignedMinor: string
  totalSpentMinor: string
  totalIncomeMinor: string
  monthly: MonthlyPoint[]
  transactions: TransactionsPage
}

const route = useRoute()
const tag = computed(() => decodeURIComponent(String(route.params.tag)))

const data = ref<TagDetail | null>(null)
const loading = ref(false)

// Filter / pagination / sort state (server-side).
const filterQ = ref('')
const filterType = ref('')
const filterFrom = ref('')
const filterTo = ref('')
const page = ref(1)
const pageSize = ref(25)
const sorting = ref<SortingState>([{ id: 'occurredAt', desc: true }])
const sortParam = computed(() => {
  const s = sorting.value[0]
  return s ? `${s.id}:${s.desc ? 'desc' : 'asc'}` : undefined
})

const activeFilterColumns = computed(() => {
  const ids: string[] = []
  if (filterFrom.value !== '' || filterTo.value !== '') ids.push('occurredAt')
  if (filterType.value !== '') ids.push('type')
  if (filterQ.value !== '') ids.push('description')
  return ids
})
const hasFilters = computed(() => activeFilterColumns.value.length > 0)

function clearFilters() {
  filterQ.value = ''
  filterType.value = ''
  filterFrom.value = ''
  filterTo.value = ''
  page.value = 1
  void load()
}

async function load() {
  if (!tag.value) return
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (filterQ.value) params.set('q', filterQ.value)
    if (filterType.value) params.set('type', filterType.value)
    if (filterFrom.value) params.set('from', filterFrom.value)
    if (filterTo.value) params.set('to', filterTo.value)
    if (sortParam.value) params.set('sort', sortParam.value)
    params.set('page', String(page.value))
    params.set('pageSize', String(pageSize.value))
    data.value = await api.get<TagDetail>(`/api/tags/${encodeURIComponent(tag.value)}?${params.toString()}`)
  } finally {
    loading.value = false
  }
}

// Debounced reload — same shape as AccountView.
let filterTimer: ReturnType<typeof setTimeout> | null = null
function onFilterChange() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 400)
}

onMounted(load)
watch(tag, load)

const typeLabels: Record<string, string> = {
  deposit: 'Deposit',
  opening_balance: 'Opening balance',
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

const transactionColumns = computed<ColumnDef<TagTransaction, unknown>[]>(() => [
  {
    id: 'occurredAt',
    accessorKey: 'occurredAt',
    header: 'Date',
    enableSorting: true,
    meta: { headerClass: 'w-28', cellClass: 'text-[var(--color-text-muted)] tabular' },
  },
  {
    id: 'type',
    accessorFn: (t) => typeLabels[t.type] ?? t.type,
    header: 'Type',
    enableSorting: true,
    meta: { headerClass: 'w-28' },
  },
  {
    id: 'description',
    accessorFn: (t) => `${t.description ?? ''} ${t.accountName}`,
    header: 'Account / description',
    enableSorting: true,
  },
  {
    id: 'amount',
    accessorFn: (t) => Number(t.amountMinor),
    header: 'Amount',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular font-medium' },
  },
  {
    id: 'amountBase',
    accessorFn: (t) => Number(t.amountBaseMinor),
    header: `In ${data.value?.baseCurrency ?? ''}`,
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular text-[var(--color-text-muted)]' },
  },
])

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

    <div v-if="loading && !data" class="text-[var(--color-text-muted)]">Loading…</div>

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
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-lg font-medium">Transactions</h2>
          <button
            v-if="hasFilters"
            type="button"
            class="btn btn-ghost text-xs"
            @click="clearFilters"
          >Clear filters</button>
        </div>

        <div
          v-if="!loading && data.transactions.items.length === 0 && !hasFilters"
          class="card p-10 text-center space-y-2"
        >
          <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
          <p class="font-medium">No transactions with this tag.</p>
        </div>

        <DataTable
          v-else
          :data="data.transactions.items"
          :columns="transactionColumns"
          show-filters
          manual-filtering
          manual-sorting
          :active-filter-columns="activeFilterColumns"
          :sorting="sorting"
          :loading="loading"
          :page="page"
          :page-size="pageSize"
          :total="data.transactions.total"
          empty-text="No transactions match the filters."
          @update:page="(p) => { page = p; load() }"
          @update:page-size="(v) => { pageSize = v; page = 1; load() }"
          @update:sorting="(s) => { sorting = s; page = 1; load() }"
        >
          <template #filter-occurredAt>
            <div class="space-y-1">
              <DateField v-model="filterFrom" clearable placeholder="From" @update:model-value="onFilterChange" />
              <DateField v-model="filterTo" clearable placeholder="To" @update:model-value="onFilterChange" />
            </div>
          </template>
          <template #filter-type>
            <select v-model="filterType" class="input" @change="onFilterChange">
              <option value="">All types</option>
              <option v-for="(label, value) in typeLabels" :key="value" :value="value">{{ label }}</option>
            </select>
          </template>
          <template #filter-description>
            <input
              v-model="filterQ"
              placeholder="Search description or account…"
              class="input text-sm"
              @input="onFilterChange"
            />
          </template>
          <template #cell-occurredAt="{ row }">
            {{ shortDate(row.occurredAt) }}
          </template>
          <template #cell-type="{ row }">
            <span class="badge">{{ typeLabels[row.type] ?? row.type }}</span>
          </template>
          <template #cell-description="{ row }">
            <RouterLink
              :to="{ name: 'transaction', params: { accountId: row.accountId, id: row.id } }"
              class="font-medium hover:text-[var(--color-accent)] transition-colors"
            >
              {{ row.description ?? '—' }}
            </RouterLink>
            <div class="text-xs text-[var(--color-text-dim)] mt-0.5 flex flex-wrap items-center gap-1.5">
              <RouterLink
                :to="{ name: 'account', params: { id: row.accountId } }"
                class="hover:text-[var(--color-accent)] transition-colors"
              >
                {{ row.accountName }}
              </RouterLink>
              <span
                v-if="categoryMeta(row.category)"
                class="inline-flex items-center gap-1"
              >
                <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(row.category)!.color }"></span>
                <span>{{ categoryMeta(row.category)!.label }}</span>
              </span>
            </div>
          </template>
          <template #cell-amount="{ row }">
            <span :class="BigInt(row.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
              {{ formatMinor(row.amountMinor, row.currency) }}
            </span>
          </template>
          <template #cell-amountBase="{ row }">
            <span :class="BigInt(row.amountBaseMinor) < 0n ? 'text-[var(--color-negative)]' : ''">
              {{ formatMinor(row.amountBaseMinor, data!.baseCurrency) }}
            </span>
          </template>
        </DataTable>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Tag not found.</p>
  </div>
</template>
