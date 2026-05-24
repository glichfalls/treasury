<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import Button from '@/components/ui/Button.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { RefreshCw, KeyRound, ArrowLeft } from 'lucide-vue-next'

interface SourceCfg {
  key: string
  enabled: boolean
}
interface NewsConfig {
  sources: SourceCfg[]
  volume: 'low' | 'medium' | 'high'
  broadSubreddits: string
  customAiEnabled: boolean
}
interface HoldingCfg {
  isin: string
  ticker: string | null
  name: string | null
  newsEnabled: boolean
  newsMarketTopic: string | null
  redditSubreddit: string | null
}

const toasts = useToastsStore()
const config = ref<NewsConfig | null>(null)
const holdings = ref<HoldingCfg[]>([])
const loading = ref(false)
const savingConfig = ref(false)
const refreshing = ref(false)
const savingIsin = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    config.value = await api.get<NewsConfig>('/api/admin/news/config')
    holdings.value = await api.get<HoldingCfg[]>('/api/admin/news/assets')
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function saveConfig() {
  if (!config.value) return
  savingConfig.value = true
  try {
    config.value = await api.patch<NewsConfig>('/api/admin/news/config', {
      enabledSources: config.value.sources.filter((s) => s.enabled).map((s) => s.key),
      volume: config.value.volume,
      broadSubreddits: config.value.broadSubreddits,
      customAiEnabled: config.value.customAiEnabled,
    })
    toasts.success('News settings saved.')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    savingConfig.value = false
  }
}

async function refreshNow() {
  refreshing.value = true
  try {
    await api.post('/api/admin/news/refresh', {})
    toasts.success('News refresh queued — new items appear shortly.')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    refreshing.value = false
  }
}

async function saveHolding(h: HoldingCfg) {
  savingIsin.value = h.isin
  try {
    await api.patch(`/api/news/assets/${encodeURIComponent(h.isin)}/preferences`, {
      enabled: h.newsEnabled,
      marketTopic: h.newsMarketTopic,
      redditSubreddit: h.redditSubreddit,
    })
    toasts.success(`Saved ${h.ticker ?? h.isin}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    savingIsin.value = null
  }
}

function sourceLabel(k: string): string {
  return k.charAt(0).toUpperCase() + k.slice(1)
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <Button variant="ghost" size="sm" :to="{ name: 'news' }">
        <ArrowLeft :size="14" />
        Back to news
      </Button>
    </div>

    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">News aggregator</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">Configure sources, volume, and per-holding tracking.</p>
      </div>
      <Button variant="secondary" size="sm" :loading="refreshing" loading-text="Queuing…" @click="refreshNow">
        <RefreshCw :size="14" />
        Refresh now
      </Button>
    </header>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>

    <template v-else-if="config">
      <!-- Sources + volume + reddit subs -->
      <div class="card p-6 space-y-5">
        <div>
          <h2 class="text-lg font-medium">Sources & volume</h2>
          <p class="text-sm text-[var(--color-text-muted)]">
            Keyed sources only contribute once their API key is set in
            <RouterLink :to="{ name: 'settings' }" class="text-[var(--color-accent)] inline-flex items-center gap-1">
              <KeyRound :size="12" /> Integrations
            </RouterLink>.
          </p>
        </div>

        <div>
          <h3 class="label mb-2">Sources</h3>
          <div class="flex flex-wrap gap-x-6 gap-y-2">
            <label
              v-for="s in config.sources"
              :key="s.key"
              class="flex items-center gap-2 text-sm cursor-pointer"
            >
              <input v-model="s.enabled" type="checkbox" class="accent-[var(--color-accent)] w-4 h-4 rounded" />
              <span>{{ sourceLabel(s.key) }}</span>
            </label>
          </div>
        </div>

        <div>
          <h3 class="label mb-2">Volume per holding</h3>
          <SegmentedControl
            v-model="config.volume"
            :options="[
              { value: 'low', label: 'Low' },
              { value: 'medium', label: 'Medium' },
              { value: 'high', label: 'High' },
            ]"
          />
        </div>

        <div>
          <h3 class="label mb-2">Broad subreddits</h3>
          <input
            v-model="config.broadSubreddits"
            type="text"
            placeholder="wallstreetbets, stocks, investing…"
            class="w-full max-w-md px-3 py-1.5 text-sm rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] focus:outline-none focus:border-[var(--color-accent)]"
          />
          <p class="text-xs text-[var(--color-text-muted)] mt-1">Comma-separated; searched per holding for the ticker/company.</p>
        </div>

        <div>
          <h3 class="label mb-2">Custom sources</h3>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="config.customAiEnabled" type="checkbox" class="accent-[var(--color-accent)] w-4 h-4 rounded" />
            <span>AI briefs &amp; digest for custom-source articles</span>
          </label>
          <p class="text-xs text-[var(--color-text-muted)] mt-1">
            Master switch for AI on per-asset custom feeds. Each source also has its own toggle
            (set on the asset page) which only applies while this is on.
          </p>
        </div>

        <Button variant="primary" :loading="savingConfig" loading-text="Saving…" @click="saveConfig">Save settings</Button>
      </div>

      <!-- Per-holding overrides -->
      <div class="card p-6 space-y-4">
        <div>
          <h2 class="text-lg font-medium">Holdings</h2>
          <p class="text-sm text-[var(--color-text-muted)]">
            Mute a holding, set the market topic for an ETF, or pin its dedicated subreddit.
          </p>
        </div>

        <div v-if="holdings.length === 0" class="text-sm text-[var(--color-text-muted)]">No tickered holdings yet.</div>

        <div v-else class="space-y-2">
          <div
            v-for="h in holdings"
            :key="h.isin"
            class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center py-2 border-b border-[var(--color-border)] last:border-0"
          >
            <div class="md:col-span-3 min-w-0">
              <div class="font-medium text-sm truncate">{{ h.ticker ?? h.isin }}</div>
              <div v-if="h.name" class="text-xs text-[var(--color-text-muted)] truncate">{{ h.name }}</div>
            </div>
            <label class="md:col-span-2 flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="h.newsEnabled" type="checkbox" class="accent-[var(--color-accent)] w-4 h-4 rounded" />
              <span>News on</span>
            </label>
            <input
              v-model="h.newsMarketTopic"
              type="text"
              placeholder="Market topic (ETFs)"
              class="md:col-span-3 px-2 py-1 text-sm rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] focus:outline-none focus:border-[var(--color-accent)]"
            />
            <input
              v-model="h.redditSubreddit"
              type="text"
              placeholder="Subreddit"
              class="md:col-span-2 px-2 py-1 text-sm rounded-md bg-[var(--color-surface)] border border-[var(--color-border)] focus:outline-none focus:border-[var(--color-accent)]"
            />
            <div class="md:col-span-2 flex justify-end">
              <Button variant="ghost" size="sm" :loading="savingIsin === h.isin" @click="saveHolding(h)">Save</Button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
