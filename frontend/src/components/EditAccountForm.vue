<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import BaseModal from '@/components/BaseModal.vue'

const props = defineProps<{ account: Account | null }>()
const emit = defineEmits<{ 'update:account': [Account | null]; saved: [] }>()

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
  { value: 'precious_metals', label: 'Precious metals' },
  { value: 'pillar_3a', label: 'Pillar 3a' },
  { value: 'other', label: 'Other' },
]

const name = ref('')
const institution = ref('')
const type = ref('bank_checking')
const currency = ref('CHF')
const error = ref<string | null>(null)
const submitting = ref(false)

// Re-seed the form whenever a different account is opened for editing.
watch(
  () => props.account,
  (a) => {
    if (!a) return
    name.value = a.name
    institution.value = a.institution ?? ''
    type.value = a.type
    currency.value = a.currency
    error.value = null
  },
  { immediate: true },
)

function close() {
  emit('update:account', null)
}

async function submit() {
  if (!props.account) return
  error.value = null
  submitting.value = true
  try {
    await accounts.update(props.account.id, {
      name: name.value.trim(),
      institution: institution.value.trim() || null,
      type: type.value,
      currency: currency.value.trim().toUpperCase(),
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
  <BaseModal :open="account !== null" title="Edit account" @update:open="(v) => !v && close()">
    <form v-if="account" id="edit-account-form" class="space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Name</label>
          <input v-model="name" required class="input" autofocus />
        </div>
        <div class="space-y-1.5">
          <label class="label">Institution</label>
          <input v-model="institution" class="input" />
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
      <button type="button" class="btn btn-ghost" @click="close">Cancel</button>
      <button type="submit" form="edit-account-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save changes' }}
      </button>
    </template>
  </BaseModal>
</template>
