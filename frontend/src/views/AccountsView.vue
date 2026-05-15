<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { RouterLink } from 'vue-router'
import { formatMinor } from '@/lib/money'
import NewAccountForm from '@/components/NewAccountForm.vue'
import EditAccountForm from '@/components/EditAccountForm.vue'
import { Pencil, Trash2, ChevronRight, Inbox } from 'lucide-vue-next'

const accounts = useAccountsStore()
const toasts = useToastsStore()

onMounted(() => {
  if (!accounts.loaded) {
    accounts.fetchAll()
  }
})

const typeLabels: Record<string, string> = {
  bank_checking: 'Checking',
  bank_savings: 'Savings',
  cash: 'Cash',
  credit_card: 'Credit card',
  brokerage: 'Brokerage',
  crypto_exchange: 'Crypto exchange',
  crypto_wallet: 'Crypto wallet',
  real_estate: 'Real estate',
  vehicle: 'Vehicle',
  precious_metals: 'Precious metals',
  pillar_3a: 'Pillar 3a',
  other: 'Other',
}

async function remove(a: Account, ev: MouseEvent) {
  ev.preventDefault()
  ev.stopPropagation()
  if (!confirm(`Delete "${a.name}" and all its transactions?`)) return
  try {
    await accounts.remove(a.id)
    toasts.success(`Deleted ${a.name}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

const editing = ref<Account | null>(null)
function startEdit(a: Account, ev: MouseEvent) {
  ev.preventDefault()
  ev.stopPropagation()
  editing.value = a
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Accounts</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">
          {{ accounts.accounts.length }} {{ accounts.accounts.length === 1 ? 'account' : 'accounts' }}.
        </p>
      </div>
      <NewAccountForm />
    </header>

    <div v-if="accounts.accounts.length === 0" class="card p-10 text-center space-y-4">
      <div class="flex justify-center text-[var(--color-text-dim)]">
        <Inbox :size="40" />
      </div>
      <div>
        <p class="font-medium">No accounts yet</p>
        <p class="text-sm text-[var(--color-text-muted)]">Create one to start tracking balances and importing transactions.</p>
      </div>
      <div class="flex justify-center">
        <NewAccountForm />
      </div>
    </div>

    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Institution</th>
            <th>Type</th>
            <th class="text-right">Balance</th>
            <th class="w-24"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in accounts.accounts" :key="a.id" class="cursor-pointer">
            <td>
              <RouterLink :to="{ name: 'account', params: { id: a.id } }" class="flex items-center gap-2 font-medium">
                {{ a.name }}
                <ChevronRight :size="14" class="text-[var(--color-text-dim)]" />
              </RouterLink>
            </td>
            <td class="text-[var(--color-text-muted)]">{{ a.institution ?? '—' }}</td>
            <td>
              <span class="badge">{{ typeLabels[a.type] ?? a.type }}</span>
            </td>
            <td class="text-right tabular font-medium">
              {{ formatMinor(a.balanceMinor, a.currency) }}
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button
                  class="btn btn-ghost p-1.5"
                  :aria-label="`Edit ${a.name}`"
                  @click="startEdit(a, $event)"
                >
                  <Pencil :size="14" />
                </button>
                <button
                  class="btn btn-danger p-1.5"
                  :aria-label="`Delete ${a.name}`"
                  @click="remove(a, $event)"
                >
                  <Trash2 :size="14" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EditAccountForm
      v-model:account="editing"
      @saved="() => toasts.success('Account updated')"
    />
  </div>
</template>
