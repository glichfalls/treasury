<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { parseMajor } from '@/lib/money'
import { PiggyBank } from 'lucide-vue-next'
import DateField from '@/components/ui/DateField.vue'
import ModalForm from '@/components/ui/ModalForm.vue'
import PriceField from '@/components/ui/PriceField.vue'

const toasts = useToastsStore()

const props = defineProps<{ accountId: string; currency: string }>()
const emit = defineEmits<{ created: [] }>()

const today = new Date().toISOString().slice(0, 10)
const open = ref(false)
const occurredAt = ref(today)
const amount = ref('')
const error = ref<string | null>(null)
const missing = ref<string[]>([])
const submitting = ref(false)

function reset() {
  occurredAt.value = today
  amount.value = ''
  error.value = null
  missing.value = []
}

async function submit() {
  error.value = null
  missing.value = []
  submitting.value = true
  try {
    const amountMinor = parseMajor(amount.value, props.currency)
    const res = await api.post<{ tradeCount: number; missingPrices: string[] }>(
      `/api/accounts/${props.accountId}/contributions`,
      { occurredAt: occurredAt.value, amountMinor },
    )
    missing.value = res.missingPrices
    if (res.missingPrices.length > 0) {
      toasts.info(`Contribution saved (no price data for ${res.missingPrices.length} slice(s))`)
    } else {
      toasts.success('Contribution saved')
    }
    amount.value = ''
    open.value = false
    emit('created')
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
    title="New 3a contribution"
    submit-label="Record contribution"
    :submitting="submitting"
    :error="error"
    @submit="submit"
    @close="reset"
  >
    <template #trigger="{ open: openModal }">
      <button class="btn btn-secondary" @click="openModal">
        <PiggyBank :size="16" />
        <span>Add contribution</span>
      </button>
    </template>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">Date</label>
        <DateField v-model="occurredAt" required />
      </div>
      <PriceField v-model="amount" label="Amount" :currency="currency" placeholder="600" required />
    </div>
    <p v-if="missing.length > 0" class="text-xs text-[var(--color-text-dim)]">
      No price data for: {{ missing.join(', ') }}. Those slices were skipped — run `app:prices:backfill` to fetch history.
    </p>
  </ModalForm>
</template>
