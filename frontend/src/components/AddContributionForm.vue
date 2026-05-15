<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { parseMajor } from '@/lib/money'
import { PiggyBank } from 'lucide-vue-next'
import BaseModal from '@/components/BaseModal.vue'

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
  <button class="btn btn-secondary" @click="open = true">
    <PiggyBank :size="16" />
    <span>Add contribution</span>
  </button>

  <BaseModal v-model:open="open" title="New 3a contribution" @close="reset">
    <form id="add-contribution-form" class="space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="space-y-1.5">
          <label class="label">Date</label>
          <input v-model="occurredAt" type="date" required class="input" />
        </div>
        <div class="space-y-1.5">
          <label class="label">Amount ({{ currency }})</label>
          <input v-model="amount" placeholder="600" required class="input tabular" />
        </div>
      </div>
      <p v-if="missing.length > 0" class="text-xs text-[var(--color-text-dim)]">
        No price data for: {{ missing.join(', ') }}. Those slices were skipped — run `app:prices:backfill` to fetch history.
      </p>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
      <button type="submit" form="add-contribution-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Record contribution' }}
      </button>
    </template>
  </BaseModal>
</template>
