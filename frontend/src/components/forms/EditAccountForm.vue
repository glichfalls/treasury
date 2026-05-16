<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import SelectField from '@/components/ui/SelectField.vue'

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
const type = ref<string>('bank_checking')
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
  <ModalForm
    :open="account !== null"
    title="Edit account"
    submit-label="Save changes"
    :submitting="submitting"
    :error="error"
    @update:open="(v) => !v && close()"
    @submit="submit"
  >
    <template v-if="account">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <TextField v-model="name" label="Name" required autofocus />
        <TextField v-model="institution" label="Institution" />
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
    </template>
  </ModalForm>
</template>
