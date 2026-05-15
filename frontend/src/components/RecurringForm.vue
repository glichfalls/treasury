<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { parseMajor, exponentOf } from '@/lib/money'
import {
  recurringApi,
  DAYS_OF_WEEK,
  MONTHS,
  type RecurringFrequency,
  type RecurringRule,
} from '@/lib/recurring'
import { CATEGORIES } from '@/lib/categories'
import { useToastsStore } from '@/stores/toasts'
import BaseModal from '@/components/BaseModal.vue'
import DateField from '@/components/DateField.vue'

/**
 * Single form used for both create and edit. When `rule` is null we're in create
 * mode (modal is closed); when a rule is passed, we're editing it. Wrapped by
 * parents that pass `mode = 'create' | 'edit'`.
 */
const props = withDefaults(defineProps<{
  accountId: string
  currency: string
  open: boolean
  rule: RecurringRule | null
  showCategories?: boolean
}>(), { showCategories: true })
const emit = defineEmits<{
  'update:open': [boolean]
  saved: [RecurringRule]
}>()

const toasts = useToastsStore()

const today = new Date().toISOString().slice(0, 10)

const description = ref('')
const amount = ref('')
const type = ref('other')
const category = ref('')
const frequency = ref<RecurringFrequency>('monthly')
const dayOfMonth = ref<number>(1)
const dayOfWeek = ref<number>(1)
const monthOfYear = ref<number>(1)
const startsAt = ref(today)
const endsAt = ref('')
const active = ref(true)

const error = ref<string | null>(null)
const submitting = ref(false)

const isEdit = computed(() => props.rule !== null)

const transactionTypes = [
  { value: 'deposit', label: 'Deposit' },
  { value: 'withdrawal', label: 'Withdrawal' },
  { value: 'fee', label: 'Fee' },
  { value: 'interest', label: 'Interest' },
  { value: 'dividend', label: 'Dividend' },
  { value: 'other', label: 'Other' },
]

function reset() {
  description.value = ''
  amount.value = ''
  type.value = 'other'
  category.value = ''
  frequency.value = 'monthly'
  dayOfMonth.value = 1
  dayOfWeek.value = 1
  monthOfYear.value = 1
  startsAt.value = today
  endsAt.value = ''
  active.value = true
  error.value = null
}

function minorToMajor(amountMinor: string, currency: string): string {
  const exp = exponentOf(currency)
  const negative = amountMinor.startsWith('-')
  const digits = (negative ? amountMinor.slice(1) : amountMinor).padStart(exp + 1, '0')
  const intPart = digits.slice(0, digits.length - exp) || '0'
  const fracPart = exp > 0 ? digits.slice(-exp) : ''
  return (negative ? '-' : '') + intPart + (fracPart ? '.' + fracPart : '')
}

watch(
  () => [props.rule, props.open] as const,
  ([r, isOpen]) => {
    if (!isOpen) return
    if (r) {
      description.value = r.description
      amount.value = minorToMajor(r.amountMinor, r.currency)
      type.value = r.type
      category.value = r.category ?? ''
      frequency.value = r.frequency
      dayOfMonth.value = r.dayOfMonth ?? 1
      dayOfWeek.value = r.dayOfWeek ?? 1
      monthOfYear.value = r.monthOfYear ?? 1
      startsAt.value = r.startsAt
      endsAt.value = r.endsAt ?? ''
      active.value = r.active
      error.value = null
    } else {
      reset()
    }
  },
  { immediate: true },
)

function close() {
  emit('update:open', false)
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const payload = {
      description: description.value.trim(),
      amountMinor: parseMajor(amount.value, props.currency),
      currency: props.currency,
      type: type.value,
      category: category.value || null,
      frequency: frequency.value,
      dayOfMonth: ['monthly', 'yearly'].includes(frequency.value) ? dayOfMonth.value : null,
      dayOfWeek: frequency.value === 'weekly' ? dayOfWeek.value : null,
      monthOfYear: frequency.value === 'yearly' ? monthOfYear.value : null,
      startsAt: startsAt.value,
      endsAt: endsAt.value || null,
      active: active.value,
    }

    const result = isEdit.value
      ? await recurringApi.update(props.rule!.id, payload)
      : await recurringApi.create(props.accountId, payload)

    toasts.success(isEdit.value ? 'Recurring rule updated' : 'Recurring rule created')
    emit('saved', result)
    close()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="isEdit ? 'Edit recurring rule' : 'New recurring rule'"
    @update:open="(v) => emit('update:open', v)"
  >
    <form id="recurring-form" class="space-y-4" @submit.prevent="submit">
      <div class="space-y-1.5">
        <label class="label">Description</label>
        <input v-model="description" required placeholder="Rent, salary, Netflix…" class="input" />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Amount ({{ currency }})</label>
          <input v-model="amount" required placeholder="-2400 for spending" class="input tabular" />
        </div>
        <div class="space-y-1.5">
          <label class="label">Type</label>
          <select v-model="type" class="input">
            <option v-for="t in transactionTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <div v-if="showCategories" class="space-y-1.5 sm:col-span-2">
          <label class="label">Category</label>
          <select v-model="category" class="input">
            <option value="">Uncategorized</option>
            <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
        </div>
      </div>

      <div class="space-y-1.5">
        <label class="label">Frequency</label>
        <div class="flex flex-wrap gap-2">
          <label
            v-for="f in (['daily','weekly','monthly','yearly'] as const)"
            :key="f"
            class="cursor-pointer"
          >
            <input v-model="frequency" type="radio" :value="f" class="sr-only peer" />
            <span class="inline-flex items-center px-3 py-1.5 rounded-md text-sm border peer-checked:border-[var(--color-accent)] peer-checked:text-[var(--color-accent)] peer-checked:bg-[color-mix(in_srgb,var(--color-accent)_12%,transparent)] border-[var(--color-border)] text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors">
              {{ f.charAt(0).toUpperCase() + f.slice(1) }}
            </span>
          </label>
        </div>
      </div>

      <div v-if="frequency === 'weekly'" class="space-y-1.5">
        <label class="label">Day of week</label>
        <select v-model="dayOfWeek" class="input">
          <option v-for="d in DAYS_OF_WEEK" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
      </div>

      <div v-if="frequency === 'monthly'" class="space-y-1.5">
        <label class="label">Day of month</label>
        <input v-model.number="dayOfMonth" type="number" min="1" max="31" class="input tabular" />
        <p class="text-xs text-[var(--color-text-dim)]">Days beyond month length clamp to the last day (e.g. 31 → 28/29 in Feb).</p>
      </div>

      <div v-if="frequency === 'yearly'" class="grid grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Month</label>
          <select v-model="monthOfYear" class="input">
            <option v-for="(m, i) in MONTHS" :key="i" :value="i + 1">{{ m }}</option>
          </select>
        </div>
        <div class="space-y-1.5">
          <label class="label">Day</label>
          <input v-model.number="dayOfMonth" type="number" min="1" max="31" class="input tabular" />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Starts</label>
          <DateField v-model="startsAt" />
        </div>
        <div class="space-y-1.5">
          <label class="label">Ends (optional)</label>
          <DateField v-model="endsAt" clearable placeholder="No end date" />
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-[var(--color-text-muted)] cursor-pointer">
        <input v-model="active" type="checkbox" class="accent-[var(--color-accent)] w-4 h-4 rounded" />
        <span>Active</span>
      </label>

      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="close">Cancel</button>
      <button type="submit" form="recurring-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : isEdit ? 'Save changes' : 'Create rule' }}
      </button>
    </template>
  </BaseModal>
</template>
