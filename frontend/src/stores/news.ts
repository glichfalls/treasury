import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

export type Sentiment = 'bullish' | 'bearish' | 'neutral'
export type NewsKind = 'headline' | 'analyst_action' | 'earnings' | 'social'

export interface NewsAsset {
  isin: string
  ticker: string | null
  name: string | null
}

export interface NewsItem {
  id: string
  source: string
  kind: NewsKind
  title: string
  url: string
  publisher: string | null
  summary: string | null
  /** In-depth markdown brief; only populated on the single-article detail fetch. */
  brief: string | null
  snippet: string | null
  sentiment: Sentiment | null
  publishedAt: string
  /**
   * Every held + un-muted asset this article applies to. An article fetched
   * against AAPL, MSFT, and NVDA collapses to one item with all three here.
   */
  assets: NewsAsset[]
}

export interface SentimentCounts {
  bullish: number
  bearish: number
  neutral: number
  unclassified: number
}

export interface NewsFeedResponse {
  items: NewsItem[]
  total: number
  page: number
  pageSize: number
  counts: SentimentCounts
  sources: string[]
}

export interface NewsFilters {
  isin?: string
  source?: string
  kind?: NewsKind
  sentiment?: Sentiment | 'unclassified'
  q?: string
  page?: number
  pageSize?: number
}

export const useNewsStore = defineStore('news', () => {
  const items = ref<NewsItem[]>([])
  const counts = ref<SentimentCounts>({ bullish: 0, bearish: 0, neutral: 0, unclassified: 0 })
  const sources = ref<string[]>([])
  const total = ref(0)
  const page = ref(1)
  const pageSize = ref(30)
  const loading = ref(false)

  function buildQuery(filters: NewsFilters): string {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(filters)) {
      if (value !== undefined && value !== null && value !== '') {
        params.set(key, String(value))
      }
    }
    const qs = params.toString()
    return qs ? `?${qs}` : ''
  }

  async function fetch(filters: NewsFilters = {}): Promise<void> {
    loading.value = true
    try {
      const res = await api.get<NewsFeedResponse>(`/api/news${buildQuery(filters)}`)
      items.value = res.items
      counts.value = res.counts
      sources.value = res.sources
      total.value = res.total
      page.value = res.page
      pageSize.value = res.pageSize
    } finally {
      loading.value = false
    }
  }

  /** Mute/un-mute a holding's news, or override its ETF market topic. */
  async function setAssetPreferences(
    isin: string,
    prefs: { enabled?: boolean; marketTopic?: string | null },
  ): Promise<void> {
    await api.patch(`/api/news/assets/${encodeURIComponent(isin)}/preferences`, prefs)
  }

  /** Fetch one article (lazy-classifies on the backend if not yet summarized). */
  async function fetchOne(id: string): Promise<NewsItem> {
    return api.get<NewsItem>(`/api/news/${encodeURIComponent(id)}`)
  }

  return { items, counts, sources, total, page, pageSize, loading, fetch, fetchOne, setAssetPreferences }
})
