<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useAccountsStore, type Account, type Transaction, type Holding } from '@/stores/accounts'
import { formatMinor, formatQuantity } from '@/lib/money'
import NewTransactionForm from '@/components/NewTransactionForm.vue'
import ImportDropzone from '@/components/ImportDropzone.vue'
import HoldingsTable from '@/components/HoldingsTable.vue'
import AddCoinForm from '@/components/AddCoinForm.vue'
import AllocationEditor from '@/components/AllocationEditor.vue'
import AddContributionForm from '@/components/AddContributionForm.vue'
import OpeningBalanceForm from '@/components/OpeningBalanceForm.vue'
import NetWorthChart from '@/components/NetWorthChart.vue'
import AllocationDonut from '@/components/AllocationDonut.vue'
import PerformanceChart from '@/components/PerformanceChart.vue'
import EditTransactionForm from '@/components/EditTransactionForm.vue'
import EditAccountForm from '@/components/EditAccountForm.vue'
import RecurringTransactionsPanel from '@/components/RecurringTransactionsPanel.vue'
import DateField from '@/components/DateField.vue'
import { useToastsStore } from '@/stores/toasts'
import { CATEGORIES, categoryMeta } from '@/lib/categories'
import { featuresFor } from '@/lib/accountFeatures'
import { ChevronLeft, Download, Inbox, Pencil, Trash2 } from 'lucide-vue-next'

const route = useRoute()
const accounts = useAccountsStore()
const toasts = useToastsStore()

const accountId = computed(() => String(route.params.id))
const account = computed(() => accounts.accounts.find((a) => a.id === accountId.value))
const features = computed(() => featuresFor(account.value?.type ?? 'other'))
const transactions = ref<Transaction[]>([])
const totalTransactions = ref(0)
const holdings = ref<Holding[]>([])
const loading = ref(false)
const range = ref<'1w' | '1m' | '6mo' | '1y' | '2y' | '5y' | 'all'>('1y')

// Filter / pagination state.
const page = ref(1)
const pageSize = ref(25)
const filterType = ref('')
const filterCategory = ref('')
const filterFrom = ref('')
const filterTo = ref('')
const filterQ = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(totalTransactions.value / pageSize.value)))
const hasFilters = computed(() =>
  filterType.value !== '' || filterCategory.value !== '' || filterFrom.value !== '' || filterTo.value !== '' || filterQ.value !== '',
)

async function load() {
  if (!accountId.value) return
  loading.value = true
  try {
    const [tx, hd] = await Promise.all([
      accounts.fetchTransactions(accountId.value, {
        page: page.value,
        pageSize: pageSize.value,
        type: filterType.value || undefined,
        category: filterCategory.value || undefined,
        from: filterFrom.value || undefined,
        to: filterTo.value || undefined,
        q: filterQ.value || undefined,
      }),
      accounts.fetchHoldings(accountId.value),
    ])
    transactions.value = tx.items
    totalTransactions.value = tx.total
    holdings.value = hd
  } finally {
    loading.value = false
  }
}

// Debounced auto-reload when filters change (avoid a request on every keystroke).
let filterTimer: ReturnType<typeof setTimeout> | null = null
function onFilterChange() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 250)
}

function clearFilters() {
  filterType.value = ''
  filterCategory.value = ''
  filterFrom.value = ''
  filterTo.value = ''
  filterQ.value = ''
  page.value = 1
  void load()
}

function goToPage(p: number) {
  page.value = Math.min(totalPages.value, Math.max(1, p))
  void load()
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

watch(accountId, () => {
  // Reset filters and pagination when navigating between accounts so the user
  // doesn't carry over filters that may not make sense (e.g. an asset ISIN
  // search on an account that doesn't hold that asset).
  page.value = 1
  filterType.value = ''
  filterCategory.value = ''
  filterFrom.value = ''
  filterTo.value = ''
  filterQ.value = ''
  activeTab.value = 'holdings'
  void load()
})

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
  const d = new Date(iso)
  return d.toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const editingAccount = ref<Account | null>(null)
// Holdings is the default tab — it's the primary content for investment accounts.
// For cash accounts (where the holdings tab is gated off) we drop straight to
// Transactions on load and on holdings re-fetch.
const activeTab = ref<'holdings' | 'transactions' | 'recurring'>('holdings')

watch([holdings, features], ([h, f]) => {
  if (!f.showHoldings) {
    if (activeTab.value === 'holdings') activeTab.value = 'transactions'
    return
  }
  if (h.length === 0 && activeTab.value === 'holdings') {
    activeTab.value = 'transactions'
  }
}, { immediate: false })

const editingTransaction = ref<Transaction | null>(null)
function startEditTransaction(t: Transaction) {
  editingTransaction.value = t
}
async function onTransactionSaved() {
  toasts.success('Transaction updated')
  await load()
  await accounts.fetchAll()
}

async function deleteTransaction(t: Transaction) {
  const isPillar3aDeposit = account.value?.type === 'pillar_3a'
    && (t.type === 'deposit' || t.type === 'opening_balance')
  const msg = isPillar3aDeposit
    ? 'Delete this contribution? Auto-generated trade rows from the same day will also be removed.'
    : `Delete this transaction (${t.description ?? t.type})?`
  if (!confirm(msg)) return
  try {
    const res = await accounts.deleteTransaction(accountId.value, t.id)
    const cascaded = res.cascadedTradeCount > 0
      ? ` (${res.cascadedTradeCount} linked trade rows also removed)`
      : ''
    toasts.success('Transaction deleted' + cascaded)
    await reloadAfterImport()
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}
</script>

<template>
  <div class="space-y-8">
    <RouterLink
      :to="{ name: 'accounts' }"
      class="inline-flex items-center gap-1 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <ChevronLeft :size="16" />
      <span>All accounts</span>
    </RouterLink>

    <template v-if="account">
      <!-- Header: meta + total on top, action bar below -->
      <header class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
          <div>
            <p class="label">{{ account.institution ?? account.type.replace('_', ' ') }}</p>
            <h1 class="text-2xl font-semibold tracking-tight mt-1">{{ account.name }}</h1>
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
        </div>

        <div class="flex flex-wrap items-center gap-2 pt-2 border-t" style="border-color: var(--color-border);">
          <!-- Primary actions: vary by account type -->
          <template v-if="account.type === 'pillar_3a'">
            <AddContributionForm
              :account-id="account.id"
              :currency="account.currency"
              @created="reloadAfterImport"
            />
            <OpeningBalanceForm
              v-if="!account.hasOpeningBalance"
              :account-id="account.id"
              :currency="account.currency"
              @created="reloadAfterImport"
            />
          </template>
          <template v-else>
            <NewTransactionForm
              :account-id="account.id"
              :currency="account.currency"
              :show-categories="features.showCategories"
              @created="load"
            />
            <AddCoinForm
              v-if="account.type === 'precious_metals'"
              :account-id="account.id"
              :currency="account.currency"
              @created="load"
            />
            <ImportDropzone
              v-if="account.type !== 'precious_metals'"
              :account-id="account.id"
              @imported="reloadAfterImport"
            />
          </template>

          <!-- Secondary actions: pushed to the right -->
          <div class="ml-auto flex items-center gap-1">
            <button class="btn btn-ghost" type="button" @click="editingAccount = account">
              <Pencil :size="14" />
              <span>Edit</span>
            </button>
            <a
              :href="`/api/accounts/${account.id}/export`"
              download
              class="btn btn-ghost"
              :aria-label="`Export ${account.name} as JSON`"
              title="Export account as JSON"
            >
              <Download :size="14" />
              <span>Export</span>
            </a>
          </div>
        </div>
      </header>

      <!-- Charts — NetWorth always renders (any account has value over time);
           allocation + performance are gated on account type. -->
      <div
        class="grid grid-cols-1 gap-4"
        :class="features.showAllocation ? 'lg:grid-cols-3' : ''"
      >
        <div :class="features.showAllocation ? 'lg:col-span-2' : ''">
          <NetWorthChart
            :endpoint="`/api/accounts/${account.id}/timeseries`"
            :title="account.type === 'brokerage' ? 'Value vs net deposits' : 'Account value over time'"
            :range="range"
            :mode="account.type === 'brokerage' ? 'vs-deposits' : 'total'"
            :currency="account.currency"
            :direction-coloring="features.showPerformance"
            @update:range="range = $event"
          />
        </div>
        <AllocationDonut
          v-if="features.showAllocation"
          :endpoint="`/api/accounts/${account.id}/allocation`"
        />
      </div>

      <PerformanceChart
        v-if="features.showPerformance"
        :endpoint="`/api/accounts/${account.id}/performance`"
        title="Account performance"
        :range="range"
        @update:range="range = $event"
      />

      <!-- Pillar 3a strategy editor stays prominent as it's how contributions get split -->
      <AllocationEditor
        v-if="account.type === 'pillar_3a'"
        :account-id="account.id"
        @saved="reloadAfterImport"
      />

      <!-- Tabs — only the ones that make sense for this account type are shown. -->
      <section class="space-y-4">
        <div class="flex items-center border-b" style="border-color: var(--color-border);">
          <button
            v-if="features.showHoldings && holdings.length > 0"
            type="button"
            class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
            :class="activeTab === 'holdings'
              ? 'text-[var(--color-text)] border-[var(--color-accent)]'
              : 'text-[var(--color-text-muted)] border-transparent hover:text-[var(--color-text)]'"
            @click="activeTab = 'holdings'"
          >
            Holdings <span class="text-[var(--color-text-dim)] ml-1">{{ holdings.length }}</span>
          </button>
          <button
            type="button"
            class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
            :class="activeTab === 'transactions'
              ? 'text-[var(--color-text)] border-[var(--color-accent)]'
              : 'text-[var(--color-text-muted)] border-transparent hover:text-[var(--color-text)]'"
            @click="activeTab = 'transactions'"
          >
            Transactions <span class="text-[var(--color-text-dim)] ml-1">{{ totalTransactions }}</span>
          </button>
          <button
            v-if="features.showRecurring"
            type="button"
            class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
            :class="activeTab === 'recurring'
              ? 'text-[var(--color-text)] border-[var(--color-accent)]'
              : 'text-[var(--color-text-muted)] border-transparent hover:text-[var(--color-text)]'"
            @click="activeTab = 'recurring'"
          >
            Recurring
          </button>
        </div>

        <!-- Recurring tab -->
        <RecurringTransactionsPanel
          v-if="activeTab === 'recurring' && account && features.showRecurring"
          :account-id="account.id"
          :currency="account.currency"
          :show-categories="features.showCategories"
          @changed="reloadAfterImport"
        />

        <!-- Holdings tab -->
        <div v-if="activeTab === 'holdings' && holdings.length > 0" class="card overflow-hidden">
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
              <tr v-for="h in holdings" :key="h.isin">
                <td>
                  <RouterLink :to="{ name: 'asset', params: { isin: h.isin } }" class="font-medium hover:text-[var(--color-accent)] transition-colors">
                    {{ h.ticker ?? '—' }}
                  </RouterLink>
                  <div class="text-xs text-[var(--color-text-dim)]">{{ h.isin }}</div>
                </td>
                <td class="text-[var(--color-text-muted)] truncate max-w-xs">{{ h.name ?? '—' }}</td>
                <td class="text-right tabular">{{ formatQuantity(h.quantity) }}</td>
                <td class="text-right tabular text-[var(--color-text-muted)]">
                  {{ h.priceMinor && h.priceCurrency ? formatMinor(h.priceMinor, h.priceCurrency) : '—' }}
                </td>
                <td class="text-right tabular font-medium">
                  {{ h.valueBaseMinor ? formatMinor(h.valueBaseMinor, h.baseCurrency) : '—' }}
                </td>
                <td class="text-xs text-[var(--color-text-dim)]">{{ h.priceAsOf ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Transactions tab -->
        <div v-if="activeTab === 'transactions'" class="space-y-4">
          <div class="card p-3">
            <div class="flex flex-wrap items-end gap-3">
              <div class="space-y-1 flex-1 min-w-[10rem]">
                <label class="label">Search</label>
                <input
                  v-model="filterQ"
                  placeholder="Description or ISIN"
                  class="input"
                  @input="onFilterChange"
                />
              </div>
              <div class="space-y-1">
                <label class="label">Type</label>
                <select v-model="filterType" class="input" @change="onFilterChange">
                  <option value="">All types</option>
                  <option v-for="(label, value) in typeLabels" :key="value" :value="value">{{ label }}</option>
                </select>
              </div>
              <div v-if="features.showCategories" class="space-y-1">
                <label class="label">Category</label>
                <select v-model="filterCategory" class="input" @change="onFilterChange">
                  <option value="">All categories</option>
                  <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
              </div>
              <div class="space-y-1">
                <label class="label">From</label>
                <DateField v-model="filterFrom" clearable placeholder="From" @update:model-value="onFilterChange" />
              </div>
              <div class="space-y-1">
                <label class="label">To</label>
                <DateField v-model="filterTo" clearable placeholder="To" @update:model-value="onFilterChange" />
              </div>
              <button
                v-if="hasFilters"
                type="button"
                class="btn btn-ghost"
                @click="clearFilters"
              >
                Clear
              </button>
            </div>
          </div>

          <div v-if="loading" class="card p-10 text-center text-[var(--color-text-muted)]">Loading…</div>
          <div v-else-if="transactions.length === 0" class="card p-10 text-center space-y-2">
            <div class="flex justify-center text-[var(--color-text-dim)]">
              <Inbox :size="40" />
            </div>
            <p class="font-medium">{{ hasFilters ? 'No transactions match the filters' : 'No transactions yet' }}</p>
            <p class="text-sm text-[var(--color-text-muted)]">
              {{ hasFilters ? 'Try clearing some filters.' : 'Import a CSV or add one manually.' }}
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
                <tr v-for="t in transactions" :key="t.id">
                  <td class="text-[var(--color-text-muted)] tabular">{{ shortDate(t.occurredAt) }}</td>
                  <td>
                    <span class="badge">{{ typeLabels[t.type ?? 'other'] ?? t.type }}</span>
                  </td>
                  <td>
                    <RouterLink
                      :to="{ name: 'transaction', params: { accountId: t.accountId, id: t.id } }"
                      class="block truncate max-w-md hover:text-[var(--color-accent)] transition-colors"
                    >
                      {{ t.description ?? '—' }}
                    </RouterLink>
                    <div class="flex flex-wrap items-center gap-1.5 mt-0.5 text-xs">
                      <span
                        v-if="features.showCategories && categoryMeta(t.category)"
                        class="inline-flex items-center gap-1.5"
                        :title="categoryMeta(t.category)!.label"
                      >
                        <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(t.category)!.color }"></span>
                        <span class="text-[var(--color-text-muted)]">{{ categoryMeta(t.category)!.label }}</span>
                      </span>
                      <span v-if="t.assetIsin" class="text-[var(--color-text-dim)]">{{ t.assetIsin }}</span>
                      <RouterLink
                        v-for="tag in (t.tags ?? [])"
                        :key="tag"
                        :to="{ name: 'tag', params: { tag } }"
                        class="text-[10px] rounded px-1.5 py-0.5 hover:opacity-80 transition-opacity"
                        style="background-color: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent);"
                        @click.stop
                      >
                        {{ tag }}
                      </RouterLink>
                    </div>
                  </td>
                  <td class="text-right tabular text-[var(--color-text-muted)]">
                    {{ formatQuantity(t.assetQuantity) }}
                  </td>
                  <td
                    class="text-right tabular font-medium"
                    :class="BigInt(t.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                  >
                    {{ formatMinor(t.amountMinor, t.currency) }}
                  </td>
                  <td class="text-right">
                    <div class="flex justify-end gap-0.5">
                      <button
                        class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                        type="button"
                        aria-label="Edit transaction"
                        @click="startEditTransaction(t)"
                      >
                        <Pencil :size="14" />
                      </button>
                      <button
                        class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-negative)] hover:bg-[var(--color-surface-hover)]"
                        type="button"
                        aria-label="Delete transaction"
                        @click="deleteTransaction(t)"
                      >
                        <Trash2 :size="14" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="totalTransactions > pageSize" class="flex items-center justify-between gap-3">
            <span class="text-xs text-[var(--color-text-muted)] tabular">
              Page {{ page }} of {{ totalPages }}
            </span>
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="btn btn-ghost text-xs"
                :disabled="page <= 1 || loading"
                @click="goToPage(page - 1)"
              >Previous</button>
              <button
                type="button"
                class="btn btn-ghost text-xs"
                :disabled="page >= totalPages || loading"
                @click="goToPage(page + 1)"
              >Next</button>
            </div>
          </div>
        </div>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Account not found.</p>

    <EditTransactionForm v-model:transaction="editingTransaction" @saved="onTransactionSaved" />
    <EditAccountForm v-model:account="editingAccount" @saved="() => toasts.success('Account updated')" />
  </div>
</template>
