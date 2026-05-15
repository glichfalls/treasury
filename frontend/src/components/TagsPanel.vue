<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { useToastsStore } from '@/stores/toasts'
import { Sparkles } from 'lucide-vue-next'

interface Tag { tag: string; count: number; totalMinor: string }
interface TagsResponse { baseCurrency: string; tags: Tag[] }

const toasts = useToastsStore()
const data = ref<TagsResponse | null>(null)
const loading = ref(false)
const retagging = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.get<TagsResponse>('/api/tags')
  } finally {
    loading.value = false
  }
}

async function retagAll() {
  if (!confirm(
    'Scan every transaction with a description, and apply any existing tag whose ' +
    'name appears in it? This only adds tags — nothing existing is removed.',
  )) return

  retagging.value = true
  try {
    const res = await api.post<{ tagged: number; examined: number; newTagInstances: number }>(
      '/api/tags/retag',
      {},
    )
    if (res.tagged === 0) {
      toasts.info(`Examined ${res.examined} transactions — nothing new to tag.`)
    } else {
      toasts.success(
        `Tagged ${res.tagged} of ${res.examined} transactions ` +
        `(${res.newTagInstances} new tag${res.newTagInstances === 1 ? '' : 's'} applied).`,
      )
    }
    await load()
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    retagging.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Tags</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Free-form labels you add to transactions. New transactions auto-pick up matching
        tags. Click a tag for spending details.
      </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        class="btn btn-primary"
        :disabled="retagging || !data || data.tags.length === 0"
        @click="retagAll"
      >
        <Sparkles :size="14" />
        <span>{{ retagging ? 'Tagging…' : 'Auto-tag existing transactions' }}</span>
      </button>
      <p v-if="data && data.tags.length === 0" class="text-xs text-[var(--color-text-dim)]">
        Add a tag to a transaction first — the auto-tagger needs at least one tag to learn from.
      </p>
    </div>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>

    <div v-else-if="data && data.tags.length > 0" class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Tag</th>
            <th class="text-right w-24">Count</th>
            <th class="text-right w-40">Net ({{ data.baseCurrency }})</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in data.tags" :key="t.tag">
            <td>
              <RouterLink
                :to="{ name: 'tag', params: { tag: t.tag } }"
                class="inline-flex items-center gap-1.5 text-xs rounded px-2 py-1 hover:opacity-80 transition-opacity"
                style="background-color: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent);"
              >
                {{ t.tag }}
              </RouterLink>
            </td>
            <td class="text-right tabular text-[var(--color-text-muted)]">{{ t.count }}</td>
            <td
              class="text-right tabular font-medium"
              :class="BigInt(t.totalMinor) >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
            >
              {{ formatMinor(t.totalMinor, data.baseCurrency) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
