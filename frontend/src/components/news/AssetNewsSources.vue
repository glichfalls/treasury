<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import Button from '@/components/ui/Button.vue'
import { Plus, Trash2, ExternalLink, Rss, Globe, AlertCircle } from 'lucide-vue-next'

const props = defineProps<{ isin: string }>()

interface NewsSource {
  id: string
  url: string
  type: 'rss' | 'atom' | 'website'
  scrapeMode: 'feed' | 'scrape'
  feedUrl: string | null
  label: string | null
  enabled: boolean
  aiEnabled: boolean
  parser: string
  lastStatus: string | null
  lastFetchedAt: string | null
  createdAt: string
}
interface PreviewItem { title: string; url: string; publisher: string | null; publishedAt: string }
interface SourcePreview {
  type: string
  scrapeMode: string
  feedUrl: string | null
  label: string | null
  parser: string
  items: PreviewItem[]
}

const toasts = useToastsStore()
const sources = ref<NewsSource[]>([])
const loading = ref(false)

const newUrl = ref('')
const newLabel = ref('')
const preview = ref<SourcePreview | null>(null)
const checking = ref(false)
const adding = ref(false)

const base = () => `/api/admin/news/assets/${encodeURIComponent(props.isin)}/sources`

async function load() {
  loading.value = true
  try {
    sources.value = await api.get<NewsSource[]>(base())
  } finally {
    loading.value = false
  }
}
onMounted(load)

function reportError(e: unknown) {
  toasts.error(e instanceof ApiError ? e.message : e instanceof Error ? e.message : String(e))
}

async function check() {
  if (!newUrl.value.trim()) return
  checking.value = true
  preview.value = null
  try {
    preview.value = await api.post<SourcePreview>(`${base()}/preview`, { url: newUrl.value.trim() })
    if (preview.value.label && !newLabel.value) newLabel.value = preview.value.label
  } catch (e) {
    reportError(e)
  } finally {
    checking.value = false
  }
}

async function add() {
  if (!newUrl.value.trim()) return
  adding.value = true
  try {
    await api.post(base(), {
      url: newUrl.value.trim(),
      label: newLabel.value.trim() || undefined,
    })
    toasts.success('Source added.')
    newUrl.value = ''
    newLabel.value = ''
    preview.value = null
    await load()
  } catch (e) {
    reportError(e)
  } finally {
    adding.value = false
  }
}

async function toggle(s: NewsSource, field: 'enabled' | 'aiEnabled') {
  const next = !s[field]
  try {
    const updated = await api.patch<NewsSource>(`/api/admin/news/sources/${s.id}`, { [field]: next })
    Object.assign(s, updated)
  } catch (e) {
    reportError(e)
  }
}

async function remove(s: NewsSource) {
  try {
    await api.delete(`/api/admin/news/sources/${s.id}`)
    sources.value = sources.value.filter((x) => x.id !== s.id)
  } catch (e) {
    reportError(e)
  }
}

function statusOk(s: NewsSource): boolean {
  return s.lastStatus === null || s.lastStatus === 'ok'
}
function timeAgo(iso: string | null): string {
  if (!iso) return 'never'
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 60) return `${Math.max(1, mins)}m ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.round(hours / 24)}d ago`
}
</script>

<template>
  <div class="card p-5 space-y-4">
    <div>
      <h3 class="text-base font-medium flex items-center gap-2">
        News sources
        <span class="badge">Admin</span>
      </h3>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">
        RSS feeds or websites crawled for this holding alongside the built-in sources.
        Websites without a feed are scraped best-effort.
      </p>
    </div>

    <!-- Existing sources -->
    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>
    <div v-else-if="sources.length === 0" class="text-sm text-[var(--color-text-muted)]">
      No custom sources yet.
    </div>
    <ul v-else class="space-y-2">
      <li
        v-for="s in sources"
        :key="s.id"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2 border-b border-[var(--color-border)] last:border-0"
      >
        <component :is="s.scrapeMode === 'feed' ? Rss : Globe" :size="15" class="shrink-0 text-[var(--color-text-muted)]" />
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium truncate">{{ s.label ?? s.url }}</div>
          <div class="text-xs text-[var(--color-text-dim)] truncate flex items-center gap-1.5">
            <a :href="s.feedUrl ?? s.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-[var(--color-text)]">
              {{ s.feedUrl ?? s.url }}<ExternalLink :size="11" />
            </a>
          </div>
          <div class="text-xs mt-0.5 flex items-center gap-1.5"
               :class="statusOk(s) ? 'text-[var(--color-text-dim)]' : 'text-[var(--color-negative)]'">
            <AlertCircle v-if="!statusOk(s)" :size="11" />
            <span>{{ s.scrapeMode === 'feed' ? s.type.toUpperCase() : 'Scrape' }}</span>
            <span>·</span>
            <span>{{ s.parser }} parser</span>
            <span>·</span>
            <span>{{ s.lastStatus ?? 'not fetched' }} ({{ timeAgo(s.lastFetchedAt) }})</span>
          </div>
        </div>
        <label class="flex items-center gap-1.5 text-xs cursor-pointer" title="Enable AI briefs for this source">
          <input type="checkbox" :checked="s.aiEnabled" class="accent-[var(--color-accent)] w-4 h-4 rounded" @change="toggle(s, 'aiEnabled')" />
          <span>AI</span>
        </label>
        <label class="flex items-center gap-1.5 text-xs cursor-pointer" title="Crawl this source">
          <input type="checkbox" :checked="s.enabled" class="accent-[var(--color-accent)] w-4 h-4 rounded" @change="toggle(s, 'enabled')" />
          <span>On</span>
        </label>
        <button
          class="shrink-0 p-1 text-[var(--color-text-dim)] hover:text-[var(--color-negative)] transition-colors"
          title="Remove source"
          @click="remove(s)"
        >
          <Trash2 :size="15" />
        </button>
      </li>
    </ul>

    <!-- Add form -->
    <div class="space-y-2 pt-2 border-t border-[var(--color-border)]">
      <div class="flex flex-col sm:flex-row gap-2">
        <input
          v-model="newUrl"
          type="url"
          placeholder="https://example.com/feed.xml or a news page"
          class="input text-sm flex-1"
          @keyup.enter="check"
        />
        <input
          v-model="newLabel"
          type="text"
          placeholder="Label (optional)"
          class="input text-sm sm:w-44"
        />
        <Button variant="secondary" size="sm" :loading="checking" loading-text="Checking…" @click="check">Check</Button>
        <Button variant="primary" size="sm" :loading="adding" loading-text="Adding…" @click="add">
          <Plus :size="14" /> Add
        </Button>
      </div>

      <!-- Preview -->
      <div v-if="preview" class="rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] p-3 space-y-2">
        <div class="text-xs text-[var(--color-text-muted)] flex flex-wrap items-center gap-x-2 gap-y-1">
          <span class="badge">{{ preview.scrapeMode === 'feed' ? preview.type.toUpperCase() : 'Scrape' }}</span>
          <span>via {{ preview.parser }} parser</span>
          <span v-if="preview.feedUrl">· resolved feed: {{ preview.feedUrl }}</span>
        </div>
        <p v-if="preview.items.length === 0" class="text-xs text-[var(--color-text-muted)]">
          No items could be extracted — adding it anyway will keep retrying on each refresh.
        </p>
        <ul v-else class="space-y-1">
          <li v-for="(it, i) in preview.items.slice(0, 5)" :key="i" class="text-sm truncate">
            <span class="text-[var(--color-text-dim)] mr-1">•</span>{{ it.title }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
