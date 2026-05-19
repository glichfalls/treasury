<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { Save } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'
import SelectField from '@/components/ui/SelectField.vue'
import type { SelectOption } from '@/components/ui/SelectField.vue'

const auth = useAuthStore()
const toasts = useToastsStore()

const COMMON_CURRENCIES = ['CHF', 'EUR', 'USD', 'GBP', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK', 'DKK']
const CUSTOM_KEY = '__custom__'

const CURRENCY_OPTIONS: SelectOption<string>[] = [
  ...COMMON_CURRENCIES.map((c) => ({ value: c, label: c })),
  { value: CUSTOM_KEY, label: 'Other…' },
]

function init(v: string | undefined | null): { sel: string | null; custom: string } {
  if (!v) return { sel: null, custom: '' }
  return COMMON_CURRENCIES.includes(v) ? { sel: v, custom: '' } : { sel: CUSTOM_KEY, custom: v }
}

const { sel: initSel, custom: initCustom } = init(auth.user?.baseCurrency)
const selectedKey = ref<string | null>(initSel)
const customCode = ref(initCustom)
const submitting = ref(false)

watch(
  () => auth.user?.baseCurrency,
  (v) => {
    const { sel, custom } = init(v)
    selectedKey.value = sel
    customCode.value = custom
  },
)

const isCustom = computed(() => selectedKey.value === CUSTOM_KEY)

async function submit() {
  submitting.value = true
  try {
    const ccy = isCustom.value ? customCode.value.trim().toUpperCase() : (selectedKey.value ?? '')
    if (!/^[A-Z]{3}$/.test(ccy)) {
      throw new Error('Currency must be a 3-letter code (e.g. EUR, USD)')
    }
    await auth.updatePreferences({ baseCurrency: ccy })
    toasts.success(`Base currency set to ${ccy}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Preferences</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Your base currency drives aggregations like total net worth, portfolio performance, and
        per-asset returns. Original transaction amounts are never changed — conversions use the
        FX rate from the transaction date.
      </p>
    </div>

    <form class="space-y-4 max-w-sm" @submit.prevent="submit">
      <SelectField
        label="Base currency"
        :model-value="selectedKey"
        :options="CURRENCY_OPTIONS"
        placeholder="Select currency…"
        @update:model-value="selectedKey = $event"
      />

      <div v-if="isCustom" class="space-y-1.5">
        <label class="label" for="pref-custom-ccy">Currency code</label>
        <input
          id="pref-custom-ccy"
          v-model="customCode"
          placeholder="e.g. SGD"
          maxlength="3"
          pattern="[A-Za-z]{3}"
          class="input uppercase"
          required
        />
        <p class="text-xs text-[var(--color-text-dim)]">3-letter ISO 4217 code.</p>
      </div>

      <Button type="submit" variant="primary" :loading="submitting" loading-text="Saving…">
        <Save :size="14" />
        <span>Save</span>
      </Button>
    </form>
  </div>
</template>
