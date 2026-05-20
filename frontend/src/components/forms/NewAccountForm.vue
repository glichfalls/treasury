<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { PROVIDER_OPTIONS, providerDef, type AccountProvider } from '@/lib/providers'
import { Plus } from 'lucide-vue-next'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import Button from '@/components/ui/Button.vue'

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
const provider = ref<string>('manual')
const name = ref('')
const institution = ref('')
const type = ref<string>('bank_checking')
const currency = ref('CHF')
const providerConfig = ref<Record<string, string>>({})
const error = ref<string | null>(null)
const submitting = ref(false)

// Auto-fill institution and type when provider changes.
watch(provider, (p) => {
  const def = providerDef(p as AccountProvider)
  if (def.defaultInstitution) institution.value = def.defaultInstitution
  if (def.defaultAccountType) type.value = def.defaultAccountType
  providerConfig.value = {}
})

function reset() {
  provider.value = 'manual'
  name.value = ''
  institution.value = ''
  type.value = 'bank_checking'
  currency.value = 'CHF'
  providerConfig.value = {}
  error.value = null
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const def = currentProviderDef.value
    const config = def.syncConfigFields
      ? Object.fromEntries(
          (def.syncConfigFields ?? [])
            .map((f) => [f.key, providerConfig.value[f.key] ?? ''])
            .filter(([, v]) => v !== ''),
        )
      : null
    const created = await accounts.create({
      name: name.value.trim(),
      institution: institution.value.trim() || null,
      type: type.value,
      currency: currency.value.trim().toUpperCase(),
      provider: provider.value,
      providerConfig: config && Object.keys(config).length > 0 ? config : null,
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

const currentProviderDef = computed(() => providerDef(provider.value as AccountProvider))
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
      <Button @click="openModal">
        <Plus :size="16" />
        <span>New account</span>
      </Button>
    </template>

    <div class="space-y-3">
      <SelectField v-model="provider" label="Provider" :options="PROVIDER_OPTIONS" />

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <TextField v-model="name" label="Name" placeholder="Main account" required autofocus />
        <TextField v-model="institution" label="Institution" placeholder="Interactive Brokers" />
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

      <!-- Provider-specific config fields -->
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
  </ModalForm>
</template>
