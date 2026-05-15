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
import BaseModal from '@/components/BaseModal.vue'

const props = defineProps<{ transaction: Transaction | null }>()
const emit = defineEmits<{ 'update:transaction': [Transaction | null]; saved: [] }>()

const accounts = useAccountsStore()

const transactionTypes = [
  { value: 'deposit', label: 'Deposit' },
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
          <input v-model="occurredAt" type="date" required class="input" />
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
        <div class="space-y-1.5 sm:col-span-2">
          <label class="label">Description</label>
          <input v-model="description" class="input" />
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
