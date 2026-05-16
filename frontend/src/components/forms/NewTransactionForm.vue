<script setup lang="ts">
import { computed, ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { parseMajor } from '@/lib/money'
import { Plus } from 'lucide-vue-next'
import DateField from '@/components/ui/DateField.vue'
import ModalForm from '@/components/ui/ModalForm.vue'
import TextField from '@/components/ui/TextField.vue'
import PriceField from '@/components/ui/PriceField.vue'
import SelectField from '@/components/ui/SelectField.vue'
import { CATEGORIES } from '@/lib/categories'

const props = defineProps<{ accountId: string; currency: string }>()
const emit = defineEmits<{ created: [] }>()

const accounts = useAccountsStore()
const toasts = useToastsStore()

const today = new Date().toISOString().slice(0, 10)
const open = ref(false)
const occurredAt = ref(today)
const amount = ref('')
const description = ref('')
const category = ref<string | null>(null)
const error = ref<string | null>(null)
const submitting = ref(false)

const categoryOptions = computed(() => CATEGORIES.map((c) => ({ value: c.value, label: c.label })))

function reset() {
  occurredAt.value = today
  amount.value = ''
  description.value = ''
  category.value = null
  error.value = null
}

async function submit() {
  error.value = null
  submitting.value = true
  try {
    const amountMinor = parseMajor(amount.value, props.currency)
    await accounts.addTransaction(props.accountId, {
      occurredAt: occurredAt.value,
      amountMinor,
      description: description.value.trim() || null,
      category: category.value || null,
    })
    toasts.success('Transaction saved')
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
    title="New transaction"
    submit-label="Save transaction"
    :submitting="submitting"
    :error="error"
    @submit="submit"
    @close="reset"
  >
    <template #trigger="{ open: openModal }">
      <button class="btn btn-secondary" @click="openModal">
        <Plus :size="16" />
        <span>Add transaction</span>
      </button>
    </template>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="space-y-1.5">
        <label class="label">Date</label>
        <DateField v-model="occurredAt" required />
      </div>
      <PriceField
        v-model="amount"
        label="Amount"
        :currency="currency"
        placeholder="-12.50 for spending"
        required
      />
      <SelectField
        v-model="category"
        label="Category"
        :options="categoryOptions"
        allow-empty
        empty-label="Uncategorized"
      />
      <TextField
        v-model="description"
        label="Description"
        placeholder="Groceries at Migros"
      />
    </div>
  </ModalForm>
</template>
