<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor, formatQuantity } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import AssetPriceChart from '@/components/charts/AssetPriceChart.vue'
import DateField from '@/components/ui/DateField.vue'
import DataTable from '@/components/ui/DataTable.vue'
import type { ColumnDef, SortingState } from '@tanstack/vue-table'
import { ChevronLeft, Inbox, TrendingUp, TrendingDown } from 'lucide-vue-next'

interface PerAccount {
  accountId: string
  accountName: string
  currency: string
  quantity: string
}
interface YearTotal {
  year: number
  currency: string
  amountMinor: string
  count: number
}
interface CurrencyTotal {
  currency: string
  investedMinor: string
  dividendsMinor: string
}
interface AssetTx {
  id: string
  accountId: string
  accountName: string
  occurredAt: string
  amountMinor: string
  currency: string
  description: string | null
  type: string
  source: string
  category: string | null
  assetQuantity: string | null
}
interface AssetDetail {
  isin: string
  ticker: string | null
  name: string | null
  currency: string | null
  unitWeightGrams: string | null
  pricePremiumPct: string | null
  totalQuantity: string
  currentPriceMinor: string | null
  currentPriceCurrency: string | null
  currentPriceAsOf: string | null
  currentValueMinor: string | null
  currentValueCurrency: string | null
  totalsByCurrency: CurrencyTotal[]
  baseCurrency: string
  baseInvestedMinor: string
  baseDividendsMinor: string
  baseCurrentValueMinor: string | null
  baseFxIncomplete: boolean
  accounts: PerAccount[]
  dividends: YearTotal[]
  transactions: TransactionsPage
}
interface TransactionsPage {
  items: AssetTx[]
  total: number
  page: number
  pageSize: number
}

const route = useRoute()
const isin = computed(() => String(route.params.isin).toUpperCase())

const data = ref<AssetDetail | null>(null)
const loading = ref(false)

// Filter / pagination / sort state.
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
    data.value = await api.get<AssetDetail>(`/api/assets/${isin.value}?${params.toString()}`)
  } finally {
    loading.value = false
  }
}

let filterTimer: ReturnType<typeof setTimeout> | null = null
function onFilterChange() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 400)
}

onMounted(load)
watch(isin, load)

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

const accountColumns = computed<ColumnDef<PerAccount, unknown>[]>(() => [
  { id: 'accountName', accessorKey: 'accountName', header: 'Account', enableSorting: true },
  {
    id: 'quantity',
    accessorFn: (a) => Number(a.quantity),
    header: 'Quantity',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', cellClass: 'tabular' },
  },
])

// Server already sorts descending by default — no need to reverse.

const transactionColumns = computed<ColumnDef<AssetTx, unknown>[]>(() => [
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
    accessorFn: (t) => `${t.accountName} ${t.description ?? ''}`,
    header: 'Account / description',
    enableSorting: true,
  },
  {
    id: 'quantity',
    accessorFn: (t) => Number(t.assetQuantity ?? 0),
    header: 'Quantity',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-28', cellClass: 'tabular text-[var(--color-text-muted)]' },
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

// Group dividends by currency for the per-year totals section.
const dividendsByCurrency = computed(() => {
  if (!data.value) return [] as Array<{ currency: string; years: YearTotal[]; total: string; count: number }>
  const map = new Map<string, YearTotal[]>()
  for (const d of data.value.dividends) {
    if (!map.has(d.currency)) map.set(d.currency, [])
    map.get(d.currency)!.push(d)
  }
  return [...map.entries()].map(([currency, years]) => ({
    currency,
    years: [...years].sort((a, b) => b.year - a.year),
    total: years.reduce((s, y) => (BigInt(s) + BigInt(y.amountMinor)).toString(), '0'),
    count: years.reduce((s, y) => s + y.count, 0),
  }))
})

// Cost basis per currency: how much actual cash was spent in each currency
// across all trades. Just informational — no return % attempted here.
const costBasis = computed(() => {
  if (!data.value) return [] as Array<{ currency: string; investedMinor: string }>
  return data.value.totalsByCurrency
    .filter((t) => BigInt(t.investedMinor) !== 0n)
    .map((t) => ({ currency: t.currency, investedMinor: t.investedMinor }))
})

// Return in the user's base currency, using historical FX from each transaction
// date. This is the headline return when transactions span multiple currencies.
const baseReturn = computed<null | {
  currency: string
  investedMinor: string
  dividendsMinor: string
  valueMinor: string
  returnMinor: bigint
  returnPct: number | null
  incomplete: boolean
}>(() => {
  if (!data.value) return null
  const invested = BigInt(data.value.baseInvestedMinor)
  const dividends = BigInt(data.value.baseDividendsMinor)
  const value = BigInt(data.value.baseCurrentValueMinor ?? '0')
  // Nothing to show if there's been no activity in base.
  if (invested === 0n && dividends === 0n && value === 0n) return null
  const returnMinor = value + dividends - invested
  const returnPct = invested > 0n
    ? Number(returnMinor * 10000n / invested) / 100
    : null
  return {
    currency: data.value.baseCurrency,
    investedMinor: data.value.baseInvestedMinor,
    dividendsMinor: data.value.baseDividendsMinor,
    valueMinor: data.value.baseCurrentValueMinor ?? '0',
    returnMinor,
    returnPct,
    incomplete: data.value.baseFxIncomplete,
  }
})

// Honest return calculation: only when EVERY relevant number is in the same
// currency. If you bought a USD stock with CHF cash and the dividends are in
// USD, there's no single-currency answer without FX history — so we show the
// cost-basis breakdown without a fake percentage instead.
const singleCurrencyReturn = computed<null | {
  currency: string
  investedMinor: string
  dividendsMinor: string
  valueMinor: string
  returnMinor: bigint
  returnPct: number | null
}>(() => {
  if (!data.value) return null
  const investedCurrencies = costBasis.value.map((c) => c.currency)
  const dividendCurrencies = data.value.totalsByCurrency
    .filter((t) => BigInt(t.dividendsMinor) !== 0n)
    .map((t) => t.currency)
  const all = [
    ...investedCurrencies,
    ...dividendCurrencies,
    ...(data.value.currentValueCurrency ? [data.value.currentValueCurrency] : []),
  ]
  if (all.length === 0) return null
  const unique = [...new Set(all)]
  if (unique.length !== 1) return null

  const currency = unique[0]!
  const totals = data.value.totalsByCurrency.find((t) => t.currency === currency)
  const invested = BigInt(totals?.investedMinor ?? '0')
  const dividends = BigInt(totals?.dividendsMinor ?? '0')
  const valueMinor = data.value.currentValueMinor ?? '0'
  const returnMinor = BigInt(valueMinor) + dividends - invested
  const returnPct = invested > 0n
    ? Number(returnMinor * 10000n / invested) / 100
    : null
  return {
    currency,
    investedMinor: totals?.investedMinor ?? '0',
    dividendsMinor: totals?.dividendsMinor ?? '0',
    valueMinor,
    returnMinor,
    returnPct,
  }
})
</script>

<template>
  <div class="space-y-8">
    <RouterLink
      :to="{ name: 'dashboard' }"
      class="inline-flex items-center gap-1 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <ChevronLeft :size="16" />
      <span>Back</span>
    </RouterLink>

    <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>

    <template v-else-if="data">
      <!-- Header -->
      <header class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
          <p class="label">{{ data.isin }}</p>
          <h1 class="text-2xl font-semibold tracking-tight mt-1">
            {{ data.ticker ?? data.name ?? data.isin }}
            <span v-if="data.ticker && data.name" class="text-[var(--color-text-muted)] text-base font-normal ml-2">{{ data.name }}</span>
          </h1>
        </div>
        <div class="text-right">
          <p class="label">Holdings</p>
          <p class="text-3xl font-semibold tracking-tight tabular mt-1">{{ formatQuantity(data.totalQuantity) }}</p>
          <p v-if="data.currentValueMinor && data.currentValueCurrency" class="text-xs text-[var(--color-text-dim)] tabular mt-1">
            {{ formatMinor(data.currentValueMinor, data.currentValueCurrency) }} current value
          </p>
        </div>
      </header>

      <!-- Stat row -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
           style="background-color: var(--color-border);">
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Latest price</p>
          <p class="text-lg font-medium tabular mt-1">
            {{ data.currentPriceMinor && data.currentPriceCurrency
              ? formatMinor(data.currentPriceMinor, data.currentPriceCurrency)
              : '—' }}
          </p>
          <p v-if="data.currentPriceAsOf" class="text-xs text-[var(--color-text-dim)] mt-0.5">{{ data.currentPriceAsOf }}</p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Accounts</p>
          <p class="text-lg font-medium tabular mt-1">{{ data.accounts.length }}</p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Transactions</p>
          <p class="text-lg font-medium tabular mt-1">{{ data.transactions.total }}</p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Dividends</p>
          <p class="text-lg font-medium tabular mt-1">
            {{ data.dividends.reduce((sum, d) => sum + d.count, 0) }}
          </p>
        </div>
      </div>

      <!-- Return: base-currency card (using historical FX) is the headline
           since it handles mixed-currency assets cleanly. The native-currency
           card alongside makes the underlying numbers obvious. -->
      <section v-if="baseReturn" class="space-y-3">
        <h2 class="text-lg font-medium">Return</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="card p-5 space-y-3">
            <div class="flex items-baseline justify-between">
              <span class="label">In {{ baseReturn.currency }}</span>
              <span
                v-if="baseReturn.returnPct !== null"
                class="text-sm font-medium tabular"
                :class="baseReturn.returnPct >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
              >
                <component :is="baseReturn.returnPct >= 0 ? TrendingUp : TrendingDown" :size="12" class="inline" />
                {{ baseReturn.returnPct >= 0 ? '+' : '' }}{{ baseReturn.returnPct.toFixed(2) }}%
              </span>
            </div>
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Net invested</span>
                <span class="tabular">{{ formatMinor(baseReturn.investedMinor, baseReturn.currency) }}</span>
              </div>
              <div v-if="BigInt(baseReturn.dividendsMinor) !== 0n" class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">+ Dividends</span>
                <span class="tabular text-[var(--color-positive)]">{{ formatMinor(baseReturn.dividendsMinor, baseReturn.currency) }}</span>
              </div>
              <div v-if="BigInt(baseReturn.valueMinor) !== 0n" class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Current value</span>
                <span class="tabular">{{ formatMinor(baseReturn.valueMinor, baseReturn.currency) }}</span>
              </div>
              <div class="flex justify-between border-t pt-1.5 mt-1.5" style="border-color: var(--color-border);">
                <span class="text-[var(--color-text)] font-medium">Total return</span>
                <span
                  class="tabular font-medium"
                  :class="baseReturn.returnMinor >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
                >{{ formatMinor(baseReturn.returnMinor.toString(), baseReturn.currency) }}</span>
              </div>
            </div>
            <p class="text-xs text-[var(--color-text-dim)]">
              Converted at the FX rate from each transaction's date · current value at today's FX.
              <span v-if="baseReturn.incomplete" class="text-[var(--color-negative)]">
                Some transactions had no FX data and were skipped.
              </span>
            </p>
          </div>

          <!-- Native-currency card alongside -->
          <div v-if="singleCurrencyReturn && singleCurrencyReturn.currency !== baseReturn.currency" class="card p-5 space-y-3">
            <div class="flex items-baseline justify-between">
              <span class="label">In {{ singleCurrencyReturn.currency }} (native)</span>
              <span
                v-if="singleCurrencyReturn.returnPct !== null"
                class="text-sm font-medium tabular"
                :class="singleCurrencyReturn.returnPct >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
              >
                {{ singleCurrencyReturn.returnPct >= 0 ? '+' : '' }}{{ singleCurrencyReturn.returnPct.toFixed(2) }}%
              </span>
            </div>
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Net invested</span>
                <span class="tabular">{{ formatMinor(singleCurrencyReturn.investedMinor, singleCurrencyReturn.currency) }}</span>
              </div>
              <div v-if="BigInt(singleCurrencyReturn.dividendsMinor) !== 0n" class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">+ Dividends</span>
                <span class="tabular text-[var(--color-positive)]">{{ formatMinor(singleCurrencyReturn.dividendsMinor, singleCurrencyReturn.currency) }}</span>
              </div>
              <div v-if="BigInt(singleCurrencyReturn.valueMinor) !== 0n" class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Current value</span>
                <span class="tabular">{{ formatMinor(singleCurrencyReturn.valueMinor, singleCurrencyReturn.currency) }}</span>
              </div>
              <div class="flex justify-between border-t pt-1.5 mt-1.5" style="border-color: var(--color-border);">
                <span class="text-[var(--color-text)] font-medium">Total return</span>
                <span
                  class="tabular font-medium"
                  :class="singleCurrencyReturn.returnMinor >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
                >{{ formatMinor(singleCurrencyReturn.returnMinor.toString(), singleCurrencyReturn.currency) }}</span>
              </div>
            </div>
            <p class="text-xs text-[var(--color-text-dim)]">
              The native-currency view — what you'd see on the broker statement.
            </p>
          </div>
        </div>
      </section>

      <!-- Fallback: single-currency only (no base-currency activity at all) -->
      <section v-else-if="singleCurrencyReturn" class="space-y-3">
        <h2 class="text-lg font-medium">Return</h2>
        <div class="card p-5 space-y-3 max-w-md">
          <div class="flex items-baseline justify-between">
            <span class="label">{{ singleCurrencyReturn.currency }}</span>
            <span
              v-if="singleCurrencyReturn.returnPct !== null"
              class="text-sm font-medium tabular"
              :class="singleCurrencyReturn.returnPct >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
            >
              <component :is="singleCurrencyReturn.returnPct >= 0 ? TrendingUp : TrendingDown" :size="12" class="inline" />
              {{ singleCurrencyReturn.returnPct >= 0 ? '+' : '' }}{{ singleCurrencyReturn.returnPct.toFixed(2) }}%
            </span>
          </div>
          <div class="space-y-1.5 text-sm">
            <div class="flex justify-between">
              <span class="text-[var(--color-text-muted)]">Net invested</span>
              <span class="tabular">{{ formatMinor(singleCurrencyReturn.investedMinor, singleCurrencyReturn.currency) }}</span>
            </div>
            <div v-if="BigInt(singleCurrencyReturn.dividendsMinor) !== 0n" class="flex justify-between">
              <span class="text-[var(--color-text-muted)]">+ Dividends</span>
              <span class="tabular text-[var(--color-positive)]">{{ formatMinor(singleCurrencyReturn.dividendsMinor, singleCurrencyReturn.currency) }}</span>
            </div>
            <div v-if="BigInt(singleCurrencyReturn.valueMinor) !== 0n" class="flex justify-between">
              <span class="text-[var(--color-text-muted)]">Current value</span>
              <span class="tabular">{{ formatMinor(singleCurrencyReturn.valueMinor, singleCurrencyReturn.currency) }}</span>
            </div>
            <div class="flex justify-between border-t pt-1.5 mt-1.5" style="border-color: var(--color-border);">
              <span class="text-[var(--color-text)] font-medium">Total return</span>
              <span
                class="tabular font-medium"
                :class="singleCurrencyReturn.returnMinor >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
              >{{ formatMinor(singleCurrencyReturn.returnMinor.toString(), singleCurrencyReturn.currency) }}</span>
            </div>
          </div>
        </div>
      </section>

      <section v-else-if="costBasis.length > 0" class="space-y-3">
        <h2 class="text-lg font-medium">Cost basis</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
          <div v-for="c in costBasis" :key="c.currency" class="card p-5 space-y-1">
            <p class="label">Spent in {{ c.currency }}</p>
            <p class="text-xl font-medium tabular mt-1">{{ formatMinor(c.investedMinor, c.currency) }}</p>
          </div>
        </div>
        <p class="text-xs text-[var(--color-text-dim)]">
          You paid in {{ costBasis.map((c) => c.currency).join(' + ') }} for an asset priced in
          {{ data.currentValueCurrency ?? data.currency ?? 'a different currency' }}.
          A single return % isn't meaningful here without an FX rate — see Dividends below and the
          current value in the header above for the raw figures.
        </p>
      </section>

      <!-- Price chart -->
      <section class="space-y-3">
        <h2 class="text-lg font-medium">Price history</h2>
        <AssetPriceChart :isin="data.isin" />
      </section>

      <!-- Per-account holdings -->
      <section v-if="data.accounts.length > 0" class="space-y-3">
        <h2 class="text-lg font-medium">Held in</h2>
        <DataTable :data="data.accounts" :columns="accountColumns">
          <template #cell-accountName="{ row }">
            <RouterLink :to="{ name: 'account', params: { id: row.accountId } }" class="font-medium">
              {{ row.accountName }}
            </RouterLink>
          </template>
          <template #cell-quantity="{ row }">
            {{ formatQuantity(row.quantity) }}
          </template>
        </DataTable>
      </section>

      <!-- Dividends by year -->
      <section v-if="dividendsByCurrency.length > 0" class="space-y-3">
        <h2 class="text-lg font-medium">Dividends</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div v-for="d in dividendsByCurrency" :key="d.currency" class="card p-5 space-y-3">
            <div class="flex items-baseline justify-between">
              <span class="label">{{ d.currency }}</span>
              <span class="text-xs text-[var(--color-text-muted)]">{{ d.count }} payments</span>
            </div>
            <p class="text-2xl font-medium tabular text-[var(--color-positive)]">{{ formatMinor(d.total, d.currency) }}</p>
            <ul class="space-y-1 text-sm">
              <li v-for="y in d.years" :key="y.year" class="flex items-center justify-between">
                <span class="text-[var(--color-text-muted)]">{{ y.year }}</span>
                <span class="tabular">{{ formatMinor(y.amountMinor, y.currency) }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Transactions -->
      <section class="space-y-3">
        <h2 class="text-lg font-medium">Transactions</h2>

        <div
          v-if="!loading && data.transactions.items.length === 0 && !hasFilters"
          class="card p-10 text-center space-y-2"
        >
          <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
          <p class="font-medium">No transactions for this asset.</p>
        </div>

        <div v-if="hasFilters" class="flex items-center justify-end">
          <button type="button" class="btn btn-ghost text-xs" @click="clearFilters">Clear filters</button>
        </div>

        <DataTable
          v-if="loading || data.transactions.items.length > 0 || hasFilters"
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
            <RouterLink :to="{ name: 'account', params: { id: row.accountId } }" class="font-medium hover:text-[var(--color-accent)]">
              {{ row.accountName }}
            </RouterLink>
            <div class="text-xs text-[var(--color-text-dim)] mt-0.5 flex items-center gap-1.5">
              <span v-if="row.description">{{ row.description }}</span>
              <span
                v-if="categoryMeta(row.category)"
                class="inline-flex items-center gap-1"
              >
                <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(row.category)!.color }"></span>
                <span>{{ categoryMeta(row.category)!.label }}</span>
              </span>
            </div>
          </template>
          <template #cell-quantity="{ row }">
            {{ formatQuantity(row.assetQuantity) }}
          </template>
          <template #cell-amount="{ row }">
            <span :class="BigInt(row.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
              {{ formatMinor(row.amountMinor, row.currency) }}
            </span>
          </template>
        </DataTable>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Asset not found.</p>
  </div>
</template>
