<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { Plus, X } from 'lucide-vue-next'

const accounts = useAccountsStore()

const accountTypes = [
  { value: 'bank_checking', label: 'Bank — Checking' },
  { value: 'bank_savings', label: 'Bank — Savings' },
  { value: 'cash', label: 'Cash' },
  { value: 'credit_card', label: 'Credit card' },
  { value: 'brokerage', label: 'Brokerage' },
  { value: 'crypto_exchange', label: 'Crypto exchange' },
  { value: 'crypto_wallet', label: 'Crypto wallet' },
  { value: 'real_estate', label: 'Real estate' },
  { value: 'vehicle', label: 'Vehicle' },
  { value: 'other', label: 'Other' },
]

const open = ref(false)
const name = ref('')
const institution = ref('')
const type = ref('bank_checking')
const currency = ref('CHF')
const error = ref<string | null>(null)
const submitting = ref(false)

async function submit() {
  error.value = null
  submitting.value = true
  try {
    await accounts.create({
      name: name.value.trim(),
      institution: institution.value.trim() || null,
      type: type.value,
      currency: currency.value.trim().toUpperCase(),
    })
    name.value = ''
    institution.value = ''
    open.value = false
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
    <span>New account</span>
  </button>

  <form v-else class="card p-5 space-y-4" @submit.prevent="submit">
    <div class="flex items-center justify-between">
      <h3 class="font-medium">New account</h3>
      <button type="button" class="btn btn-ghost p-1" @click="open = false">
        <X :size="16" />
      </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">Name</label>
        <input v-model="name" required placeholder="Salary account" class="input" />
      </div>
      <div class="space-y-1.5">
        <label class="label">Institution</label>
        <input v-model="institution" placeholder="ZKB" class="input" />
      </div>
      <div class="space-y-1.5">
        <label class="label">Type</label>
        <select v-model="type" class="input">
          <option v-for="t in accountTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>
      <div class="space-y-1.5">
        <label class="label">Currency</label>
        <input v-model="currency" required maxlength="3" pattern="[A-Za-z]{3}" class="input uppercase" />
      </div>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Create account' }}
      </button>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </div>
  </form>
</template>
