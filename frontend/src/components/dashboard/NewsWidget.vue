<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import ChartCard from '@/components/ui/ChartCard.vue'
import Button from '@/components/ui/Button.vue'
import { RefreshCw } from 'lucide-vue-next'
import type { NewsItem, SentimentCounts } from '@/stores/news'

interface DashboardResponse {
  items: NewsItem[]
  counts: SentimentCounts
}

const data = ref<DashboardResponse | null>(null)
const loading = ref(false)
const refreshing = ref(false)
const toasts = useToastsStore()

async function load() {
  loading.value = true
  try {
    data.value = await api.get<DashboardResponse>('/api/news/dashboard')
  } finally {
    loading.value = false
  }
}

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

onMounted(load)

const empty = computed(() => !data.value || data.value.items.length === 0)

const tilt = computed(() => {
  const c = data.value?.counts
  if (!c) return null
  const directional = c.bullish + c.bearish
  if (directional === 0) return null
  return { bullish: c.bullish, bearish: c.bearish, pct: Math.round((c.bullish / directional) * 100) }
})

function dotClass(sentiment: string | null): string {
  if (sentiment === 'bullish') return 'bg-[var(--color-positive)]'
  if (sentiment === 'bearish') return 'bg-[var(--color-negative)]'
  return 'bg-[var(--color-text-dim)]'
}

function assetsLabel(item: NewsItem): string {
  if (item.assets.length === 0) return ''
  const labels = item.assets.map((a) => a.ticker ?? a.name ?? a.isin)
  // Compact list — show up to 3, then "+N more" so the row stays single-line.
  if (labels.length <= 3) return labels.join(' · ')
  return `${labels.slice(0, 3).join(' · ')} +${labels.length - 3} more`
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
  <ChartCard
    title="Holdings news"
    :loading="loading"
    :empty="empty"
    empty-text="No news yet for your holdings."
    height="auto"
  >
    <template #actions>
      <div class="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          icon-only
          :loading="refreshing"
          title="Refresh news"
          @click="refresh"
        >
          <RefreshCw :size="14" />
        </Button>
        <RouterLink :to="{ name: 'news' }" class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)]">
          View all
        </RouterLink>
      </div>
    </template>

    <div v-if="data">
      <div v-if="tilt" class="flex items-center gap-2 mb-3 text-xs text-[var(--color-text-muted)]">
        <span class="text-[var(--color-positive)]">{{ tilt.bullish }} bullish</span>
        <span>·</span>
        <span class="text-[var(--color-negative)]">{{ tilt.bearish }} bearish</span>
        <span class="ml-auto">{{ tilt.pct }}% bullish</span>
      </div>

      <ul class="space-y-1">
        <li v-for="item in data.items" :key="item.id">
          <RouterLink
            :to="{ name: 'news-detail', params: { id: item.id } }"
            class="flex items-baseline gap-2 py-1 px-2 -mx-2 rounded hover:bg-[var(--color-surface)]"
          >
            <span class="mt-1.5 shrink-0 w-1.5 h-1.5 rounded-full" :class="dotClass(item.sentiment)" />
            <span class="min-w-0 flex-1">
              <span class="text-sm truncate block">{{ item.title }}</span>
              <span class="text-xs text-[var(--color-text-muted)]">
                {{ assetsLabel(item) }}
                · {{ item.publisher ?? item.source }}
              </span>
            </span>
            <span class="shrink-0 text-xs text-[var(--color-text-dim)] tabular">{{ timeAgo(item.publishedAt) }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </ChartCard>
</template>
