<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import AssetPriceChart from '@/components/AssetPriceChart.vue'
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
  accounts: PerAccount[]
  dividends: YearTotal[]
  transactions: AssetTx[]
}

const route = useRoute()
const isin = computed(() => String(route.params.isin).toUpperCase())

const data = ref<AssetDetail | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.get<AssetDetail>(`/api/assets/${isin.value}`)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(isin, load)

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

// Simple per-currency return: current value (when same currency) + dividends − invested.
// Computed only for currencies where invested > 0 so we don't divide by zero.
const returnByCurrency = computed(() => {
  if (!data.value) return [] as Array<{ currency: string; investedMinor: string; dividendsMinor: string; valueMinor: bigint; returnMinor: bigint; returnPct: number | null }>
  const result: Array<{ currency: string; investedMinor: string; dividendsMinor: string; valueMinor: bigint; returnMinor: bigint; returnPct: number | null }> = []
  for (const t of data.value.totalsByCurrency) {
    const invested = BigInt(t.investedMinor)
    const dividends = BigInt(t.dividendsMinor)
    // Only include current value if it's in this same currency
    const valueMinor = data.value.currentValueCurrency === t.currency && data.value.currentValueMinor !== null
      ? BigInt(data.value.currentValueMinor)
      : 0n
    const returnMinor = valueMinor + dividends - invested
    const returnPct = invested > 0n
      ? Number(returnMinor * 10000n / invested) / 100
      : null
    result.push({
      currency: t.currency,
      investedMinor: t.investedMinor,
      dividendsMinor: t.dividendsMinor,
      valueMinor,
      returnMinor,
      returnPct,
    })
  }
  return result
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
          <p class="text-3xl font-semibold tracking-tight tabular mt-1">{{ data.totalQuantity }}</p>
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
          <p class="text-lg font-medium tabular mt-1">{{ data.transactions.length }}</p>
        </div>
        <div class="px-5 py-4" style="background-color: var(--color-surface);">
          <p class="label">Dividends</p>
          <p class="text-lg font-medium tabular mt-1">
            {{ data.transactions.filter((t) => t.type === 'dividend').length }}
          </p>
        </div>
      </div>

      <!-- Return summary per currency -->
      <section v-if="returnByCurrency.length > 0" class="space-y-3">
        <h2 class="text-lg font-medium">Return</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          <div
            v-for="r in returnByCurrency"
            :key="r.currency"
            class="card p-5 space-y-3"
          >
            <div class="flex items-baseline justify-between">
              <span class="label">{{ r.currency }}</span>
              <span v-if="r.returnPct !== null"
                class="text-xs font-medium tabular"
                :class="r.returnPct >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
              >
                <component :is="r.returnPct >= 0 ? TrendingUp : TrendingDown" :size="12" class="inline" />
                {{ r.returnPct >= 0 ? '+' : '' }}{{ r.returnPct.toFixed(2) }}%
              </span>
            </div>
            <div class="space-y-1.5 text-sm">
              <div class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Net invested</span>
                <span class="tabular">{{ formatMinor(r.investedMinor, r.currency) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">+ Dividends</span>
                <span class="tabular text-[var(--color-positive)]">{{ formatMinor(r.dividendsMinor, r.currency) }}</span>
              </div>
              <div v-if="r.valueMinor !== 0n" class="flex justify-between">
                <span class="text-[var(--color-text-muted)]">Current value</span>
                <span class="tabular">{{ formatMinor(r.valueMinor.toString(), r.currency) }}</span>
              </div>
              <div class="flex justify-between border-t pt-1.5 mt-1.5" style="border-color: var(--color-border);">
                <span class="text-[var(--color-text)] font-medium">Total return</span>
                <span
                  class="tabular font-medium"
                  :class="r.returnMinor >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
                >{{ formatMinor(r.returnMinor.toString(), r.currency) }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Price chart -->
      <section class="space-y-3">
        <h2 class="text-lg font-medium">Price history</h2>
        <AssetPriceChart :isin="data.isin" />
      </section>

      <!-- Per-account holdings -->
      <section v-if="data.accounts.length > 0" class="space-y-3">
        <h2 class="text-lg font-medium">Held in</h2>
        <div class="card overflow-hidden">
          <table class="table">
            <thead>
              <tr>
                <th>Account</th>
                <th class="text-right">Quantity</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in data.accounts" :key="a.accountId">
                <td>
                  <RouterLink :to="{ name: 'account', params: { id: a.accountId } }" class="font-medium">
                    {{ a.accountName }}
                  </RouterLink>
                </td>
                <td class="text-right tabular">{{ a.quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
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

        <div v-if="data.transactions.length === 0" class="card p-10 text-center space-y-2">
          <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
          <p class="font-medium">No transactions for this asset.</p>
        </div>

        <div v-else class="card overflow-hidden">
          <table class="table">
            <thead>
              <tr>
                <th class="w-28">Date</th>
                <th class="w-28">Type</th>
                <th>Account / description</th>
                <th class="text-right w-28">Quantity</th>
                <th class="text-right w-32">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in [...data.transactions].reverse()" :key="t.id">
                <td class="text-[var(--color-text-muted)] tabular">{{ shortDate(t.occurredAt) }}</td>
                <td><span class="badge">{{ typeLabels[t.type] ?? t.type }}</span></td>
                <td>
                  <RouterLink :to="{ name: 'account', params: { id: t.accountId } }" class="font-medium hover:text-[var(--color-accent)]">
                    {{ t.accountName }}
                  </RouterLink>
                  <div class="text-xs text-[var(--color-text-dim)] mt-0.5 flex items-center gap-1.5">
                    <span v-if="t.description">{{ t.description }}</span>
                    <span
                      v-if="categoryMeta(t.category)"
                      class="inline-flex items-center gap-1"
                    >
                      <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(t.category)!.color }"></span>
                      <span>{{ categoryMeta(t.category)!.label }}</span>
                    </span>
                  </div>
                </td>
                <td class="text-right tabular text-[var(--color-text-muted)]">{{ t.assetQuantity ?? '' }}</td>
                <td
                  class="text-right tabular font-medium"
                  :class="BigInt(t.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                >
                  {{ formatMinor(t.amountMinor, t.currency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Asset not found.</p>
  </div>
</template>
