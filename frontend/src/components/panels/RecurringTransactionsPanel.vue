<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { recurringApi, describeSchedule, type RecurringRule } from '@/lib/recurring'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { useToastsStore } from '@/stores/toasts'
import RecurringForm from '@/components/forms/RecurringForm.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Button from '@/components/ui/Button.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import { Plus, Pencil, Trash2, Play, Pause, Inbox } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{ accountId: string; currency: string; showCategories?: boolean }>(),
  { showCategories: true },
)
const emit = defineEmits<{ changed: [] }>()

const toasts = useToastsStore()

const rules = ref<RecurringRule[]>([])
const loading = ref(false)

const formOpen = ref(false)
const editing = ref<RecurringRule | null>(null)

async function load() {
  loading.value = true
  try {
    rules.value = await recurringApi.list(props.accountId)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.accountId, load)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(r: RecurringRule) {
  editing.value = r
  formOpen.value = true
}

function onSaved() {
  void load()
  emit('changed')
}

async function toggleActive(r: RecurringRule) {
  try {
    const updated = await recurringApi.update(r.id, { active: !r.active })
    rules.value = rules.value.map((x) => (x.id === r.id ? updated : x))
    toasts.success(updated.active ? 'Resumed' : 'Paused')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

async function runNow(r: RecurringRule) {
  try {
    const res = await recurringApi.run(r.id)
    rules.value = rules.value.map((x) => (x.id === r.id ? res.rule : x))
    if (res.created > 0) {
      toasts.success(`Created ${res.created} transaction${res.created === 1 ? '' : 's'}`)
      emit('changed')
    } else {
      toasts.info('Nothing due yet')
    }
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

async function remove(r: RecurringRule) {
  if (!confirm(`Delete "${r.description}"? Already-generated transactions stay.`)) return
  try {
    await recurringApi.remove(r.id)
    rules.value = rules.value.filter((x) => x.id !== r.id)
    toasts.success('Rule deleted')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

function shortDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const columns = computed<ColumnDef<RecurringRule, unknown>[]>(() => [
  { id: 'description', accessorKey: 'description', header: 'Description', enableSorting: true },
  {
    id: 'schedule',
    accessorFn: (r) => describeSchedule(r),
    header: 'Schedule',
    enableSorting: true,
    meta: { cellClass: 'text-[var(--color-text-muted)]' },
  },
  {
    id: 'amount',
    accessorFn: (r) => Number(r.amountMinor),
    header: 'Amount',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32', cellClass: 'tabular font-medium' },
  },
  {
    id: 'next',
    accessorFn: (r) => r.nextOccurrenceAt ?? '',
    header: 'Next',
    enableSorting: true,
    meta: { cellClass: 'text-xs text-[var(--color-text-muted)] tabular' },
  },
  {
    id: 'status',
    accessorFn: (r) => (r.active ? 'Active' : 'Paused'),
    header: 'Status',
    enableSorting: true,
  },
  {
    id: 'actions',
    header: '',
    enableSorting: false,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32' },
  },
])
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h3 class="text-sm text-[var(--color-text-muted)]">
          Templates that auto-create transactions on a schedule (rent, salary, subscriptions).
          The materializer runs nightly; use <strong>Run now</strong> to catch up immediately.
        </h3>
      </div>
      <Button @click="openCreate">
        <Plus :size="14" />
        <span>New rule</span>
      </Button>
    </div>

    <div v-if="loading" class="card p-10 text-center text-[var(--color-text-muted)]">Loading…</div>

    <div v-else-if="rules.length === 0" class="card p-10 text-center space-y-2">
      <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
      <p class="font-medium">No recurring rules yet</p>
      <p class="text-sm text-[var(--color-text-muted)]">
        Add one for your rent, salary, or subscriptions so they appear automatically.
      </p>
    </div>

    <DataTable v-else :data="rules" :columns="columns">
      <template #cell-description="{ row }">
        <div :class="{ 'opacity-60': !row.active }">
          <div class="font-medium">{{ row.description }}</div>
          <div v-if="showCategories && categoryMeta(row.category)" class="text-xs mt-0.5 flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(row.category)!.color }"></span>
            <span class="text-[var(--color-text-muted)]">{{ categoryMeta(row.category)!.label }}</span>
          </div>
        </div>
      </template>
      <template #cell-amount="{ row }">
        <span :class="BigInt(row.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'">
          {{ formatMinor(row.amountMinor, row.currency) }}
        </span>
      </template>
      <template #cell-next="{ row }">
        {{ shortDate(row.nextOccurrenceAt) }}
      </template>
      <template #cell-status="{ row }">
        <span v-if="row.active" class="badge" style="color: var(--color-positive);">Active</span>
        <span v-else class="badge">Paused</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button
            variant="ghost"
            icon-only
            :title="row.active ? 'Pause' : 'Resume'"
            @click="toggleActive(row)"
          >
            <Pause v-if="row.active" :size="14" />
            <Play v-else :size="14" />
          </Button>
          <Button
            variant="ghost"
            icon-only
            title="Run now"
            :disabled="!row.active"
            @click="runNow(row)"
          >
            <span class="text-xs">▶</span>
          </Button>
          <Button variant="ghost" icon-only title="Edit" @click="openEdit(row)">
            <Pencil :size="14" />
          </Button>
          <Button variant="danger" icon-only title="Delete" @click="remove(row)">
            <Trash2 :size="14" />
          </Button>
        </div>
      </template>
    </DataTable>

    <RecurringForm
      v-model:open="formOpen"
      :account-id="accountId"
      :currency="currency"
      :rule="editing"
      :show-categories="showCategories"
      @saved="onSaved"
    />
  </div>
</template>
