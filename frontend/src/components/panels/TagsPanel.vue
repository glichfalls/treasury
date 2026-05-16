<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { formatMinor } from '@/lib/money'
import { useToastsStore } from '@/stores/toasts'
import { Sparkles } from 'lucide-vue-next'
import DataTable from '@/components/ui/DataTable.vue'
import type { ColumnDef } from '@tanstack/vue-table'

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

const columns = computed<ColumnDef<Tag, unknown>[]>(() => [
  { id: 'tag', accessorKey: 'tag', header: 'Tag', enableSorting: true },
  {
    id: 'count',
    accessorKey: 'count',
    header: 'Count',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-24', cellClass: 'tabular text-[var(--color-text-muted)]' },
  },
  {
    id: 'total',
    accessorFn: (t) => Number(t.totalMinor),
    header: `Net (${data.value?.baseCurrency ?? ''})`,
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-40', cellClass: 'tabular font-medium' },
  },
])
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

    <DataTable
      v-else-if="data && data.tags.length > 0"
      :data="data.tags"
      :columns="columns"
    >
      <template #cell-tag="{ row }">
        <RouterLink
          :to="{ name: 'tag', params: { tag: row.tag } }"
          class="inline-flex items-center gap-1.5 text-xs rounded px-2 py-1 hover:opacity-80 transition-opacity"
          style="background-color: color-mix(in srgb, var(--color-accent) 14%, transparent); color: var(--color-accent);"
        >
          {{ row.tag }}
        </RouterLink>
      </template>
      <template #cell-total="{ row }">
        <span :class="BigInt(row.totalMinor) >= 0n ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'">
          {{ formatMinor(row.totalMinor, data!.baseCurrency) }}
        </span>
      </template>
    </DataTable>
  </div>
</template>
