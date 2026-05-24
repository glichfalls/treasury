<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useNewsStore, type NewsDigest } from '@/stores/news'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { renderMarkdown } from '@/lib/markdown'
import Button from '@/components/ui/Button.vue'
import { Sparkles, RefreshCw } from 'lucide-vue-next'

const news = useNewsStore()
const auth = useAuthStore()
const toasts = useToastsStore()

const digest = ref<NewsDigest | null>(null)
const loading = ref(false)
const generating = ref(false)

async function load() {
  loading.value = true
  try {
    digest.value = await news.fetchDigest()
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function generate() {
  generating.value = true
  try {
    const r = await news.generateDigest()
    toasts.success(`Briefing generated from ${r.itemCount} items.`)
    await load()
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    generating.value = false
  }
}

function formatWhen(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

// The window the briefing actually covers (e.g. "May 21 – 24"), collapsing to a
// single date when start and end land on the same day.
function formatRange(startIso: string, endIso: string): string {
  const opt: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' }
  const start = new Date(startIso).toLocaleDateString(undefined, opt)
  const end = new Date(endIso).toLocaleDateString(undefined, opt)
  return start === end ? end : `${start} – ${end}`
}
</script>

<template>
  <section
    class="card p-5"
    style="background-image: linear-gradient(135deg, color-mix(in srgb, var(--color-accent) 7%, var(--color-surface)), var(--color-surface) 70%);"
  >
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <Sparkles :size="16" class="text-[var(--color-accent)]" />
          <h2 class="text-base font-semibold tracking-tight">Daily briefing</h2>
        </div>
        <p
          v-if="digest"
          class="text-xs text-[var(--color-text-muted)] mt-0.5"
          :title="'Generated ' + formatWhen(digest.generatedAt)"
        >
          {{ formatRange(digest.periodStart, digest.periodEnd) }} · {{ digest.itemCount }} {{ digest.itemCount === 1 ? 'item' : 'items' }}
        </p>
      </div>
      <Button v-if="auth.isAdmin" class="shrink-0" variant="ghost" size="sm" :loading="generating" loading-text="Generating…" @click="generate">
        <RefreshCw :size="13" />
        Generate
      </Button>
    </div>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)] mt-3">Loading…</div>
    <!-- eslint-disable-next-line vue/no-v-html -- sanitized markdown of trusted AI output -->
    <div v-else-if="digest" class="text-sm leading-relaxed mt-3 space-y-2" v-html="renderMarkdown(digest.content)" />
    <p v-else class="text-sm text-[var(--color-text-muted)] mt-3">
      No briefing yet — it's generated each morning once an OpenAI key is configured<span v-if="auth.isAdmin">, or hit Generate</span>.
    </p>
  </section>
</template>
