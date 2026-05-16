<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAccountsStore, type Transaction } from '@/stores/accounts'
import { exponentOf, parseMajor } from '@/lib/money'

function minorToMajorString(amountMinor: string, currency: string): string {
  const exp = exponentOf(currency)
  const negative = amountMinor.startsWith('-')
  const digits = (negative ? amountMinor.slice(1) : amountMinor).padStart(exp + 1, '0')
  const intPart = digits.slice(0, digits.length - exp) || '0'
  const fracPart = exp > 0 ? digits.slice(-exp) : ''
  return (negative ? '-' : '') + intPart + (fracPart ? '.' + fracPart : '')
}
import { computed } from 'vue'
import BaseModal from '@/components/BaseModal.vue'
import DateField from '@/components/DateField.vue'
import TagsInput from '@/components/TagsInput.vue'
import { CATEGORIES } from '@/lib/categories'
import { featuresFor } from '@/lib/accountFeatures'

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

const occurredAt = ref('')
const amount = ref('')
const description = ref('')
const type = ref('other')
const category = ref('')
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
    category.value = t.category ?? ''
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
  <BaseModal :open="transaction !== null" title="Edit transaction" @update:open="(v) => !v && close()">
    <form v-if="transaction" id="edit-transaction-form" class="space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Date</label>
          <DateField v-model="occurredAt" required />
        </div>
        <div class="space-y-1.5">
          <label class="label">Amount ({{ transaction.currency }})</label>
          <input v-model="amount" required class="input tabular" />
        </div>
        <div class="space-y-1.5">
          <label class="label">Type</label>
          <select v-model="type" class="input">
            <option v-for="t in transactionTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <div v-if="features.showCategories" class="space-y-1.5">
          <label class="label">Category</label>
          <select v-model="category" class="input">
            <option value="">Uncategorized</option>
            <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
        </div>
        <div class="space-y-1.5 sm:col-span-2">
          <label class="label">Description</label>
          <input v-model="description" class="input" />
        </div>
        <div class="space-y-1.5 sm:col-span-2">
          <label class="label">Tags</label>
          <TagsInput v-model="tags" placeholder="Netflix, business, refund…" />
        </div>
      </div>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="close">Cancel</button>
      <button type="submit" form="edit-transaction-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save changes' }}
      </button>
    </template>
  </BaseModal>
</template>
