<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/lib/api'
import { parseMajor } from '@/lib/money'
import { Sparkles, X } from 'lucide-vue-next'

const props = defineProps<{ accountId: string; currency: string }>()
const emit = defineEmits<{ created: [] }>()

const today = new Date().toISOString().slice(0, 10)
const open = ref(false)
const occurredAt = ref(today)
const amount = ref('')
const error = ref<string | null>(null)
const missing = ref<string[]>([])
const submitting = ref(false)

async function submit() {
  error.value = null
  missing.value = []
  submitting.value = true
  try {
    const amountMinor = parseMajor(amount.value, props.currency)
    const res = await api.post<{ tradeCount: number; missingPrices: string[] }>(
      `/api/accounts/${props.accountId}/contributions`,
      { occurredAt: occurredAt.value, amountMinor, description: 'Opening balance' },
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
  <button v-if="!open" class="btn btn-secondary" @click="open = true">
    <Sparkles :size="16" />
    <span>Set starting balance</span>
  </button>

  <form v-else class="card p-5 space-y-4" @submit.prevent="submit">
    <div class="flex items-center justify-between">
      <h3 class="font-medium flex items-center gap-2">
        <Sparkles :size="16" class="text-[var(--color-accent)]" />
        Set starting balance
      </h3>
      <button type="button" class="btn btn-ghost p-1" @click="open = false">
        <X :size="16" />
      </button>
    </div>

    <p class="text-xs text-[var(--color-text-dim)]">
      Use this once when you set up an existing 3a. Enter the current value of your account — it will be split across the allocation at today's prices, and from this point onward growth is tracked from real ETF history.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">As of</label>
        <input v-model="occurredAt" type="date" required class="input" />
      </div>
      <div class="space-y-1.5">
        <label class="label">Current value ({{ currency }})</label>
        <input v-model="amount" placeholder="30000" required class="input tabular" />
      </div>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Save starting balance' }}
      </button>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </div>
    <p v-if="missing.length > 0" class="text-xs text-[var(--color-text-dim)]">
      No price data for: {{ missing.join(', ') }} — those slices stayed as cash.
    </p>
  </form>
</template>
