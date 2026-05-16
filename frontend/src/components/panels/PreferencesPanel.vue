<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToastsStore } from '@/stores/toasts'
import { Save } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

const auth = useAuthStore()
const toasts = useToastsStore()

// Curated list — covers ~95% of accounts a Swiss user is likely to touch. Anyone
// who needs something exotic can still type it in the input.
const COMMON_CURRENCIES = [
  'CHF', 'EUR', 'USD', 'GBP', 'JPY', 'CAD', 'AUD', 'SEK', 'NOK', 'DKK',
]

const baseCurrency = ref(auth.user?.baseCurrency ?? 'CHF')
const submitting = ref(false)

watch(
  () => auth.user?.baseCurrency,
  (v) => {
    if (v) baseCurrency.value = v
  },
)

async function submit() {
  submitting.value = true
  try {
    const ccy = baseCurrency.value.trim().toUpperCase()
    if (!/^[A-Z]{3}$/.test(ccy)) {
      throw new Error('Currency must be a 3-letter code')
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
      <div class="space-y-1.5">
        <label class="label" for="pref-base-ccy">Base currency</label>
        <input
          id="pref-base-ccy"
          v-model="baseCurrency"
          list="common-currencies"
          required
          maxlength="3"
          pattern="[A-Za-z]{3}"
          class="input uppercase tabular tracking-widest"
        />
        <datalist id="common-currencies">
          <option v-for="c in COMMON_CURRENCIES" :key="c" :value="c" />
        </datalist>
      </div>

      <Button type="submit" variant="primary" :loading="submitting" loading-text="Saving…">
        <Save :size="14" />
        <span>Save</span>
      </Button>
    </form>
  </div>
</template>
