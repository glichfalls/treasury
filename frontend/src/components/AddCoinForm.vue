<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { parseMajor } from '@/lib/money'
import { Coins, Plus, X } from 'lucide-vue-next'

interface CatalogEntry {
  isin: string
  name: string
  currency: string
  unitWeightGrams: string
  pricePremiumPct: string | null
}

const props = defineProps<{ accountId: string; currency: string }>()
const emit = defineEmits<{ created: [] }>()

const today = new Date().toISOString().slice(0, 10)
const open = ref(false)
const catalog = ref<CatalogEntry[]>([])
const isin = ref('')
const quantity = ref('1')
const amount = ref('')
const occurredAt = ref(today)
const error = ref<string | null>(null)
const submitting = ref(false)

onMounted(async () => {
  catalog.value = await api.get<CatalogEntry[]>('/api/accounts/coins/catalog')
  if (catalog.value.length > 0 && catalog.value[0]) {
    isin.value = catalog.value[0].isin
  }
})

async function submit() {
  error.value = null
  submitting.value = true
  try {
    // Negative cash flow because it's a purchase.
    const amountMinor = '-' + parseMajor(amount.value, props.currency)
    await api.post(`/api/accounts/${props.accountId}/transactions`, {
      occurredAt: occurredAt.value,
      amountMinor,
      type: 'trade_buy',
      assetIsin: isin.value,
      assetQuantity: quantity.value,
      description: `${quantity.value}× ${catalog.value.find((c) => c.isin === isin.value)?.name ?? isin.value}`,
    })
    amount.value = ''
    quantity.value = '1'
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
    <Coins :size="16" />
    <span>Add coin</span>
  </button>

  <form v-else class="card p-5 space-y-4" @submit.prevent="submit">
    <div class="flex items-center justify-between">
      <h3 class="font-medium flex items-center gap-2">
        <Coins :size="16" class="text-[var(--color-accent)]" />
        New coin purchase
      </h3>
      <button type="button" class="btn btn-ghost p-1" @click="open = false">
        <X :size="16" />
      </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5 sm:col-span-2">
        <label class="label">Coin</label>
        <select v-model="isin" class="input">
          <option v-for="c in catalog" :key="c.isin" :value="c.isin">
            {{ c.name }} · {{ c.unitWeightGrams }} g
          </option>
        </select>
      </div>
      <div class="space-y-1.5">
        <label class="label">Date</label>
        <input v-model="occurredAt" type="date" required class="input" />
      </div>
      <div class="space-y-1.5">
        <label class="label">Quantity</label>
        <input v-model="quantity" type="number" min="0" step="any" required class="input tabular" />
        <p class="text-xs text-[var(--color-text-dim)]">For odd-weight bars/scrap, pick the 1 g bar and enter the gram count here.</p>
      </div>
      <div class="space-y-1.5 sm:col-span-2">
        <label class="label">Total paid ({{ currency }})</label>
        <input v-model="amount" placeholder="2300" required class="input tabular" />
      </div>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="btn btn-primary" :disabled="submitting">
        <Plus v-if="!submitting" :size="16" />
        {{ submitting ? 'Saving…' : 'Add purchase' }}
      </button>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </div>
  </form>
</template>
