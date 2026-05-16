<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { Plus } from 'lucide-vue-next'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import SelectField from '@/components/ui/SelectField.vue'

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
const type = ref<string>('bank_checking')
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
  <ModalForm
    v-model:open="open"
    title="New account"
    submit-label="Create account"
    :submitting="submitting"
    :error="error"
    @submit="submit"
    @close="reset"
  >
    <template #trigger="{ open: openModal }">
      <button class="btn btn-secondary" @click="openModal">
        <Plus :size="16" />
        <span>New account</span>
      </button>
    </template>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <TextField v-model="name" label="Name" placeholder="Salary account" required autofocus />
      <TextField v-model="institution" label="Institution" placeholder="ZKB" />
      <SelectField v-model="type" label="Type" :options="accountTypes" />
      <TextField
        v-model="currency"
        label="Currency"
        required
        maxlength="3"
        pattern="[A-Za-z]{3}"
        class="uppercase"
      />
    </div>
  </ModalForm>
</template>
