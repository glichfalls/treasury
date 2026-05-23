<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useNewsStore, type NewsItem } from '@/stores/news'
import { useToastsStore } from '@/stores/toasts'
import Button from '@/components/ui/Button.vue'
import { ArrowLeft, ExternalLink } from 'lucide-vue-next'

const route = useRoute()
const news = useNewsStore()
const toasts = useToastsStore()

const item = ref<NewsItem | null>(null)
const loading = ref(false)

async function load(id: string) {
  loading.value = true
  item.value = null
  try {
    item.value = await news.fetchOne(id)
  } catch {
    toasts.error('Could not load this article.')
  } finally {
    loading.value = false
  }
}

onMounted(() => load(String(route.params.id)))
watch(() => route.params.id, (id) => { if (id) load(String(id)) })

function dotClass(sentiment: string | null): string {
  if (sentiment === 'bullish') return 'bg-[var(--color-positive)]'
  if (sentiment === 'bearish') return 'bg-[var(--color-negative)]'
  if (sentiment === 'neutral') return 'bg-[var(--color-text-muted)]'
  return 'bg-[var(--color-text-dim)]'
}

function sentimentLabel(s: string | null): string {
  if (s === 'bullish') return 'Bullish'
  if (s === 'bearish') return 'Bearish'
  if (s === 'neutral') return 'Neutral'
  return 'Unrated'
}

function assetLabel(a: { ticker: string | null; name: string | null; isin: string }): string {
  return a.ticker ?? a.name ?? a.isin
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    weekday: 'short', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
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

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)] py-12 text-center">
      Loading article…
    </div>

    <article v-else-if="item" class="card p-6 space-y-4">
      <header class="space-y-3">
        <div class="flex items-center gap-2 text-xs text-[var(--color-text-muted)]">
          <span class="inline-block w-2 h-2 rounded-full" :class="dotClass(item.sentiment)" />
          <span>{{ sentimentLabel(item.sentiment) }}</span>
          <span>·</span>
          <span>{{ item.publisher ?? item.source }}</span>
          <span>·</span>
          <span>{{ formatTime(item.publishedAt) }}</span>
        </div>
        <h1 class="text-xl font-semibold tracking-tight">{{ item.title }}</h1>
        <div v-if="item.assets.length > 0" class="flex flex-wrap items-center gap-2 text-xs">
          <span class="text-[var(--color-text-muted)]">Affects:</span>
          <RouterLink
            v-for="a in item.assets"
            :key="a.isin"
            :to="{ name: 'asset', params: { isin: a.isin } }"
            class="px-2 py-0.5 rounded-md border border-[var(--color-border)] text-[var(--color-text)] hover:border-[var(--color-accent)]"
          >{{ assetLabel(a) }}</RouterLink>
        </div>
      </header>

      <section v-if="item.summary" class="space-y-2">
        <h2 class="label">Summary</h2>
        <p class="text-sm leading-relaxed">{{ item.summary }}</p>
      </section>

      <section v-if="item.snippet && item.snippet !== item.summary" class="space-y-2">
        <h2 class="label">Excerpt</h2>
        <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">{{ item.snippet }}</p>
      </section>

      <div class="pt-2">
        <a
          :href="item.url"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex items-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium bg-[var(--color-accent)] text-[var(--color-accent-text)] hover:bg-[var(--color-accent-hover)] no-underline"
        >
          <ExternalLink :size="14" />
          Open article
        </a>
      </div>
    </article>

    <div v-else class="text-sm text-[var(--color-text-muted)] py-12 text-center">
      Article not found.
    </div>
  </div>
</template>
