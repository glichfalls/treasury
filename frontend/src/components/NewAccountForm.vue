<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { Plus } from 'lucide-vue-next'
import BaseModal from '@/components/BaseModal.vue'

const accounts = useAccountsStore()
const toasts = useToastsStore()

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
  { value: 'precious_metals', label: 'Precious metals' },
  { value: 'pillar_3a', label: 'Pillar 3a' },
  { value: 'other', label: 'Other' },
]

const open = ref(false)
const name = ref('')
const institution = ref('')
const type = ref('bank_checking')
const currency = ref('CHF')
const error = ref<string | null>(null)
const submitting = ref(false)

function reset() {
  name.value = ''
  institution.value = ''
  type.value = 'bank_checking'
  currency.value = 'CHF'
  error.value = null
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const created = await accounts.create({
      name: name.value.trim(),
      institution: institution.value.trim() || null,
      type: type.value,
      currency: currency.value.trim().toUpperCase(),
    })
    toasts.success(`Created ${created.name}`)
    reset()
    open.value = false
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
    <span>New account</span>
  </button>

  <BaseModal v-model:open="open" title="New account" @close="reset">
    <form id="new-account-form" class="space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Name</label>
          <input v-model="name" required placeholder="Salary account" class="input" autofocus />
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
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
      <button type="submit" form="new-account-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Create account' }}
      </button>
    </template>
  </BaseModal>
</template>
