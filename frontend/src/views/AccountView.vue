<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useAccountsStore, type Transaction, type Holding } from '@/stores/accounts'
import { formatMinor } from '@/lib/money'
import NewTransactionForm from '@/components/NewTransactionForm.vue'
import ImportDropzone from '@/components/ImportDropzone.vue'
import HoldingsTable from '@/components/HoldingsTable.vue'
import AddCoinForm from '@/components/AddCoinForm.vue'
import AllocationEditor from '@/components/AllocationEditor.vue'
import AddContributionForm from '@/components/AddContributionForm.vue'
import OpeningBalanceForm from '@/components/OpeningBalanceForm.vue'
import NetWorthChart from '@/components/NetWorthChart.vue'
import AllocationDonut from '@/components/AllocationDonut.vue'
import AssetPriceChart from '@/components/AssetPriceChart.vue'
import PerformanceChart from '@/components/PerformanceChart.vue'
import { ChevronLeft, Download, Inbox, Trash2 } from 'lucide-vue-next'

const route = useRoute()
const accounts = useAccountsStore()

const accountId = computed(() => String(route.params.id))
const account = computed(() => accounts.accounts.find((a) => a.id === accountId.value))
const transactions = ref<Transaction[]>([])
const holdings = ref<Holding[]>([])
const loading = ref(false)
const range = ref<'1w' | '1m' | '6mo' | '1y' | '2y' | '5y' | 'all'>('1y')
const expandedHolding = ref<string | null>(null)

async function load() {
  if (!accountId.value) return
  loading.value = true
  try {
    const [tx, hd] = await Promise.all([
      accounts.fetchTransactions(accountId.value),
      accounts.fetchHoldings(accountId.value),
    ])
    transactions.value = tx
    holdings.value = hd
  } finally {
    loading.value = false
  }
}

async function reloadAfterImport() {
  await accounts.fetchAll()
  await load()
}

onMounted(async () => {
  if (!accounts.loaded) {
    await accounts.fetchAll()
  }
  await load()
})

watch(accountId, load)

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
  const d = new Date(iso)
  return d.toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

function toggleHolding(isin: string) {
  expandedHolding.value = expandedHolding.value === isin ? null : isin
}

async function deleteTransaction(t: Transaction) {
  const isPillar3aDeposit = account.value?.type === 'pillar_3a' && t.type === 'deposit'
  const msg = isPillar3aDeposit
    ? 'Delete this contribution? Auto-generated trade rows from the same day will also be removed.'
    : `Delete this transaction (${t.description ?? t.type})?`
  if (!confirm(msg)) return
  await accounts.deleteTransaction(accountId.value, t.id)
  await reloadAfterImport()
}
</script>

<template>
  <div class="mx-auto max-w-6xl px-6 py-10 space-y-8">
    <RouterLink
      :to="{ name: 'home' }"
      class="inline-flex items-center gap-1 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <ChevronLeft :size="16" />
      <span>All accounts</span>
    </RouterLink>

    <template v-if="account">
      <header class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
          <p class="label">{{ account.institution ?? account.type }}</p>
          <h1 class="text-2xl font-semibold tracking-tight mt-1 flex items-center gap-2">
            {{ account.name }}
            <a
              :href="`/api/accounts/${account.id}/export`"
              download
              class="text-[var(--color-text-dim)] hover:text-[var(--color-text)] transition-colors"
              :aria-label="`Export ${account.name} as JSON`"
              title="Export account as JSON"
            >
              <Download :size="16" />
            </a>
          </h1>
        </div>
        <div class="text-right">
          <p class="label">Total</p>
          <p class="text-3xl font-semibold tracking-tight tabular mt-1">
            {{ formatMinor(account.balanceMinor, account.currency) }}
          </p>
          <p
            v-if="BigInt(account.holdingsMinor) !== 0n"
            class="text-xs text-[var(--color-text-dim)] tabular mt-1"
          >
            {{ formatMinor(account.cashMinor, account.currency) }} cash &middot;
            {{ formatMinor(account.holdingsMinor, account.currency) }} holdings
          </p>
        </div>
      </header>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2">
          <NetWorthChart
            :endpoint="`/api/accounts/${account.id}/timeseries`"
            :title="account.type === 'brokerage' ? 'Value vs net deposits' : 'Account value over time'"
            :range="range"
            :mode="account.type === 'brokerage' ? 'vs-deposits' : 'total'"
            granularity="weekly"
            :currency="account.currency"
            @update:range="range = $event"
          />
        </div>
        <AllocationDonut :endpoint="`/api/accounts/${account.id}/allocation`" />
      </div>

      <PerformanceChart
        :endpoint="`/api/accounts/${account.id}/performance`"
        title="Account performance"
        :range="range"
        granularity="weekly"
        @update:range="range = $event"
      />

      <div v-if="account.type === 'pillar_3a'" class="space-y-4">
        <AllocationEditor :account-id="account.id" @saved="reloadAfterImport" />
        <div class="flex flex-wrap items-start gap-3">
          <OpeningBalanceForm
            :account-id="account.id"
            :currency="account.currency"
            @created="reloadAfterImport"
          />
          <AddContributionForm
            :account-id="account.id"
            :currency="account.currency"
            @created="reloadAfterImport"
          />
        </div>
      </div>

      <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <ImportDropzone v-if="account.type !== 'precious_metals'" :account-id="account.id" @imported="reloadAfterImport" />
        <div v-if="account.type === 'precious_metals'" class="flex items-start">
          <AddCoinForm
            :account-id="account.id"
            :currency="account.currency"
            class="w-full"
            @created="load"
          />
        </div>
        <div class="flex items-start">
          <NewTransactionForm
            :account-id="account.id"
            :currency="account.currency"
            class="w-full"
            @created="load"
          />
        </div>
      </div>

      <section v-if="holdings.length > 0" class="space-y-4">
        <h2 class="text-lg font-medium">Holdings</h2>
        <div class="card overflow-hidden">
          <table class="table">
            <thead>
              <tr>
                <th>Ticker</th>
                <th>Name</th>
                <th class="text-right w-24">Quantity</th>
                <th class="text-right w-32">Last price</th>
                <th class="text-right w-32">Value</th>
                <th class="w-24">As of</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="h in holdings" :key="h.isin">
                <tr class="cursor-pointer" @click="toggleHolding(h.isin)">
                  <td>
                    <div class="font-medium">{{ h.ticker ?? '—' }}</div>
                    <div class="text-xs text-[var(--color-text-dim)]">{{ h.isin }}</div>
                  </td>
                  <td class="text-[var(--color-text-muted)] truncate max-w-xs">{{ h.name ?? '—' }}</td>
                  <td class="text-right tabular">{{ h.quantity }}</td>
                  <td class="text-right tabular text-[var(--color-text-muted)]">
                    {{ h.priceMinor && h.priceCurrency ? formatMinor(h.priceMinor, h.priceCurrency) : '—' }}
                  </td>
                  <td class="text-right tabular font-medium">
                    {{ h.valueBaseMinor ? formatMinor(h.valueBaseMinor, h.baseCurrency) : '—' }}
                  </td>
                  <td class="text-xs text-[var(--color-text-dim)]">{{ h.priceAsOf ?? '—' }}</td>
                </tr>
                <tr v-if="expandedHolding === h.isin">
                  <td colspan="6" class="bg-[var(--color-bg)]/50 p-4">
                    <AssetPriceChart :isin="h.isin" />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <section class="space-y-4">
        <h2 class="text-lg font-medium">Transactions</h2>

        <div v-if="loading" class="card p-10 text-center text-[var(--color-text-muted)]">Loading…</div>
        <div v-else-if="transactions.length === 0" class="card p-10 text-center space-y-2">
          <div class="flex justify-center text-[var(--color-text-dim)]">
            <Inbox :size="40" />
          </div>
          <p class="font-medium">No transactions yet</p>
          <p class="text-sm text-[var(--color-text-muted)]">
            Import a CSV or add one manually.
          </p>
        </div>
        <div v-else class="card overflow-hidden">
          <table class="table">
            <thead>
              <tr>
                <th class="w-28">Date</th>
                <th class="w-28">Type</th>
                <th>Description</th>
                <th class="text-right w-32">Quantity</th>
                <th class="text-right w-32">Amount</th>
                <th class="w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="t in transactions.filter((tx) =>
                  account?.type !== 'pillar_3a' || (tx.type !== 'trade_buy' && tx.type !== 'trade_sell')
                )"
                :key="t.id"
              >
                <td class="text-[var(--color-text-muted)] tabular">{{ shortDate(t.occurredAt) }}</td>
                <td>
                  <span class="badge">{{ typeLabels[t.type ?? 'other'] ?? t.type }}</span>
                </td>
                <td>
                  <div class="truncate max-w-md">{{ t.description ?? '—' }}</div>
                  <div v-if="t.assetIsin" class="text-xs text-[var(--color-text-dim)] mt-0.5">
                    {{ t.assetIsin }}
                  </div>
                </td>
                <td class="text-right tabular text-[var(--color-text-muted)]">
                  {{ t.assetQuantity ?? '' }}
                </td>
                <td
                  class="text-right tabular font-medium"
                  :class="BigInt(t.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                >
                  {{ formatMinor(t.amountMinor, t.currency) }}
                </td>
                <td class="text-right">
                  <button
                    class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-negative)] hover:bg-[var(--color-surface-hover)]"
                    type="button"
                    aria-label="Delete transaction"
                    @click="deleteTransaction(t)"
                  >
                    <Trash2 :size="14" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Account not found.</p>
  </div>
</template>
