<script setup lang="ts">
import { onMounted, computed, ref } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import { RouterLink } from 'vue-router'
import { formatMinor } from '@/lib/money'
import NewAccountForm from '@/components/NewAccountForm.vue'
import NetWorthChart from '@/components/NetWorthChart.vue'
import CashFlowChart from '@/components/CashFlowChart.vue'
import AllocationDonut from '@/components/AllocationDonut.vue'
import { Trash2, ChevronRight, Inbox } from 'lucide-vue-next'

const accounts = useAccountsStore()
const range = ref<'6mo' | '1y' | '2y' | '5y' | 'all'>('2y')
const networthMode = ref<'total' | 'stacked'>('total')

onMounted(() => {
  if (!accounts.loaded) {
    accounts.fetchAll()
  }
})

const netWorthByCurrency = computed(() => {
  const totals = new Map<string, bigint>()
  for (const a of accounts.accounts) {
    totals.set(a.currency, (totals.get(a.currency) ?? 0n) + BigInt(a.balanceMinor))
  }
  return [...totals.entries()].map(([currency, minor]) => ({ currency, minor: minor.toString() }))
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
  other: 'Other',
}

async function remove(a: Account, ev: MouseEvent) {
  ev.preventDefault()
  ev.stopPropagation()
  if (!confirm(`Delete "${a.name}" and all its transactions?`)) return
  await accounts.remove(a.id)
}
</script>

<template>
  <div class="mx-auto max-w-6xl px-6 py-10 space-y-10">
    <section>
      <h2 class="label mb-3">Net worth</h2>
      <div v-if="netWorthByCurrency.length === 0" class="text-[var(--color-text-muted)]">
        Add an account to get started.
      </div>
      <div v-else class="flex flex-wrap gap-x-10 gap-y-3">
        <div v-for="t in netWorthByCurrency" :key="t.currency">
          <div class="text-3xl font-semibold tracking-tight tabular">
            {{ formatMinor(t.minor, t.currency) }}
          </div>
        </div>
      </div>
    </section>

    <section v-if="accounts.accounts.length > 0" class="space-y-4">
      <div class="flex items-center justify-end gap-1 -mb-2">
        <button
          v-for="m in (['total','stacked'] as const)"
          :key="m"
          :class="['text-xs px-2 py-0.5 rounded transition-colors',
            m === networthMode
              ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
              : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
          @click="networthMode = m"
        >{{ m === 'total' ? 'Total' : 'Cash + holdings' }}</button>
      </div>
      <NetWorthChart
        endpoint="/api/networth/timeseries"
        title="Net worth over time"
        :range="range"
        :mode="networthMode"
        granularity="weekly"
        @update:range="range = $event"
      />

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <CashFlowChart :months="18" />
        <AllocationDonut endpoint="/api/allocation" />
      </div>
    </section>

    <section class="space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium">Accounts</h2>
        <NewAccountForm v-if="accounts.accounts.length > 0" />
      </div>

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
              <th class="w-10"></th>
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
                <button
                  class="btn btn-danger p-1.5"
                  :aria-label="`Delete ${a.name}`"
                  @click="remove(a, $event)"
                >
                  <Trash2 :size="14" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
