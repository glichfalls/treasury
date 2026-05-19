<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/ui/Button.vue'
import SelectField from '@/components/ui/SelectField.vue'
import BrandMark from '@/components/ui/BrandMark.vue'
import type { SelectOption } from '@/components/ui/SelectField.vue'

const auth = useAuthStore()
const router = useRouter()

const COMMON_CURRENCIES = ['CHF', 'EUR', 'USD', 'GBP', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK', 'DKK']
const CUSTOM_KEY = '__custom__'

const CURRENCY_OPTIONS: SelectOption<string>[] = [
  ...COMMON_CURRENCIES.map((c) => ({ value: c, label: c })),
  { value: CUSTOM_KEY, label: 'Other…' },
]

const selectedKey = ref<string | null>(null)
const customCode = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

const isCustom = computed(() => selectedKey.value === CUSTOM_KEY)

async function submit() {
  error.value = null
  const ccy = isCustom.value ? customCode.value.trim().toUpperCase() : (selectedKey.value ?? '')
  if (!/^[A-Z]{3}$/.test(ccy)) {
    error.value = 'Please select or enter a valid 3-letter currency code.'
    return
  }
  submitting.value = true
  try {
    await auth.updatePreferences({ baseCurrency: ccy })
    await router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-sm space-y-6">
      <div class="flex flex-col items-center gap-3 text-center">
        <BrandMark :size="32" />
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">Welcome to Treasury</h1>
          <p class="text-sm text-[var(--color-text-muted)] mt-1">
            Choose your base currency to get started. It drives all net-worth aggregations and
            FX conversions throughout the app. You can change it later in Settings.
          </p>
        </div>
      </div>

      <div class="card p-6">
        <form class="space-y-4" @submit.prevent="submit">
          <SelectField
            label="Base currency"
            :model-value="selectedKey"
            :options="CURRENCY_OPTIONS"
            placeholder="Select currency…"
            @update:model-value="selectedKey = $event"
          />

          <div v-if="isCustom" class="space-y-1.5">
            <label class="label" for="setup-custom-ccy">Currency code</label>
            <input
              id="setup-custom-ccy"
              v-model="customCode"
              placeholder="e.g. SGD"
              maxlength="3"
              pattern="[A-Za-z]{3}"
              class="input uppercase"
              required
            />
            <p class="text-xs text-[var(--color-text-dim)]">3-letter ISO 4217 code.</p>
          </div>

          <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>

          <Button
            type="submit"
            variant="primary"
            :loading="submitting"
            loading-text="Saving…"
            :full-width="true"
          >
            <span>Get started</span>
          </Button>
        </form>
      </div>
    </div>
  </div>
</template>
