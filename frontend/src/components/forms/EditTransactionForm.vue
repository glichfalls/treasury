<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useAccountsStore, type Transaction } from '@/stores/accounts'
import { exponentOf, parseMajor } from '@/lib/money'
import DateField from '@/components/ui/DateField.vue'
import TagsInput from '@/components/ui/TagsInput.vue'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import PriceField from '@/components/ui/PriceField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { CATEGORIES } from '@/lib/categories'
import { featuresFor } from '@/lib/accountFeatures'

function minorToMajorString(amountMinor: string, currency: string): string {
  const exp = exponentOf(currency)
  const negative = amountMinor.startsWith('-')
  const digits = (negative ? amountMinor.slice(1) : amountMinor).padStart(exp + 1, '0')
  const intPart = digits.slice(0, digits.length - exp) || '0'
  const fracPart = exp > 0 ? digits.slice(-exp) : ''
  return (negative ? '-' : '') + intPart + (fracPart ? '.' + fracPart : '')
}

const props = defineProps<{ transaction: Transaction | null }>()
const emit = defineEmits<{ 'update:transaction': [Transaction | null]; saved: [] }>()

const accounts = useAccountsStore()

// Resolve the account behind this transaction so we can hide category etc. for
// non-bank-style accounts. Account list is in the store from earlier load().
const features = computed(() => {
  if (!props.transaction) return featuresFor('other')
  const acc = accounts.accounts.find((a) => a.id === props.transaction!.accountId)
  return featuresFor(acc?.type ?? 'other')
})

const transactionTypes = [
  { value: 'deposit', label: 'Deposit' },
  { value: 'opening_balance', label: 'Opening balance' },
  { value: 'withdrawal', label: 'Withdrawal' },
  { value: 'trade_buy', label: 'Buy' },
  { value: 'trade_sell', label: 'Sell' },
  { value: 'fee', label: 'Fee' },
  { value: 'interest', label: 'Interest' },
  { value: 'dividend', label: 'Dividend' },
  { value: 'fx_conversion', label: 'FX conversion' },
  { value: 'other', label: 'Other' },
]

const categoryOptions = computed(() => CATEGORIES.map((c) => ({ value: c.value, label: c.label })))

const occurredAt = ref('')
const amount = ref('')
const description = ref('')
const type = ref<string>('other')
const category = ref<string | null>(null)
const tags = ref<string[]>([])
const error = ref<string | null>(null)
const submitting = ref(false)

watch(
  () => props.transaction,
  (t) => {
    if (!t) return
    occurredAt.value = t.occurredAt
    amount.value = minorToMajorString(t.amountMinor, t.currency)
    description.value = t.description ?? ''
    type.value = t.type
    category.value = t.category ?? null
    tags.value = [...(t.tags ?? [])]
    error.value = null
  },
  { immediate: true },
)

function close() {
  emit('update:transaction', null)
}

async function submit() {
  if (!props.transaction) return
  error.value = null
  submitting.value = true
  try {
    const amountMinor = parseMajor(amount.value, props.transaction.currency)
    await accounts.updateTransaction(props.transaction.accountId, props.transaction.id, {
      occurredAt: occurredAt.value,
      amountMinor,
      description: description.value.trim() || null,
      type: type.value,
      category: category.value || null,
      tags: tags.value,
    })
    emit('saved')
    close()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <ModalForm
    :open="transaction !== null"
    title="Edit transaction"
    submit-label="Save changes"
    :submitting="submitting"
    :error="error"
    @update:open="(v) => !v && close()"
    @submit="submit"
  >
    <template v-if="transaction">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Date</label>
          <DateField v-model="occurredAt" required />
        </div>
        <PriceField v-model="amount" label="Amount" :currency="transaction.currency" required />
        <SelectField v-model="type" label="Type" :options="transactionTypes" />
        <SelectField
          v-if="features.showCategories"
          v-model="category"
          label="Category"
          :options="categoryOptions"
          allow-empty
          empty-label="Uncategorized"
        />
        <div class="space-y-1.5 sm:col-span-2">
          <TextField v-model="description" label="Description" />
        </div>
        <div class="space-y-1.5 sm:col-span-2">
          <label class="label">Tags</label>
          <TagsInput v-model="tags" placeholder="Netflix, business, refund…" />
        </div>
      </div>
    </template>
  </ModalForm>
</template>
