<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { describeSchedule, type RecurringFrequency } from '@/lib/recurring'
import {
  Search, Wallet, Receipt, TrendingUp, Repeat, Tag as TagIcon, ArrowRight, X,
} from 'lucide-vue-next'

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

const router = useRouter()

const open = ref(false)
const query = ref('')
const results = ref<SearchResponse>({ accounts: [], transactions: [], assets: [], recurring: [], tags: [] })
const loading = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

// Flat list across all groups — drives keyboard navigation.
interface FlatItem {
  kind: 'account' | 'asset' | 'tag' | 'transaction' | 'recurring'
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  route: { name: string; params?: any }
  data: AccountResult | TransactionResult | AssetResult | RecurringResult | TagResult
}

const flat = computed<FlatItem[]>(() => {
  const out: FlatItem[] = []
  for (const a of results.value.accounts) {
    out.push({ kind: 'account', route: { name: 'account', params: { id: a.id } }, data: a })
  }
  for (const a of results.value.assets) {
    out.push({ kind: 'asset', route: { name: 'asset', params: { isin: a.isin } }, data: a })
  }
  for (const t of results.value.tags) {
    out.push({ kind: 'tag', route: { name: 'tag', params: { tag: t.tag } }, data: t })
  }
  for (const t of results.value.transactions) {
    out.push({
      kind: 'transaction',
      route: { name: 'transaction', params: { accountId: t.accountId, id: t.id } },
      data: t,
    })
  }
  for (const r of results.value.recurring) {
    out.push({ kind: 'recurring', route: { name: 'account', params: { id: r.accountId } }, data: r })
  }
  return out
})

const hasResults = computed(() => flat.value.length > 0)
const selectedIndex = ref(0)
watch(flat, () => { selectedIndex.value = 0 })

let timer: ReturnType<typeof setTimeout> | null = null
watch(query, (q) => {
  if (timer) clearTimeout(timer)
  if (q.trim().length < 2) {
    results.value = { accounts: [], transactions: [], assets: [], recurring: [], tags: [] }
    loading.value = false
    return
  }
  loading.value = true
  timer = setTimeout(async () => {
    try {
      results.value = await api.get<SearchResponse>(
        `/api/search?q=${encodeURIComponent(q.trim())}`,
      )
    } finally {
      loading.value = false
    }
  }, 180)
})

function openModal() {
  open.value = true
  nextTick(() => inputRef.value?.focus())
}
function close() {
  open.value = false
}

function go(item: FlatItem) {
  router.push(item.route)
  query.value = ''
  close()
}

function viewAll() {
  router.push({ name: 'search', query: { q: query.value.trim() } })
  query.value = ''
  close()
}

function onKeydown(ev: KeyboardEvent) {
  if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 'k') {
    ev.preventDefault()
    openModal()
    return
  }

  if (!open.value) return

  if (ev.key === 'Escape') {
    ev.preventDefault()
    close()
    return
  }
  if (!hasResults.value) {
    if (ev.key === 'Enter' && query.value.trim().length >= 2) {
      ev.preventDefault()
      viewAll()
    }
    return
  }

  if (ev.key === 'ArrowDown') {
    ev.preventDefault()
    selectedIndex.value = (selectedIndex.value + 1) % flat.value.length
  } else if (ev.key === 'ArrowUp') {
    ev.preventDefault()
    selectedIndex.value = (selectedIndex.value - 1 + flat.value.length) % flat.value.length
  } else if (ev.key === 'Enter') {
    ev.preventDefault()
    // Cmd/Ctrl+Enter goes to the full results page instead of the selected row.
    if (ev.metaKey || ev.ctrlKey) viewAll()
    else if (flat.value[selectedIndex.value]) go(flat.value[selectedIndex.value]!)
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

function indexOfKind(kind: FlatItem['kind'], match: (d: FlatItem['data']) => boolean): number {
  return flat.value.findIndex(f => f.kind === kind && match(f.data))
}

const showAllParams = computed(() => ({ name: 'search', query: { q: query.value.trim() } }))
</script>

<template>
  <!-- Trigger pill in the header. Clicking opens the modal. -->
  <button
    type="button"
    class="w-full max-w-md flex items-center gap-2 px-3 py-1.5 rounded-md text-sm transition-colors"
    style="background-color: var(--color-bg); border: 1px solid var(--color-border); color: var(--color-text-dim);"
    @click="openModal"
  >
    <Search :size="14" />
    <span class="flex-1 text-left">Search…</span>
    <kbd class="text-[10px] px-1.5 py-0.5 rounded border" style="border-color: var(--color-border);">⌘K</kbd>
  </button>

  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-start justify-center pt-[10vh] px-4"
        style="background-color: color-mix(in srgb, #000 70%, transparent);"
        @mousedown="close"
      >
        <div
          class="card w-full max-w-2xl overflow-hidden flex flex-col max-h-[80vh]"
          @mousedown.stop
        >
          <!-- Input -->
          <div class="flex items-center gap-3 px-4 py-3 border-b" style="border-color: var(--color-border);">
            <Search :size="18" class="text-[var(--color-text-dim)] shrink-0" />
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              placeholder="Search accounts, transactions, assets, tags…"
              class="flex-1 bg-transparent outline-none text-base"
              autocomplete="off"
            />
            <button
              type="button"
              class="text-[var(--color-text-dim)] hover:text-[var(--color-text)]"
              @click="close"
              aria-label="Close"
            >
              <X :size="16" />
            </button>
          </div>

          <!-- Body -->
          <div class="overflow-y-auto flex-1">
            <p v-if="query.trim().length < 2" class="text-sm text-[var(--color-text-muted)] p-6 text-center">
              Type at least 2 characters.
            </p>
            <p v-else-if="loading && !hasResults" class="text-sm text-[var(--color-text-muted)] p-6 text-center">
              Searching…
            </p>
            <p v-else-if="!hasResults" class="text-sm text-[var(--color-text-muted)] p-6 text-center">
              No matches for "<span class="text-[var(--color-text)]">{{ query }}</span>".
            </p>

            <template v-else>
              <!-- Accounts -->
              <section v-if="results.accounts.length > 0">
                <div class="px-4 pt-3 pb-1 flex items-center justify-between text-[11px] uppercase tracking-wider text-[var(--color-text-dim)]">
                  <span class="inline-flex items-center gap-1.5">
                    <Wallet :size="11" /> Accounts
                  </span>
                  <RouterLink :to="showAllParams" class="text-[var(--color-accent)] hover:underline normal-case tracking-normal" @click="close">
                    View all →
                  </RouterLink>
                </div>
                <button
                  v-for="a in results.accounts"
                  :key="a.id"
                  type="button"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm transition-colors"
                  :class="indexOfKind('account', d => (d as AccountResult).id === a.id) === selectedIndex
                    ? 'bg-[var(--color-surface-hover)]'
                    : 'hover:bg-[var(--color-surface-hover)]'"
                  @click="go({ kind: 'account', route: { name: 'account', params: { id: a.id } }, data: a })"
                >
                  <Wallet :size="14" class="text-[var(--color-accent)] shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">{{ a.name }}</div>
                    <div class="text-xs text-[var(--color-text-dim)] truncate">{{ a.institution ?? a.type }}</div>
                  </div>
                  <span class="text-xs text-[var(--color-text-muted)]">{{ a.currency }}</span>
                </button>
              </section>

              <!-- Assets -->
              <section v-if="results.assets.length > 0">
                <div class="px-4 pt-3 pb-1 flex items-center justify-between text-[11px] uppercase tracking-wider text-[var(--color-text-dim)]">
                  <span class="inline-flex items-center gap-1.5">
                    <TrendingUp :size="11" /> Assets
                  </span>
                  <RouterLink :to="showAllParams" class="text-[var(--color-accent)] hover:underline normal-case tracking-normal" @click="close">
                    View all →
                  </RouterLink>
                </div>
                <button
                  v-for="a in results.assets"
                  :key="a.isin"
                  type="button"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm transition-colors"
                  :class="indexOfKind('asset', d => (d as AssetResult).isin === a.isin) === selectedIndex
                    ? 'bg-[var(--color-surface-hover)]'
                    : 'hover:bg-[var(--color-surface-hover)]'"
                  @click="go({ kind: 'asset', route: { name: 'asset', params: { isin: a.isin } }, data: a })"
                >
                  <TrendingUp :size="14" class="text-[var(--color-highlight)] shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">{{ a.ticker ?? a.name ?? a.isin }}</div>
                    <div class="text-xs text-[var(--color-text-dim)] truncate">{{ a.name ?? a.isin }}</div>
                  </div>
                  <span v-if="a.currency" class="text-xs text-[var(--color-text-muted)]">{{ a.currency }}</span>
                </button>
              </section>

              <!-- Tags -->
              <section v-if="results.tags.length > 0">
                <div class="px-4 pt-3 pb-1 flex items-center justify-between text-[11px] uppercase tracking-wider text-[var(--color-text-dim)]">
                  <span class="inline-flex items-center gap-1.5">
                    <TagIcon :size="11" /> Tags
                  </span>
                  <RouterLink :to="showAllParams" class="text-[var(--color-accent)] hover:underline normal-case tracking-normal" @click="close">
                    View all →
                  </RouterLink>
                </div>
                <button
                  v-for="t in results.tags"
                  :key="t.tag"
                  type="button"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm transition-colors"
                  :class="indexOfKind('tag', d => (d as TagResult).tag === t.tag) === selectedIndex
                    ? 'bg-[var(--color-surface-hover)]'
                    : 'hover:bg-[var(--color-surface-hover)]'"
                  @click="go({ kind: 'tag', route: { name: 'tag', params: { tag: t.tag } }, data: t })"
                >
                  <TagIcon :size="14" class="text-[var(--color-accent)] shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">{{ t.tag }}</div>
                  </div>
                  <span class="text-xs text-[var(--color-text-dim)]">{{ t.count }}</span>
                </button>
              </section>

              <!-- Transactions -->
              <section v-if="results.transactions.length > 0">
                <div class="px-4 pt-3 pb-1 flex items-center justify-between text-[11px] uppercase tracking-wider text-[var(--color-text-dim)]">
                  <span class="inline-flex items-center gap-1.5">
                    <Receipt :size="11" /> Transactions
                  </span>
                  <RouterLink :to="showAllParams" class="text-[var(--color-accent)] hover:underline normal-case tracking-normal" @click="close">
                    View all →
                  </RouterLink>
                </div>
                <button
                  v-for="t in results.transactions"
                  :key="t.id"
                  type="button"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm transition-colors"
                  :class="indexOfKind('transaction', d => (d as TransactionResult).id === t.id) === selectedIndex
                    ? 'bg-[var(--color-surface-hover)]'
                    : 'hover:bg-[var(--color-surface-hover)]'"
                  @click="go({ kind: 'transaction', route: { name: 'transaction', params: { accountId: t.accountId, id: t.id } }, data: t })"
                >
                  <Receipt :size="14" class="text-[var(--color-text-muted)] shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">{{ t.description ?? '—' }}</div>
                    <div class="text-xs text-[var(--color-text-dim)] truncate flex items-center gap-1.5">
                      <span>{{ t.accountName }}</span>
                      <span>·</span>
                      <span>{{ shortDate(t.occurredAt) }}</span>
                      <span v-if="categoryMeta(t.category)" class="inline-flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(t.category)!.color }"></span>
                        <span>{{ categoryMeta(t.category)!.label }}</span>
                      </span>
                    </div>
                  </div>
                  <span class="text-xs tabular shrink-0"
                    :class="BigInt(t.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
                    {{ formatMinor(t.amountMinor, t.currency) }}
                  </span>
                </button>
              </section>

              <!-- Recurring -->
              <section v-if="results.recurring.length > 0">
                <div class="px-4 pt-3 pb-1 flex items-center justify-between text-[11px] uppercase tracking-wider text-[var(--color-text-dim)]">
                  <span class="inline-flex items-center gap-1.5">
                    <Repeat :size="11" /> Recurring
                  </span>
                  <RouterLink :to="showAllParams" class="text-[var(--color-accent)] hover:underline normal-case tracking-normal" @click="close">
                    View all →
                  </RouterLink>
                </div>
                <button
                  v-for="r in results.recurring"
                  :key="r.id"
                  type="button"
                  class="w-full text-left px-4 py-2 flex items-center gap-3 text-sm transition-colors"
                  :class="[
                    indexOfKind('recurring', d => (d as RecurringResult).id === r.id) === selectedIndex
                      ? 'bg-[var(--color-surface-hover)]'
                      : 'hover:bg-[var(--color-surface-hover)]',
                    !r.active ? 'opacity-60' : '',
                  ]"
                  @click="go({ kind: 'recurring', route: { name: 'account', params: { id: r.accountId } }, data: r })"
                >
                  <Repeat :size="14" class="text-[var(--color-text-muted)] shrink-0" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">{{ r.description }}</div>
                    <div class="text-xs text-[var(--color-text-dim)] truncate">
                      {{ r.accountName }} · {{ describeSchedule(r as never) }}
                    </div>
                  </div>
                  <span class="text-xs tabular shrink-0"
                    :class="BigInt(r.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
                    {{ formatMinor(r.amountMinor, r.currency) }}
                  </span>
                </button>
              </section>
            </template>
          </div>

          <!-- Footer -->
          <div
            v-if="query.trim().length >= 2"
            class="border-t px-4 py-2 flex items-center justify-between text-xs text-[var(--color-text-dim)]"
            style="border-color: var(--color-border);"
          >
            <div class="flex items-center gap-3">
              <span><kbd class="px-1 py-0.5 rounded border" style="border-color: var(--color-border);">↑↓</kbd> navigate</span>
              <span><kbd class="px-1 py-0.5 rounded border" style="border-color: var(--color-border);">↵</kbd> open</span>
              <span><kbd class="px-1 py-0.5 rounded border" style="border-color: var(--color-border);">esc</kbd> close</span>
            </div>
            <button
              type="button"
              class="inline-flex items-center gap-1 text-[var(--color-accent)] hover:underline"
              @click="viewAll"
            >
              See all results
              <ArrowRight :size="12" />
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
