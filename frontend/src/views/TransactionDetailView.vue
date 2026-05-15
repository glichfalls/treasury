<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { api } from '@/lib/api'
import { useAccountsStore, type Transaction } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { formatMinor, formatQuantity } from '@/lib/money'
import { categoryMeta } from '@/lib/categories'
import { featuresFor } from '@/lib/accountFeatures'
import EditTransactionForm from '@/components/EditTransactionForm.vue'
import { ChevronLeft, Pencil, Trash2, ArrowRight } from 'lucide-vue-next'

interface DetailedTransaction extends Transaction {
  accountName: string
  accountType: string
}

const route = useRoute()
const router = useRouter()
const accounts = useAccountsStore()
const toasts = useToastsStore()

const accountId = computed(() => String(route.params.accountId))
const transactionId = computed(() => String(route.params.id))

const transaction = ref<DetailedTransaction | null>(null)
const loading = ref(false)
const editing = ref<Transaction | null>(null)

async function load() {
  if (!accountId.value || !transactionId.value) return
  loading.value = true
  try {
    transaction.value = await api.get<DetailedTransaction>(
      `/api/accounts/${accountId.value}/transactions/${transactionId.value}`,
    )
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([accountId, transactionId], load)

const features = computed(() =>
  transaction.value ? featuresFor(transaction.value.accountType) : featuresFor('other'),
)

const typeLabels: Record<string, string> = {
  deposit: 'Deposit',
  withdrawal: 'Withdrawal',
  trade_buy: 'Buy',
  trade_sell: 'Sell',
  fee: 'Fee',
  interest: 'Interest',
  dividend: 'Dividend',
  fx_conversion: 'FX conversion',
  other: 'Other',
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'long', day: '2-digit' })
}

async function deleteTransaction() {
  if (!transaction.value) return
  if (!confirm(`Delete this transaction (${transaction.value.description ?? transaction.value.type})?`)) return
  try {
    await accounts.deleteTransaction(accountId.value, transactionId.value)
    toasts.success('Transaction deleted')
    await router.push({ name: 'account', params: { id: accountId.value } })
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

async function onEditSaved() {
  await load()
  toasts.success('Transaction updated')
}
</script>

<template>
  <div class="space-y-6 max-w-3xl mx-auto">
    <RouterLink
      :to="{ name: 'account', params: { id: accountId } }"
      class="inline-flex items-center gap-1 text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
    >
      <ChevronLeft :size="16" />
      <span>Back to account</span>
    </RouterLink>

    <div v-if="loading" class="text-[var(--color-text-muted)]">Loading…</div>

    <template v-else-if="transaction">
      <!-- Header -->
      <header class="space-y-3">
        <div class="flex items-baseline gap-2">
          <span class="badge">{{ typeLabels[transaction.type] ?? transaction.type }}</span>
          <span v-if="transaction.source !== 'manual'" class="text-xs text-[var(--color-text-dim)]">
            via {{ transaction.source }}
          </span>
        </div>
        <h1 class="text-2xl font-semibold tracking-tight">
          {{ transaction.description ?? 'Untitled transaction' }}
        </h1>
        <p
          class="text-4xl font-semibold tracking-tight tabular"
          :class="BigInt(transaction.amountMinor) < 0n ? 'text-[var(--color-negative)]' : 'text-[var(--color-positive)]'"
        >
          {{ formatMinor(transaction.amountMinor, transaction.currency) }}
        </p>
      </header>

      <!-- Action bar -->
      <div class="flex items-center gap-2 pb-2 border-b" style="border-color: var(--color-border);">
        <button class="btn btn-ghost" type="button" @click="editing = transaction">
          <Pencil :size="14" />
          <span>Edit</span>
        </button>
        <button class="btn btn-danger" type="button" @click="deleteTransaction">
          <Trash2 :size="14" />
          <span>Delete</span>
        </button>
      </div>

      <!-- Detail fields -->
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
        <div class="space-y-1">
          <dt class="label">Date</dt>
          <dd class="tabular">{{ shortDate(transaction.occurredAt) }}</dd>
        </div>
        <div class="space-y-1">
          <dt class="label">Account</dt>
          <dd>
            <RouterLink
              :to="{ name: 'account', params: { id: transaction.accountId } }"
              class="inline-flex items-center gap-1 text-[var(--color-accent)] hover:underline"
            >
              {{ transaction.accountName }}
              <ArrowRight :size="12" />
            </RouterLink>
          </dd>
        </div>
        <div v-if="features.showCategories && categoryMeta(transaction.category)" class="space-y-1">
          <dt class="label">Category</dt>
          <dd class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: categoryMeta(transaction.category)!.color }"></span>
            <span>{{ categoryMeta(transaction.category)!.label }}</span>
          </dd>
        </div>
        <div class="space-y-1">
          <dt class="label">Currency</dt>
          <dd class="tabular">{{ transaction.currency }}</dd>
        </div>
        <div v-if="transaction.assetIsin" class="space-y-1">
          <dt class="label">Asset</dt>
          <dd>
            <RouterLink
              :to="{ name: 'asset', params: { isin: transaction.assetIsin } }"
              class="inline-flex items-center gap-1 text-[var(--color-accent)] hover:underline"
            >
              {{ transaction.assetIsin }}
              <ArrowRight :size="12" />
            </RouterLink>
          </dd>
        </div>
        <div v-if="transaction.assetQuantity" class="space-y-1">
          <dt class="label">Quantity</dt>
          <dd class="tabular">{{ formatQuantity(transaction.assetQuantity) }}</dd>
        </div>
      </dl>

      <!-- Tags -->
      <section class="space-y-2">
        <h2 class="label">Tags</h2>
        <div v-if="transaction.tags && transaction.tags.length > 0" class="flex flex-wrap gap-1.5">
          <RouterLink
            v-for="t in transaction.tags"
            :key="t"
            :to="{ name: 'tag', params: { tag: t } }"
            class="inline-flex items-center gap-1 text-xs rounded px-2 py-1 hover:opacity-80 transition-opacity"
            style="background-color: color-mix(in srgb, var(--color-accent) 18%, transparent); color: var(--color-accent);"
          >
            {{ t }}
          </RouterLink>
        </div>
        <p v-else class="text-sm text-[var(--color-text-muted)]">No tags. Edit to add some.</p>
      </section>

      <EditTransactionForm v-model:transaction="editing" @saved="onEditSaved" />
    </template>

    <p v-else class="text-[var(--color-text-muted)]">Transaction not found.</p>
  </div>
</template>
