<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { describeSchedule, type RecurringFrequency } from '@/lib/recurring'
import { Search, Wallet, Receipt, TrendingUp, Repeat } from 'lucide-vue-next'

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
interface SearchResponse {
  accounts: AccountResult[]
  transactions: TransactionResult[]
  assets: AssetResult[]
  recurring: RecurringResult[]
}

const router = useRouter()

const query = ref('')
const results = ref<SearchResponse>({ accounts: [], transactions: [], assets: [], recurring: [] })
const open = ref(false)
const loading = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

// Flattened list with target route, for keyboard navigation.
interface FlatItem {
  kind: 'account' | 'transaction' | 'asset' | 'recurring'
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  route: { name: string; params?: any }
  data: AccountResult | TransactionResult | AssetResult | RecurringResult
}

const flat = computed<FlatItem[]>(() => {
  const out: FlatItem[] = []
  for (const a of results.value.accounts) {
    out.push({ kind: 'account', route: { name: 'account', params: { id: a.id } }, data: a })
  }
  for (const a of results.value.assets) {
    out.push({ kind: 'asset', route: { name: 'asset', params: { isin: a.isin } }, data: a })
  }
  for (const t of results.value.transactions) {
    out.push({ kind: 'transaction', route: { name: 'account', params: { id: t.accountId } }, data: t })
  }
  for (const r of results.value.recurring) {
    out.push({ kind: 'recurring', route: { name: 'account', params: { id: r.accountId } }, data: r })
  }
  return out
})

const hasResults = computed(() => flat.value.length > 0)
const selectedIndex = ref(0)

watch(flat, () => { selectedIndex.value = 0 })

// Debounced fetch on query change.
let timer: ReturnType<typeof setTimeout> | null = null
watch(query, (q) => {
  if (timer) clearTimeout(timer)
  if (q.trim().length < 2) {
    results.value = { accounts: [], transactions: [], assets: [], recurring: [] }
    loading.value = false
    return
  }
  loading.value = true
  timer = setTimeout(async () => {
    try {
      results.value = await api.get<SearchResponse>(`/api/search?q=${encodeURIComponent(q.trim())}`)
    } finally {
      loading.value = false
    }
  }, 200)
})

function focusInput() {
  inputRef.value?.focus()
  inputRef.value?.select()
  open.value = true
}

function close() {
  open.value = false
}

function go(item: FlatItem) {
  router.push(item.route)
  query.value = ''
  close()
}

function onKeydown(ev: KeyboardEvent) {
  // Cmd/Ctrl+K to focus from anywhere in the app.
  if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 'k') {
    ev.preventDefault()
    focusInput()
    return
  }

  if (!open.value || !hasResults.value) return

  if (ev.key === 'ArrowDown') {
    ev.preventDefault()
    selectedIndex.value = (selectedIndex.value + 1) % flat.value.length
  } else if (ev.key === 'ArrowUp') {
    ev.preventDefault()
    selectedIndex.value = (selectedIndex.value - 1 + flat.value.length) % flat.value.length
  } else if (ev.key === 'Enter') {
    ev.preventDefault()
    const item = flat.value[selectedIndex.value]
    if (item) go(item)
  } else if (ev.key === 'Escape') {
    ev.preventDefault()
    close()
    inputRef.value?.blur()
  }
}

function onClickOutside(ev: MouseEvent) {
  const target = ev.target as Node | null
  if (!target) return
  const root = (ev.currentTarget as Document)
  // close if click was outside the search container
  const container = document.getElementById('header-search-root')
  if (container && !container.contains(target)) {
    close()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  document.addEventListener('mousedown', onClickOutside)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.removeEventListener('mousedown', onClickOutside)
})

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const flatGroupBoundaries = computed(() => {
  const out: Record<number, string> = {}
  const counts = {
    account: results.value.accounts.length,
    asset: results.value.assets.length,
    transaction: results.value.transactions.length,
    recurring: results.value.recurring.length,
  }
  let idx = 0
  if (counts.account > 0) { out[idx] = 'Accounts'; idx += counts.account }
  if (counts.asset > 0) { out[idx] = 'Assets'; idx += counts.asset }
  if (counts.transaction > 0) { out[idx] = 'Transactions'; idx += counts.transaction }
  if (counts.recurring > 0) { out[idx] = 'Recurring' }
  return out
})

defineExpose({ focusInput })
</script>

<template>
  <div id="header-search-root" class="relative w-full max-w-md">
    <div class="relative">
      <Search :size="14" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-dim)] pointer-events-none" />
      <input
        ref="inputRef"
        v-model="query"
        type="text"
        placeholder="Search… (⌘K)"
        class="input pl-8 pr-10 text-sm py-1.5"
        autocomplete="off"
        @focus="open = true"
      />
      <kbd
        v-if="!open && query === ''"
        class="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] px-1.5 py-0.5 rounded text-[var(--color-text-dim)] border border-[var(--color-border)] bg-[var(--color-bg)] hidden sm:inline-block"
      >⌘K</kbd>
    </div>

    <!-- Dropdown -->
    <div
      v-if="open && (query.length >= 2)"
      class="absolute top-full left-0 right-0 mt-1.5 card shadow-xl overflow-hidden z-50 max-h-[28rem] overflow-y-auto"
    >
      <p v-if="loading && !hasResults" class="text-sm text-[var(--color-text-muted)] p-4 text-center">Searching…</p>

      <p v-else-if="!hasResults" class="text-sm text-[var(--color-text-muted)] p-4 text-center">
        No matches for "<span class="text-[var(--color-text)]">{{ query }}</span>".
      </p>

      <ul v-else class="py-1">
        <template v-for="(item, i) in flat" :key="`${item.kind}-${i}`">
          <li
            v-if="flatGroupBoundaries[i]"
            class="px-3 pt-2.5 pb-1 text-[10px] uppercase tracking-wider text-[var(--color-text-dim)]"
          >
            {{ flatGroupBoundaries[i] }}
          </li>
          <li>
            <button
              type="button"
              class="w-full text-left px-3 py-2 flex items-center gap-3 text-sm transition-colors"
              :class="i === selectedIndex ? 'bg-[var(--color-surface-hover)]' : 'hover:bg-[var(--color-surface-hover)]'"
              @click="go(item)"
              @mouseenter="selectedIndex = i"
            >
              <!-- Account result -->
              <template v-if="item.kind === 'account'">
                <Wallet :size="14" class="text-[var(--color-accent)] shrink-0" />
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">{{ (item.data as AccountResult).name }}</div>
                  <div class="text-xs text-[var(--color-text-dim)] truncate">
                    {{ (item.data as AccountResult).institution ?? (item.data as AccountResult).type }}
                  </div>
                </div>
                <span class="text-xs text-[var(--color-text-muted)]">{{ (item.data as AccountResult).currency }}</span>
              </template>

              <!-- Asset result -->
              <template v-else-if="item.kind === 'asset'">
                <TrendingUp :size="14" class="text-[var(--color-highlight)] shrink-0" />
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">{{ (item.data as AssetResult).ticker ?? (item.data as AssetResult).name ?? (item.data as AssetResult).isin }}</div>
                  <div class="text-xs text-[var(--color-text-dim)] truncate">{{ (item.data as AssetResult).name ?? (item.data as AssetResult).isin }}</div>
                </div>
                <span v-if="(item.data as AssetResult).currency" class="text-xs text-[var(--color-text-muted)]">{{ (item.data as AssetResult).currency }}</span>
              </template>

              <!-- Transaction result -->
              <template v-else-if="item.kind === 'transaction'">
                <Receipt :size="14" class="text-[var(--color-text-muted)] shrink-0" />
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">{{ (item.data as TransactionResult).description ?? '—' }}</div>
                  <div class="text-xs text-[var(--color-text-dim)] truncate flex items-center gap-1.5">
                    <span>{{ (item.data as TransactionResult).accountName }}</span>
                    <span>·</span>
                    <span>{{ shortDate((item.data as TransactionResult).occurredAt) }}</span>
                    <span
                      v-if="categoryMeta((item.data as TransactionResult).category)"
                      class="inline-flex items-center gap-1"
                    >
                      <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta((item.data as TransactionResult).category)!.color }"></span>
                      <span>{{ categoryMeta((item.data as TransactionResult).category)!.label }}</span>
                    </span>
                  </div>
                </div>
                <span
                  class="text-xs tabular shrink-0"
                  :class="BigInt((item.data as TransactionResult).amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                >
                  {{ formatMinor((item.data as TransactionResult).amountMinor, (item.data as TransactionResult).currency) }}
                </span>
              </template>

              <!-- Recurring result -->
              <template v-else-if="item.kind === 'recurring'">
                <Repeat :size="14" class="text-[var(--color-text-muted)] shrink-0" />
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate" :class="!(item.data as RecurringResult).active ? 'opacity-60' : ''">
                    {{ (item.data as RecurringResult).description }}
                  </div>
                  <div class="text-xs text-[var(--color-text-dim)] truncate">
                    {{ (item.data as RecurringResult).accountName }} · {{ describeSchedule(item.data as never) }}
                  </div>
                </div>
                <span
                  class="text-xs tabular shrink-0"
                  :class="BigInt((item.data as RecurringResult).amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
                >
                  {{ formatMinor((item.data as RecurringResult).amountMinor, (item.data as RecurringResult).currency) }}
                </span>
              </template>
            </button>
          </li>
        </template>
      </ul>
    </div>
  </div>
</template>
