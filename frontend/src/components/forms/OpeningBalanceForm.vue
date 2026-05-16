<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { parseMajor } from '@/lib/money'
import { Sparkles } from 'lucide-vue-next'
import DateField from '@/components/ui/DateField.vue'
import ModalForm from '@/components/ui/ModalForm.vue'
import PriceField from '@/components/ui/PriceField.vue'
import Button from '@/components/ui/Button.vue'

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
      { occurredAt: occurredAt.value, amountMinor, description: 'Opening balance', isOpeningBalance: true },
    )
    missing.value = res.missingPrices
    toasts.success('Starting balance saved')
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
    title="Set starting balance"
    submit-label="Save starting balance"
    :submitting="submitting"
    :error="error"
    @submit="submit"
    @close="reset"
  >
    <template #trigger="{ open: openModal }">
      <Button @click="openModal">
        <Sparkles :size="16" />
        <span>Set starting balance</span>
      </Button>
    </template>

    <p class="text-xs text-[var(--color-text-dim)]">
      Use this once when you set up an existing 3a. Enter the current value of your account — it will be split across the allocation at today's prices, and from this point onward growth is tracked from real ETF history.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">As of</label>
        <DateField v-model="occurredAt" required />
      </div>
      <PriceField v-model="amount" label="Current value" :currency="currency" placeholder="30000" required />
    </div>
    <p v-if="missing.length > 0" class="text-xs text-[var(--color-text-dim)]">
      No price data for: {{ missing.join(', ') }} — those slices stayed as cash.
    </p>
  </ModalForm>
</template>
