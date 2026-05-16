<script setup lang="ts">
import { computed, h, onMounted, ref } from 'vue'
import { useAccountsStore, type Account } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { RouterLink } from 'vue-router'
import { formatMinor } from '@/lib/money'
import NewAccountForm from '@/components/forms/NewAccountForm.vue'
import EditAccountForm from '@/components/forms/EditAccountForm.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Button from '@/components/ui/Button.vue'
import type { ColumnDef } from '@tanstack/vue-table'
import { Pencil, Trash2, Inbox } from 'lucide-vue-next'

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

const columns = computed<ColumnDef<Account, unknown>[]>(() => [
  {
    id: 'name',
    accessorKey: 'name',
    header: 'Name',
    enableSorting: true,
  },
  {
    id: 'institution',
    accessorFn: (a) => a.institution ?? '',
    header: 'Institution',
    enableSorting: true,
    cell: ({ getValue }) => getValue() || h('span', { class: 'text-[var(--color-text-muted)]' }, '—'),
  },
  {
    id: 'type',
    accessorFn: (a) => typeLabels[a.type] ?? a.type,
    header: 'Type',
    enableSorting: true,
  },
  {
    id: 'balance',
    accessorFn: (a) => Number(a.balanceMinor),
    header: 'Balance',
    enableSorting: true,
    enableColumnFilter: false,
    meta: { align: 'right', cellClass: 'tabular font-medium' },
  },
  {
    id: 'actions',
    header: '',
    enableSorting: false,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-24' },
  },
])
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

    <DataTable v-else :data="accounts.accounts" :columns="columns" empty-text="No accounts.">
      <template #cell-name="{ row }">
        <RouterLink :to="{ name: 'account', params: { id: row.id } }" class="font-medium hover:text-[var(--color-accent)] transition-colors">
          {{ row.name }}
        </RouterLink>
      </template>
      <template #cell-type="{ row }">
        <span class="badge">{{ typeLabels[row.type] ?? row.type }}</span>
      </template>
      <template #cell-balance="{ row }">
        {{ formatMinor(row.balanceMinor, row.currency) }}
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button
            variant="ghost"
            icon-only
            :aria-label="`Edit ${row.name}`"
            @click="startEdit(row, $event)"
          >
            <Pencil :size="14" />
          </Button>
          <Button
            variant="danger"
            icon-only
            :aria-label="`Delete ${row.name}`"
            @click="remove(row, $event)"
          >
            <Trash2 :size="14" />
          </Button>
        </div>
      </template>
    </DataTable>

    <EditAccountForm
      v-model:account="editing"
      @saved="() => toasts.success('Account updated')"
    />
  </div>
</template>
