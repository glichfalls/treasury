<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import type { NewsItem } from '@/stores/news'

const props = withDefaults(defineProps<{
  isin: string
  limit?: number
}>(), {
  limit: 8,
})

interface FeedResponse { items: NewsItem[] }

const items = ref<NewsItem[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const res = await api.get<FeedResponse>(
      `/api/news?isin=${encodeURIComponent(props.isin)}&pageSize=${props.limit}`,
    )
    items.value = res.items
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.isin, load)

function dotClass(sentiment: string | null): string {
  if (sentiment === 'bullish') return 'bg-[var(--color-positive)]'
  if (sentiment === 'bearish') return 'bg-[var(--color-negative)]'
  if (sentiment === 'neutral') return 'bg-[var(--color-text-muted)]'
  return 'bg-[var(--color-text-dim)]'
}

function timeAgo(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime()
  const mins = Math.round(diff / 60000)
  if (mins < 60) return `${Math.max(1, mins)}m`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours}h`
  return `${Math.round(hours / 24)}d`
}
</script>

<template>
  <div v-if="loading && items.length === 0" class="text-sm text-[var(--color-text-muted)] py-4">
    Loading news…
  </div>
  <div v-else-if="items.length === 0" class="text-sm text-[var(--color-text-muted)] py-4">
    No recent news for this asset.
  </div>
  <ul v-else class="space-y-1">
    <li v-for="item in items" :key="item.id">
      <RouterLink
        :to="{ name: 'news-detail', params: { id: item.id } }"
        class="flex items-baseline gap-2 py-1.5 px-2 -mx-2 rounded hover:bg-[var(--color-surface)]"
      >
        <span class="mt-1.5 shrink-0 w-1.5 h-1.5 rounded-full" :class="dotClass(item.sentiment)" />
        <span class="min-w-0 flex-1">
          <span class="text-sm block">{{ item.title }}</span>
          <span class="text-xs text-[var(--color-text-muted)]">{{ item.publisher ?? item.source }}</span>
        </span>
        <span class="shrink-0 text-xs text-[var(--color-text-dim)] tabular">{{ timeAgo(item.publishedAt) }}</span>
      </RouterLink>
    </li>
  </ul>
</template>
