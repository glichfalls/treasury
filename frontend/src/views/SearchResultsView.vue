<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { describeSchedule, type RecurringFrequency } from '@/lib/recurring'
import { useAccountsStore } from '@/stores/accounts'
import DateField from '@/components/ui/DateField.vue'
import DataTable from '@/components/ui/DataTable.vue'
import type { ColumnDef, SortingState } from '@tanstack/vue-table'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { Search, Wallet, Receipt, TrendingUp, Repeat, Tag as TagIcon, Inbox, X } from 'lucide-vue-next'

interface AccountResult { id: string; name: string; institution: string | null; type: string; currency: string }
interface TransactionResult {
  id: string; accountId: string; accountName: string; occurredAt: string;
  amountMinor: string; currency: string; description: string | null;
  type: string; category: string | null; assetIsin: string | null;
}
interface AssetResult { isin: string; ticker: string | null; name: string | null; currency: string | null }
interface RecurringResult {
  id: string; accountId: string; accountName: string; description: string;
  amountMinor: string; currency: string; frequency: RecurringFrequency; active: boolean;
}
interface TagResult { tag: string; count: number }
interface SearchResponse {
  accounts: AccountResult[]
  transactions: TransactionResult[]
  assets: AssetResult[]
  recurring: RecurringResult[]
  tags: TagResult[]
}
interface TransactionsPage {
  items: TransactionResult[]
  total: number
  page: number
  pageSize: number
}
interface MonthlyPoint { month: string; amountMinor: string }
interface SearchStats {
  query: string
  baseCurrency: string
  count: number
  totalSignedMinor: string
  totalSpentMinor: string
  totalIncomeMinor: string
  monthly: MonthlyPoint[]
}

const route = useRoute()
const router = useRouter()
const accountsStore = useAccountsStore()

const query = ref(String(route.query.q ?? ''))
const accountId = ref(String(route.query.accountId ?? ''))
const dateFrom = ref(String(route.query.dateFrom ?? ''))
const dateTo = ref(String(route.query.dateTo ?? ''))
const txType = ref(String(route.query.type ?? ''))

const results = ref<SearchResponse>({ accounts: [], transactions: [], assets: [], recurring: [], tags: [] })
const stats = ref<SearchStats | null>(null)
const transactionsPage = ref<TransactionsPage>({ items: [], total: 0, page: 1, pageSize: 25 })
const loading = ref(false)

// Pagination + sort state for the transactions table.
const txPage = ref(1)
const txPageSize = ref(25)
const txSorting = ref<SortingState>([{ id: 'occurredAt', desc: true }])
const txSortParam = computed(() => {
  const s = txSorting.value[0]
  return s ? `${s.id}:${s.desc ? 'desc' : 'asc'}` : undefined
})

const transactionTypes = [
  { value: 'deposit', label: 'Deposit' },
  { value: 'withdrawal', label: 'Withdrawal' },
  { value: 'trade_buy', label: 'Buy' },
  { value: 'trade_sell', label: 'Sell' },
  { value: 'fee', label: 'Fee' },
  { value: 'interest', label: 'Interest' },
  { value: 'dividend', label: 'Dividend' },
  { value: 'fx_conversion', label: 'FX' },
  { value: 'other', label: 'Other' },
]

function buildSearchParams(extra: Record<string, string | number> = {}): string {
  const p = new URLSearchParams()
  p.set('q', query.value.trim())
  if (accountId.value) p.set('accountId', accountId.value)
  if (dateFrom.value) p.set('dateFrom', dateFrom.value)
  if (dateTo.value) p.set('dateTo', dateTo.value)
  if (txType.value) p.set('type', txType.value)
  for (const [k, v] of Object.entries(extra)) p.set(k, String(v))
  return p.toString()
}

async function load() {
  const q = query.value.trim()
  if (q.length < 2) {
    results.value = { accounts: [], transactions: [], assets: [], recurring: [], tags: [] }
    stats.value = null
    transactionsPage.value = { items: [], total: 0, page: 1, pageSize: txPageSize.value }
    return
  }
  loading.value = true
  try {
    const txExtra: Record<string, string | number> = {
      page: txPage.value,
      pageSize: txPageSize.value,
    }
    if (txSortParam.value) txExtra.sort = txSortParam.value
    const [r, s, t] = await Promise.all([
      api.get<SearchResponse>(`/api/search?${buildSearchParams({ limit: 100 })}`),
      api.get<SearchStats>(`/api/search/stats?${buildSearchParams()}`),
      api.get<TransactionsPage>(`/api/search/transactions?${buildSearchParams(txExtra)}`),
    ])
    results.value = r
    stats.value = s
    transactionsPage.value = t
  } finally {
    loading.value = false
  }
}

// Whenever a top-bar filter changes via syncRoute, the watch on route.query
// re-runs load(). We just need to reset to page 1 there.

function syncRoute() {
  const next: Record<string, string> = {}
  if (query.value.trim()) next.q = query.value.trim()
  if (accountId.value) next.accountId = accountId.value
  if (dateFrom.value) next.dateFrom = dateFrom.value
  if (dateTo.value) next.dateTo = dateTo.value
  if (txType.value) next.type = txType.value
  router.replace({ name: 'search', query: next })
}

onMounted(async () => {
  if (!accountsStore.loaded) {
    await accountsStore.fetchAll().catch(() => {})
  }
  load()
})

// React to URL changes (e.g. navigating from the modal "View all" link).
// Reset transactions pagination — a new query implies a new dataset.
watch(() => route.query, (q) => {
  query.value = String(q.q ?? '')
  accountId.value = String(q.accountId ?? '')
  dateFrom.value = String(q.dateFrom ?? '')
  dateTo.value = String(q.dateTo ?? '')
  txType.value = String(q.type ?? '')
  txPage.value = 1
  load()
})

function onSubmit() {
  syncRoute()
}
function onFilterChange() {
  syncRoute()
}
function clearFilters() {
  accountId.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  txType.value = ''
  syncRoute()
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const totalMatches = computed(() => {
  const r = results.value
  return r.accounts.length + r.assets.length + transactionsPage.value.total + r.recurring.length + r.tags.length
})

const hasFilters = computed(() => !!(accountId.value || dateFrom.value || dateTo.value || txType.value))

const typeLabels: Record<string, string> = {
  deposit: 'Deposit', opening_balance: 'Opening balance', withdrawal: 'Withdrawal',
  trade_buy: 'Buy', trade_sell: 'Sell',
  fee: 'Fee', interest: 'Interest', dividend: 'Dividend', fx_conversion: 'FX', other: 'Other',
}

const transactionColumns = computed<ColumnDef<TransactionResult, unknown>[]>(() => [
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
])

const hasStats = computed(() => (stats.value?.count ?? 0) > 0)
const hasMonthly = computed(() => (stats.value?.monthly?.length ?? 0) > 0)

const chartOption = computed<EChartsOption>(() => {
  if (!stats.value || stats.value.monthly.length === 0) return { backgroundColor: 'transparent' }
  const ccy = stats.value.baseCurrency
  const months = stats.value.monthly.map((m) => m.month)
  const income = stats.value.monthly.map((m) => Math.max(0, Number(m.amountMinor) / 100))
  const spending = stats.value.monthly.map((m) => Math.min(0, Number(m.amountMinor) / 100))

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
</script>

<template>
  <div class="space-y-6 max-w-5xl mx-auto">
    <header class="space-y-3">
      <p class="label">Search</p>
      <form @submit.prevent="onSubmit">
        <div class="relative">
          <Search :size="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--color-text-dim)] pointer-events-none" />
          <input
            v-model="query"
            type="text"
            placeholder="Search accounts, transactions, assets, tags…"
            class="input pl-10 text-base py-2.5"
            autofocus
          />
        </div>
      </form>

      <!-- Filter bar -->
      <div class="flex flex-wrap items-end gap-3 pt-1">
        <label class="flex flex-col gap-1 text-xs text-[var(--color-text-dim)]">
          <span>Account</span>
          <select v-model="accountId" class="input py-1.5 text-sm min-w-[12rem]" @change="onFilterChange">
            <option value="">All accounts</option>
            <option v-for="a in accountsStore.accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
        </label>
        <div class="flex flex-col gap-1 text-xs text-[var(--color-text-dim)]">
          <span>From</span>
          <DateField v-model="dateFrom" placeholder="Any" clearable :max="dateTo || undefined" @update:model-value="onFilterChange" />
        </div>
        <div class="flex flex-col gap-1 text-xs text-[var(--color-text-dim)]">
          <span>To</span>
          <DateField v-model="dateTo" placeholder="Any" clearable :min="dateFrom || undefined" @update:model-value="onFilterChange" />
        </div>
        <label class="flex flex-col gap-1 text-xs text-[var(--color-text-dim)]">
          <span>Type</span>
          <select v-model="txType" class="input py-1.5 text-sm min-w-[10rem]" @change="onFilterChange">
            <option value="">All types</option>
            <option v-for="t in transactionTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </label>
        <button
          v-if="hasFilters"
          type="button"
          class="inline-flex items-center gap-1 text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors py-1.5"
          @click="clearFilters"
        >
          <X :size="12" />
          Clear filters
        </button>
      </div>

      <p class="text-sm text-[var(--color-text-muted)]">
        <span v-if="loading">Searching…</span>
        <span v-else-if="query.trim().length < 2">Type at least 2 characters.</span>
        <span v-else>{{ totalMatches }} matches for "<span class="text-[var(--color-text)]">{{ query }}</span>"</span>
      </p>
    </header>

    <!-- Stats -->
    <section v-if="hasStats && stats" class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
             style="background-color: var(--color-border);">
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Transactions</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1">{{ stats.count }}</p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Net</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1"
          :class="BigInt(stats.totalSignedMinor) >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
        >
          {{ formatMinor(stats.totalSignedMinor, stats.baseCurrency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Spent</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-negative)]">
          {{ formatMinor(stats.totalSpentMinor, stats.baseCurrency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Received</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-positive)]">
          {{ formatMinor(stats.totalIncomeMinor, stats.baseCurrency) }}
        </p>
      </div>
    </section>

    <!-- Monthly chart -->
    <section v-if="hasMonthly && stats" class="card p-4">
      <h2 class="text-sm font-medium mb-2">Monthly totals ({{ stats.baseCurrency }})</h2>
      <VChart :option="chartOption" class="w-full" style="height: 18rem" autoresize />
    </section>

    <div v-if="!loading && query.trim().length >= 2 && totalMatches === 0" class="card p-10 text-center space-y-2">
      <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
      <p class="font-medium">No matches</p>
      <p class="text-sm text-[var(--color-text-muted)]">
        Try a shorter query{{ hasFilters ? ', clear some filters' : '' }}, or check spelling.
      </p>
    </div>

    <!-- Accounts -->
    <section v-if="results.accounts.length > 0" class="space-y-3">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <Wallet :size="16" class="text-[var(--color-accent)]" />
        Accounts <span class="text-sm text-[var(--color-text-dim)]">{{ results.accounts.length }}</span>
      </h2>
      <div class="card overflow-hidden">
        <ul>
          <li v-for="a in results.accounts" :key="a.id" class="border-b last:border-b-0" style="border-color: var(--color-border);">
            <RouterLink :to="{ name: 'account', params: { id: a.id } }"
              class="block px-4 py-3 hover:bg-[var(--color-surface-hover)] transition-colors flex items-center justify-between">
              <div>
                <div class="font-medium">{{ a.name }}</div>
                <div class="text-xs text-[var(--color-text-dim)]">{{ a.institution ?? a.type }}</div>
              </div>
              <span class="text-xs text-[var(--color-text-muted)]">{{ a.currency }}</span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <!-- Assets -->
    <section v-if="results.assets.length > 0" class="space-y-3">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <TrendingUp :size="16" class="text-[var(--color-highlight)]" />
        Assets <span class="text-sm text-[var(--color-text-dim)]">{{ results.assets.length }}</span>
      </h2>
      <div class="card overflow-hidden">
        <ul>
          <li v-for="a in results.assets" :key="a.isin" class="border-b last:border-b-0" style="border-color: var(--color-border);">
            <RouterLink :to="{ name: 'asset', params: { isin: a.isin } }"
              class="block px-4 py-3 hover:bg-[var(--color-surface-hover)] transition-colors flex items-center justify-between">
              <div>
                <div class="font-medium">{{ a.ticker ?? a.name ?? a.isin }}</div>
                <div class="text-xs text-[var(--color-text-dim)]">{{ a.name ?? a.isin }}</div>
              </div>
              <span class="text-xs text-[var(--color-text-muted)]">{{ a.currency }}</span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </section>

    <!-- Tags -->
    <section v-if="results.tags.length > 0" class="space-y-3">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <TagIcon :size="16" class="text-[var(--color-accent)]" />
        Tags <span class="text-sm text-[var(--color-text-dim)]">{{ results.tags.length }}</span>
      </h2>
      <div class="flex flex-wrap gap-2">
        <RouterLink v-for="t in results.tags" :key="t.tag"
          :to="{ name: 'tag', params: { tag: t.tag } }"
          class="inline-flex items-center gap-1.5 text-sm rounded px-3 py-1.5 hover:opacity-80 transition-opacity"
          style="background-color: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent);">
          {{ t.tag }}
          <span class="text-xs text-[var(--color-text-dim)]">{{ t.count }}</span>
        </RouterLink>
      </div>
    </section>

    <!-- Transactions -->
    <section v-if="transactionsPage.total > 0" class="space-y-3">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <Receipt :size="16" class="text-[var(--color-text-muted)]" />
        Transactions <span class="text-sm text-[var(--color-text-dim)]">{{ transactionsPage.total }}</span>
      </h2>
      <DataTable
        :data="transactionsPage.items"
        :columns="transactionColumns"
        manual-sorting
        :sorting="txSorting"
        :loading="loading"
        :page="txPage"
        :page-size="txPageSize"
        :total="transactionsPage.total"
        empty-text="No transactions match."
        @update:page="(p) => { txPage = p; load() }"
        @update:page-size="(v) => { txPageSize = v; txPage = 1; load() }"
        @update:sorting="(s) => { txSorting = s; txPage = 1; load() }"
      >
        <template #cell-occurredAt="{ row }">
          {{ shortDate(row.occurredAt) }}
        </template>
        <template #cell-type="{ row }">
          <span class="badge">{{ typeLabels[row.type] ?? row.type }}</span>
        </template>
        <template #cell-description="{ row }">
          <RouterLink :to="{ name: 'transaction', params: { accountId: row.accountId, id: row.id } }"
            class="font-medium hover:text-[var(--color-accent)] transition-colors">
            {{ row.description ?? '—' }}
          </RouterLink>
          <div class="text-xs text-[var(--color-text-dim)] mt-0.5 flex items-center gap-1.5">
            <RouterLink :to="{ name: 'account', params: { id: row.accountId } }"
              class="hover:text-[var(--color-accent)] transition-colors">
              {{ row.accountName }}
            </RouterLink>
            <span v-if="categoryMeta(row.category)" class="inline-flex items-center gap-1">
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
      </DataTable>
    </section>

    <!-- Recurring -->
    <section v-if="results.recurring.length > 0" class="space-y-3">
      <h2 class="text-lg font-medium flex items-center gap-2">
        <Repeat :size="16" class="text-[var(--color-text-muted)]" />
        Recurring <span class="text-sm text-[var(--color-text-dim)]">{{ results.recurring.length }}</span>
      </h2>
      <div class="card overflow-hidden">
        <ul>
          <li v-for="r in results.recurring" :key="r.id" class="border-b last:border-b-0" style="border-color: var(--color-border);">
            <RouterLink :to="{ name: 'account', params: { id: r.accountId } }"
              class="block px-4 py-3 hover:bg-[var(--color-surface-hover)] transition-colors flex items-center justify-between"
              :class="!r.active ? 'opacity-60' : ''">
              <div>
                <div class="font-medium">{{ r.description }}</div>
                <div class="text-xs text-[var(--color-text-dim)]">{{ r.accountName }} · {{ describeSchedule(r as never) }}</div>
              </div>
              <span class="tabular text-sm"
                :class="BigInt(r.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
                {{ formatMinor(r.amountMinor, r.currency) }}
              </span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </section>
  </div>
</template>
