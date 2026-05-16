<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useAccountsStore, type Account, type Transaction, type Holding } from '@/stores/accounts'
import { formatMinor, formatQuantity } from '@/lib/money'
import NewTransactionForm from '@/components/forms/NewTransactionForm.vue'
import ImportDropzone from '@/components/ImportDropzone.vue'
import AddCoinForm from '@/components/forms/AddCoinForm.vue'
import AllocationEditor from '@/components/AllocationEditor.vue'
import AddContributionForm from '@/components/forms/AddContributionForm.vue'
import OpeningBalanceForm from '@/components/forms/OpeningBalanceForm.vue'
import NetWorthChart from '@/components/charts/NetWorthChart.vue'
import AllocationDonut from '@/components/charts/AllocationDonut.vue'
import PerformanceChart from '@/components/charts/PerformanceChart.vue'
import EditTransactionForm from '@/components/forms/EditTransactionForm.vue'
import EditAccountForm from '@/components/forms/EditAccountForm.vue'
import RecurringTransactionsPanel from '@/components/panels/RecurringTransactionsPanel.vue'
import DateField from '@/components/ui/DateField.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SelectField from '@/components/ui/SelectField.vue'
import type { ColumnDef, SortingState } from '@tanstack/vue-table'
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
const range = ref<'1w' | '1m' | '3m' | '6m' | 'ytd' | '1y' | '2y' | '5y' | 'all'>('ytd')

// Filter / pagination / sort state.
const page = ref(1)
const pageSize = ref(25)
const filterType = ref<string | null>(null)
const filterCategory = ref<string | null>(null)
const filterFrom = ref('')
const filterTo = ref('')
const filterQ = ref('')
// Date desc is the default — newest transactions first. Empty = backend default.
const sorting = ref<SortingState>([{ id: 'occurredAt', desc: true }])
const sortParam = computed(() => {
  const s = sorting.value[0]
  return s ? `${s.id}:${s.desc ? 'desc' : 'asc'}` : undefined
})

const totalPages = computed(() => Math.max(1, Math.ceil(totalTransactions.value / pageSize.value)))
const activeFilterColumns = computed(() => {
  const ids: string[] = []
  if (filterFrom.value !== '' || filterTo.value !== '') ids.push('occurredAt')
  if (filterType.value) ids.push('type')
  if (filterQ.value !== '' || filterCategory.value) ids.push('description')
  return ids
})
const hasFilters = computed(() => activeFilterColumns.value.length > 0)

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
        sort: sortParam.value,
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

// Debounced auto-reload when filters change. Long enough that a user typing a
// 4-5 char search doesn't trigger an in-flight request per keystroke.
let filterTimer: ReturnType<typeof setTimeout> | null = null
function onFilterChange() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    page.value = 1
    void load()
  }, 400)
}

function clearFilters() {
  filterType.value = null
  filterCategory.value = null
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
  filterType.value = null
  filterCategory.value = null
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

const holdingsColumns = computed<ColumnDef<Holding, unknown>[]>(() => [
  { id: 'ticker', accessorFn: (h) => h.ticker ?? '', header: 'Ticker', enableSorting: true },
  { id: 'name', accessorFn: (h) => h.name ?? '', header: 'Name', enableSorting: true },
  {
    id: 'quantity',
    accessorFn: (h) => Number(h.quantity),
    header: 'Quantity',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-24', cellClass: 'tabular' },
  },
  {
    id: 'price',
    accessorFn: (h) => (h.priceMinor ? Number(h.priceMinor) : 0),
    header: 'Last price',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular text-[var(--color-text-muted)]' },
  },
  {
    id: 'value',
    accessorFn: (h) => (h.valueBaseMinor ? Number(h.valueBaseMinor) : 0),
    header: 'Value',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular font-medium' },
  },
  {
    id: 'priceAsOf',
    accessorFn: (h) => h.priceAsOf ?? '',
    header: 'As of',
    enableSorting: true,
    meta: { headerClass: 'w-24', cellClass: 'text-xs text-[var(--color-text-dim)]' },
  },
])

// AccountView's transactions table is server-paginated, so sort here applies only
// to the visible page — still useful for "biggest item on this page".
const transactionColumns = computed<ColumnDef<Transaction, unknown>[]>(() => [
  {
    id: 'occurredAt',
    accessorKey: 'occurredAt',
    header: 'Date',
    enableSorting: true,
    meta: { headerClass: 'w-28', cellClass: 'text-[var(--color-text-muted)] tabular' },
  },
  {
    id: 'type',
    accessorFn: (t) => typeLabels[t.type ?? 'other'] ?? t.type,
    header: 'Type',
    enableSorting: true,
    meta: { headerClass: 'w-28' },
  },
  {
    id: 'description',
    accessorFn: (t) => t.description ?? '',
    header: 'Description',
    enableSorting: true,
  },
  {
    id: 'quantity',
    accessorFn: (t) => Number(t.assetQuantity ?? 0),
    header: 'Quantity',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular text-[var(--color-text-muted)]' },
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
    id: 'actions',
    header: '',
    enableSorting: false,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-10' },
  },
])

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
        <DataTable
          v-if="activeTab === 'holdings' && holdings.length > 0"
          :data="holdings"
          :columns="holdingsColumns"
        >
          <template #cell-ticker="{ row }">
            <RouterLink :to="{ name: 'asset', params: { isin: row.isin } }" class="font-medium hover:text-[var(--color-accent)] transition-colors">
              {{ row.ticker ?? '—' }}
            </RouterLink>
            <div class="text-xs text-[var(--color-text-dim)]">{{ row.isin }}</div>
          </template>
          <template #cell-name="{ row }">
            <span class="text-[var(--color-text-muted)] truncate max-w-xs">{{ row.name ?? '—' }}</span>
          </template>
          <template #cell-quantity="{ row }">
            {{ formatQuantity(row.quantity) }}
          </template>
          <template #cell-price="{ row }">
            {{ row.priceMinor && row.priceCurrency ? formatMinor(row.priceMinor, row.priceCurrency) : '—' }}
          </template>
          <template #cell-value="{ row }">
            {{ row.valueBaseMinor ? formatMinor(row.valueBaseMinor, row.baseCurrency) : '—' }}
          </template>
          <template #cell-priceAsOf="{ row }">
            {{ row.priceAsOf ?? '—' }}
          </template>
        </DataTable>

        <!-- Transactions tab -->
        <div v-if="activeTab === 'transactions'" class="space-y-4">
          <div v-if="hasFilters" class="flex items-center justify-end">
            <button type="button" class="btn btn-ghost text-xs" @click="clearFilters">Clear filters</button>
          </div>

          <div
            v-if="!loading && transactions.length === 0 && !hasFilters"
            class="card p-10 text-center space-y-2"
          >
            <div class="flex justify-center text-[var(--color-text-dim)]">
              <Inbox :size="40" />
            </div>
            <p class="font-medium">No transactions yet</p>
            <p class="text-sm text-[var(--color-text-muted)]">Import a CSV or add one manually.</p>
          </div>
          <DataTable
            v-else
            :data="transactions"
            :columns="transactionColumns"
            show-filters
            manual-filtering
            manual-sorting
            :active-filter-columns="activeFilterColumns"
            :sorting="sorting"
            :loading="loading"
            :page="page"
            :page-size="pageSize"
            :total="totalTransactions"
            empty-text="No transactions match the filters."
            @update:page="goToPage"
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
              <SelectField
                v-model="filterType"
                :options="Object.entries(typeLabels).map(([value, label]) => ({ value, label }))"
                allow-empty
                empty-label="All types"
                clearable
                size="sm"
                @update:model-value="onFilterChange"
              />
            </template>
            <template #filter-description>
              <div class="space-y-1">
                <input
                  v-model="filterQ"
                  placeholder="Search description or ISIN…"
                  class="input text-sm"
                  @input="onFilterChange"
                />
                <SelectField
                  v-if="features.showCategories"
                  v-model="filterCategory"
                  :options="CATEGORIES.map((c) => ({ value: c.value, label: c.label }))"
                  allow-empty
                  empty-label="All categories"
                  clearable
                  size="sm"
                  @update:model-value="onFilterChange"
                />
              </div>
            </template>
            <template #cell-occurredAt="{ row }">
              {{ shortDate(row.occurredAt) }}
            </template>
            <template #cell-type="{ row }">
              <span class="badge">{{ typeLabels[row.type ?? 'other'] ?? row.type }}</span>
            </template>
            <template #cell-description="{ row }">
              <RouterLink
                :to="{ name: 'transaction', params: { accountId: row.accountId, id: row.id } }"
                class="block truncate max-w-md hover:text-[var(--color-accent)] transition-colors"
              >
                {{ row.description ?? '—' }}
              </RouterLink>
              <div class="flex flex-wrap items-center gap-1.5 mt-0.5 text-xs">
                <span
                  v-if="features.showCategories && categoryMeta(row.category)"
                  class="inline-flex items-center gap-1.5"
                  :title="categoryMeta(row.category)!.label"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(row.category)!.color }"></span>
                  <span class="text-[var(--color-text-muted)]">{{ categoryMeta(row.category)!.label }}</span>
                </span>
                <span v-if="row.assetIsin" class="text-[var(--color-text-dim)]">{{ row.assetIsin }}</span>
                <RouterLink
                  v-for="tag in (row.tags ?? [])"
                  :key="tag"
                  :to="{ name: 'tag', params: { tag } }"
                  class="text-[10px] rounded px-1.5 py-0.5 hover:opacity-80 transition-opacity"
                  style="background-color: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent);"
                  @click.stop
                >
                  {{ tag }}
                </RouterLink>
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
            <template #cell-actions="{ row }">
              <div class="flex justify-end gap-0.5">
                <button
                  class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                  type="button"
                  aria-label="Edit transaction"
                  @click="startEditTransaction(row)"
                >
                  <Pencil :size="14" />
                </button>
                <button
                  class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-negative)] hover:bg-[var(--color-surface-hover)]"
                  type="button"
                  aria-label="Delete transaction"
                  @click="deleteTransaction(row)"
                >
                  <Trash2 :size="14" />
                </button>
              </div>
            </template>
          </DataTable>
        </div>
      </section>
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Account not found.</p>

    <EditTransactionForm v-model:transaction="editingTransaction" @saved="onTransactionSaved" />
    <EditAccountForm v-model:account="editingAccount" @saved="() => toasts.success('Account updated')" />
  </div>
</template>
