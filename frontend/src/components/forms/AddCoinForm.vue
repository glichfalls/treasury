<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { parseMajor } from '@/lib/money'
import { Coins } from 'lucide-vue-next'
import DateField from '@/components/ui/DateField.vue'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import PriceField from '@/components/ui/PriceField.vue'
import SelectField from '@/components/ui/SelectField.vue'

const toasts = useToastsStore()

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
const isin = ref<string>('')
const quantity = ref('1')
const amount = ref('')
const occurredAt = ref(today)
const error = ref<string | null>(null)
const submitting = ref(false)

const catalogOptions = computed(() =>
  catalog.value.map((c) => ({
    value: c.isin,
    label: c.name,
    hint: `${c.unitWeightGrams} g`,
  })),
)

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
    toasts.success(`Added ${quantity.value}× ${catalog.value.find((c) => c.isin === isin.value)?.name ?? 'coin'}`)
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
  <ModalForm
    v-model:open="open"
    title="New coin purchase"
    submit-label="Add purchase"
    :submitting="submitting"
    :error="error"
    @submit="submit"
    @close="reset"
  >
    <template #trigger="{ open: openModal }">
      <button class="btn btn-secondary" @click="openModal">
        <Coins :size="16" />
        <span>Add coin</span>
      </button>
    </template>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2">
        <SelectField v-model="isin" label="Coin" :options="catalogOptions" searchable />
      </div>
      <div class="space-y-1.5">
        <label class="label">Date</label>
        <DateField v-model="occurredAt" required />
      </div>
      <TextField
        v-model="quantity"
        label="Quantity"
        type="number"
        min="0"
        step="any"
        required
        class="tabular"
        hint="For odd-weight bars/scrap, pick the 1 g bar and enter the gram count here."
      />
      <div class="sm:col-span-2">
        <PriceField v-model="amount" label="Total paid" :currency="currency" placeholder="2300" required />
      </div>
    </div>
  </ModalForm>
</template>
