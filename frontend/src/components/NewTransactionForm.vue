<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { parseMajor } from '@/lib/money'
import { Plus, X } from 'lucide-vue-next'

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
    amount.value = ''
    description.value = ''
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
  <button v-if="!open" class="btn btn-secondary" @click="open = true">
    <Plus :size="16" />
    <span>Add transaction</span>
  </button>

  <form v-else class="card p-5 space-y-4" @submit.prevent="submit">
    <div class="flex items-center justify-between">
      <h3 class="font-medium">New transaction</h3>
      <button type="button" class="btn btn-ghost p-1" @click="open = false">
        <X :size="16" />
      </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">Date</label>
        <input v-model="occurredAt" type="date" required class="input" />
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

    <div class="flex items-center gap-3">
      <button type="submit" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save transaction' }}
      </button>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </div>
  </form>
</template>
