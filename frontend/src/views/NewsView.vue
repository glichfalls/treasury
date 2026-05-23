<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useNewsStore, type Sentiment, type NewsKind } from '@/stores/news'
import { useToastsStore } from '@/stores/toasts'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import SelectField from '@/components/ui/SelectField.vue'
import Button from '@/components/ui/Button.vue'
import { api } from '@/lib/api'
import { BellOff, RefreshCw, Search, Settings } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'

const news = useNewsStore()
const toasts = useToastsStore()
const auth = useAuthStore()

type Tab = 'all' | Sentiment | 'unclassified'

const tab = ref<Tab>('all')
const kind = ref<'all' | NewsKind>('all')
const source = ref<string | null>(null)
const q = ref('')
const page = ref(1)

const kindOptions: { value: 'all' | NewsKind; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'headline', label: 'News' },
  { value: 'analyst_action', label: 'Analyst' },
  { value: 'earnings', label: 'Earnings' },
  { value: 'social', label: 'Social' },
]

const sentimentTabs = computed(() => {
  const c = news.counts
  const total = c.bullish + c.bearish + c.neutral + c.unclassified
  const tabs: { value: Tab; label: string; count: number }[] = [
    { value: 'all', label: 'All', count: total },
    { value: 'bullish', label: 'Bullish', count: c.bullish },
    { value: 'bearish', label: 'Bearish', count: c.bearish },
    { value: 'neutral', label: 'Neutral', count: c.neutral },
  ]
  // Only surface the unclassified bucket while items still await sentiment.
  if (c.unclassified > 0) {
    tabs.push({ value: 'unclassified', label: 'Unrated', count: c.unclassified })
  }
  return tabs
})

const sourceOptions = computed(() =>
  news.sources.map((s) => ({ value: s, label: s.charAt(0).toUpperCase() + s.slice(1) })),
)

const totalPages = computed(() => Math.max(1, Math.ceil(news.total / news.pageSize)))
const rangeStart = computed(() => (news.total === 0 ? 0 : (news.page - 1) * news.pageSize + 1))
const rangeEnd = computed(() => Math.min(news.page * news.pageSize, news.total))

function load() {
  news.fetch({
    sentiment: tab.value === 'all' ? undefined : tab.value,
    kind: kind.value === 'all' ? undefined : kind.value,
    source: source.value ?? undefined,
    q: q.value.trim() || undefined,
    page: page.value,
  })
}

onMounted(load)

// Filter changes reset to page 1; page changes just reload.
watch([tab, kind, source], () => {
  page.value = 1
  load()
})
watch(page, load)

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(q, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 350)
})

const refreshing = ref(false)
async function refresh() {
  refreshing.value = true
  try {
    await api.post('/api/admin/news/refresh', {})
    toasts.success('News refresh queued — new items will appear shortly.')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    refreshing.value = false
  }
}

async function muteHolding(isin: string, label: string) {
  try {
    await news.setAssetPreferences(isin, { enabled: false })
    toasts.success(`Muted news for ${label}`)
    load()
  } catch {
    toasts.error('Could not mute this holding')
  }
}

function dotClass(sentiment: string | null): string {
  if (sentiment === 'bullish') return 'bg-[var(--color-positive)]'
  if (sentiment === 'bearish') return 'bg-[var(--color-negative)]'
  if (sentiment === 'neutral') return 'bg-[var(--color-text-muted)]'
  return 'bg-[var(--color-text-dim)]'
}

function assetLabel(a: { ticker: string | null; name: string | null; isin: string }): string {
  return a.ticker ?? a.name ?? a.isin
}

/** Badge for the structured kinds; headlines get none (they're the default). */
function kindBadge(k: NewsKind): { label: string; class: string } | null {
  switch (k) {
    case 'analyst_action':
      return { label: 'Analyst', class: 'border-[var(--color-accent)] text-[var(--color-accent)]' }
    case 'earnings':
      return { label: 'Earnings', class: 'border-[var(--color-positive)] text-[var(--color-positive)]' }
    case 'social':
      return { label: 'Social', class: 'border-[var(--color-border)] text-[var(--color-text-muted)]' }
    default:
      return null
  }
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">News</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">Headlines and sentiment for the assets you hold.</p>
      </div>
      <div v-if="auth.isAdmin" class="flex items-center gap-2">
        <Button variant="ghost" size="sm" :to="{ name: 'news-admin' }">
          <Settings :size="14" />
          Configure
        </Button>
        <Button variant="secondary" size="sm" :loading="refreshing" loading-text="Queuing…" @click="refresh">
          <RefreshCw :size="14" />
          Refresh news
        </Button>
      </div>
    </header>

    <div class="flex flex-wrap items-center gap-3">
      <SegmentedControl v-model="kind" variant="chip" :options="kindOptions" />
    </div>

    <div class="flex flex-wrap items-end gap-3 justify-between">
      <SegmentedControl v-model="tab" variant="tabs" :options="sentimentTabs" />
      <div class="flex items-end gap-3">
        <div class="relative">
          <Search :size="14" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--color-text-dim)]" />
          <input
            v-model="q"
            type="search"
            placeholder="Search headlines…"
            class="pl-8 pr-3 py-1.5 text-sm rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] focus:outline-none focus:border-[var(--color-accent)]"
          />
        </div>
        <SelectField
          v-if="sourceOptions.length > 1"
          v-model="source"
          :options="sourceOptions"
          allow-empty
          empty-label="All sources"
          clearable
          size="sm"
          :full-width="false"
        />
      </div>
    </div>

    <div v-if="news.counts.unclassified > 0" class="text-xs text-[var(--color-text-muted)]">
      Sentiment grouping and summaries activate once the AI classifier is configured; until then items appear under
      <span class="text-[var(--color-text)]">Unrated</span>.
    </div>

    <div v-if="news.loading && news.items.length === 0" class="text-sm text-[var(--color-text-muted)] py-12 text-center">
      Loading news…
    </div>
    <div v-else-if="news.items.length === 0" class="text-sm text-[var(--color-text-muted)] py-12 text-center">
      No news to show. The feed refreshes hourly for your holdings.
    </div>

    <ul v-else class="space-y-2">
      <li
        v-for="item in news.items"
        :key="item.id"
        class="group card p-4 flex items-start gap-3"
      >
        <span class="mt-1.5 shrink-0 w-2 h-2 rounded-full" :class="dotClass(item.sentiment)" />
        <div class="min-w-0 flex-1">
          <span
            v-if="kindBadge(item.kind)"
            class="inline-block mr-2 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide border align-middle"
            :class="kindBadge(item.kind)!.class"
          >{{ kindBadge(item.kind)!.label }}</span>
          <RouterLink
            :to="{ name: 'news-detail', params: { id: item.id } }"
            class="text-sm font-medium hover:text-[var(--color-accent)]"
          >{{ item.title }}</RouterLink>
          <p v-if="item.summary ?? item.snippet" class="text-sm text-[var(--color-text-muted)] mt-1">
            {{ item.summary ?? item.snippet }}
          </p>
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--color-text-muted)] mt-1.5">
            <template v-for="(a, idx) in item.assets" :key="a.isin">
              <span v-if="idx > 0" class="text-[var(--color-text-dim)]">·</span>
              <RouterLink
                :to="{ name: 'asset', params: { isin: a.isin } }"
                class="font-medium text-[var(--color-text)] hover:text-[var(--color-accent)]"
              >{{ assetLabel(a) }}</RouterLink>
            </template>
            <span v-if="item.assets.length > 0">·</span>
            <span>{{ item.publisher ?? item.source }}</span>
            <span>·</span>
            <span>{{ formatTime(item.publishedAt) }}</span>
          </div>
        </div>
        <Button
          v-if="item.assets.length === 1"
          variant="ghost"
          size="sm"
          icon-only
          class="opacity-0 group-hover:opacity-100 transition-opacity"
          :title="`Mute news for ${assetLabel(item.assets[0]!)}`"
          @click="muteHolding(item.assets[0]!.isin, assetLabel(item.assets[0]!))"
        >
          <BellOff :size="14" />
        </Button>
      </li>
    </ul>

    <div v-if="news.total > news.pageSize" class="flex items-center justify-between text-sm">
      <span class="text-[var(--color-text-muted)]">{{ rangeStart }}–{{ rangeEnd }} of {{ news.total }}</span>
      <div class="flex items-center gap-2">
        <Button variant="secondary" size="sm" :disabled="news.page <= 1" @click="page = news.page - 1">Previous</Button>
        <Button variant="secondary" size="sm" :disabled="news.page >= totalPages" @click="page = news.page + 1">Next</Button>
      </div>
    </div>
  </div>
</template>
