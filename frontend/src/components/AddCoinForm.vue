<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { parseMajor } from '@/lib/money'
import { Coins } from 'lucide-vue-next'
import BaseModal from '@/components/BaseModal.vue'
import DateField from '@/components/DateField.vue'

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

function reset() {
  quantity.value = '1'
  amount.value = ''
  occurredAt.value = today
  error.value = null
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const amountMinor = '-' + parseMajor(amount.value, props.currency)
    await api.post(`/api/accounts/${props.accountId}/transactions`, {
      occurredAt: occurredAt.value,
      amountMinor,
      type: 'trade_buy',
      assetIsin: isin.value,
      assetQuantity: quantity.value,
      description: `${quantity.value}× ${catalog.value.find((c) => c.isin === isin.value)?.name ?? isin.value}`,
    })
    reset()
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
    <Coins :size="16" />
    <span>Add coin</span>
  </button>

  <BaseModal v-model:open="open" title="New coin purchase" @close="reset">
    <form id="add-coin-form" class="space-y-4" @submit.prevent="submit">
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
          <DateField v-model="occurredAt" required />
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
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
      <button type="submit" form="add-coin-form" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Saving…' : 'Add purchase' }}
      </button>
    </template>
  </BaseModal>
</template>
