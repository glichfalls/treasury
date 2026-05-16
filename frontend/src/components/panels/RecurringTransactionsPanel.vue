<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { recurringApi, describeSchedule, type RecurringRule } from '@/lib/recurring'
import { formatMinor } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { useToastsStore } from '@/stores/toasts'
import RecurringForm from '@/components/forms/RecurringForm.vue'
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
      <button type="button" class="btn btn-secondary" @click="openCreate">
        <Plus :size="14" />
        <span>New rule</span>
      </button>
    </div>

    <div v-if="loading" class="card p-10 text-center text-[var(--color-text-muted)]">Loading…</div>

    <div v-else-if="rules.length === 0" class="card p-10 text-center space-y-2">
      <div class="flex justify-center text-[var(--color-text-dim)]"><Inbox :size="40" /></div>
      <p class="font-medium">No recurring rules yet</p>
      <p class="text-sm text-[var(--color-text-muted)]">
        Add one for your rent, salary, or subscriptions so they appear automatically.
      </p>
    </div>

    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Description</th>
            <th>Schedule</th>
            <th class="text-right w-32">Amount</th>
            <th>Next</th>
            <th>Status</th>
            <th class="w-32"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.id" :class="{ 'opacity-60': !r.active }">
            <td>
              <div class="font-medium">{{ r.description }}</div>
              <div v-if="showCategories && categoryMeta(r.category)" class="text-xs mt-0.5 flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: categoryMeta(r.category)!.color }"></span>
                <span class="text-[var(--color-text-muted)]">{{ categoryMeta(r.category)!.label }}</span>
              </div>
            </td>
            <td class="text-[var(--color-text-muted)]">{{ describeSchedule(r) }}</td>
            <td
              class="text-right tabular font-medium"
              :class="BigInt(r.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
            >
              {{ formatMinor(r.amountMinor, r.currency) }}
            </td>
            <td class="text-xs text-[var(--color-text-muted)] tabular">{{ shortDate(r.nextOccurrenceAt) }}</td>
            <td>
              <span v-if="r.active" class="badge" style="color: var(--color-positive);">Active</span>
              <span v-else class="badge">Paused</span>
            </td>
            <td>
              <div class="flex justify-end gap-1">
                <button
                  type="button"
                  class="btn btn-ghost p-1.5"
                  :title="r.active ? 'Pause' : 'Resume'"
                  @click="toggleActive(r)"
                >
                  <Pause v-if="r.active" :size="14" />
                  <Play v-else :size="14" />
                </button>
                <button
                  type="button"
                  class="btn btn-ghost p-1.5"
                  title="Run now"
                  :disabled="!r.active"
                  @click="runNow(r)"
                >
                  <span class="text-xs">▶</span>
                </button>
                <button type="button" class="btn btn-ghost p-1.5" title="Edit" @click="openEdit(r)">
                  <Pencil :size="14" />
                </button>
                <button type="button" class="btn btn-danger p-1.5" title="Delete" @click="remove(r)">
                  <Trash2 :size="14" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

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
