<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { parseMajor } from '@/lib/money'
import { Plus } from 'lucide-vue-next'
import BaseModal from '@/components/BaseModal.vue'
import DateField from '@/components/DateField.vue'

const props = defineProps<{ accountId: string; currency: string }>()
const emit = defineEmits<{ created: [] }>()

const accounts = useAccountsStore()

const today = new Date().toISOString().slice(0, 10)
const open = ref(false)
const occurredAt = ref(today)
const amount = ref('')
const description = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

function reset() {
  occurredAt.value = today
  amount.value = ''
  description.value = ''
  error.value = null
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const amountMinor = parseMajor(amount.value, props.currency)
    await accounts.addTransaction(props.accountId, {
      occurredAt: occurredAt.value,
      amountMinor,
      description: description.value.trim() || null,
    })
    reset()
    open.value = false
    emit('created')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <button class="btn btn-secondary" @click="open = true">
    <Plus :size="16" />
    <span>Add transaction</span>
  </button>

  <BaseModal v-model:open="open" title="New transaction" @close="reset">
    <form id="new-transaction-form" class="space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Date</label>
          <DateField v-model="occurredAt" required />
        </div>
        <div class="space-y-1.5">
          <label class="label">Amount ({{ currency }})</label>
          <input v-model="amount" placeholder="-12.50 for spending" required class="input tabular" />
        </div>
        <div class="space-y-1.5 sm:col-span-2">
          <label class="label">Description</label>
          <input v-model="description" placeholder="Groceries at Migros" class="input" />
        </div>
      </div>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
      <button type="submit" form="new-transaction-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save transaction' }}
      </button>
    </template>
  </BaseModal>
</template>
