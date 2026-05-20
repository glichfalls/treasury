<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import { PROVIDER_OPTIONS, providerDef, type AccountProvider } from '@/lib/providers'
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
const provider = ref<string>('manual')
const providerConfig = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const submitting = ref(false)

// Re-seed all fields when the account being edited changes.
watch(
  () => props.account,
  (a) => {
    if (!a) return
    name.value = a.name
    institution.value = a.institution ?? ''
    type.value = a.type
    currency.value = a.currency
    provider.value = a.provider ?? 'manual'
    providerConfig.value = { ...(a.providerConfig ?? {}) }
    error.value = null
  },
  { immediate: true },
)

// When provider changes, clear stale credentials from the previous provider.
watch(provider, () => {
  providerConfig.value = {}
})

const currentProviderDef = computed(() => providerDef(provider.value as AccountProvider))

function close() {
  emit('update:account', null)
}

async function submit() {
  if (!props.account) return
  error.value = null
  submitting.value = true
  try {
    const def = currentProviderDef.value
    const config = def.syncConfigFields
      ? Object.fromEntries(
          (def.syncConfigFields ?? [])
            .map((f): [string, string] => [f.key, providerConfig.value[f.key] ?? ''])
            .filter(([, v]) => v !== ''),
        )
      : null
    await accounts.update(props.account.id, {
      name: name.value.trim(),
      institution: institution.value.trim() || null,
      type: type.value,
      currency: currency.value.trim().toUpperCase(),
      provider: provider.value,
      providerConfig: config && Object.keys(config).length > 0 ? config : null,
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
      <div class="space-y-3">
        <SelectField v-model="provider" label="Provider" :options="PROVIDER_OPTIONS" />

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

        <!-- Provider-specific config fields (e.g. IBKR Flex credentials) -->
        <template v-if="currentProviderDef.syncConfigFields?.length">
          <div class="pt-1 border-t" style="border-color: var(--color-border);">
            <p class="text-xs text-[var(--color-text-muted)] mb-2">Connection settings</p>
            <div class="space-y-2">
              <div v-for="field in currentProviderDef.syncConfigFields" :key="field.key">
                <TextField
                  :model-value="providerConfig[field.key] ?? ''"
                  :label="field.label"
                  :placeholder="field.placeholder"
                  :type="field.secret ? 'password' : 'text'"
                  @update:model-value="providerConfig[field.key] = String($event)"
                />
                <p v-if="field.hint" class="text-xs text-[var(--color-text-dim)] mt-0.5 ml-0.5">{{ field.hint }}</p>
              </div>
            </div>
          </div>
        </template>
      </div>
    </template>
  </ModalForm>
</template>
