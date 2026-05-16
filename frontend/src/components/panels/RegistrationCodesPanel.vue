<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { Plus, Trash2, Copy, Check } from 'lucide-vue-next'
import DataTable from '@/components/ui/DataTable.vue'
import type { ColumnDef } from '@tanstack/vue-table'

interface RegistrationCode {
  id: string
  code: string
  label: string | null
  createdAt: string
  createdByEmail: string
  usedAt: string | null
  usedByEmail: string | null
}

const toasts = useToastsStore()

const codes = ref<RegistrationCode[]>([])
const loading = ref(false)
const creating = ref(false)
const newLabel = ref('')
const copiedId = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    codes.value = await api.get<RegistrationCode[]>('/api/registration-codes')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function createCode() {
  creating.value = true
  try {
    const created = await api.post<RegistrationCode>('/api/registration-codes', {
      label: newLabel.value.trim() || null,
    })
    codes.value = [created, ...codes.value]
    newLabel.value = ''
    toasts.success(`Code created: ${created.code}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  } finally {
    creating.value = false
  }
}

async function revoke(c: RegistrationCode) {
  if (!confirm(`Revoke code ${c.code}?`)) return
  try {
    await api.delete(`/api/registration-codes/${c.id}`)
    codes.value = codes.value.filter((x) => x.id !== c.id)
    toasts.success('Code revoked')
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

async function copyCode(c: RegistrationCode) {
  try {
    await navigator.clipboard.writeText(c.code)
    copiedId.value = c.id
    setTimeout(() => { if (copiedId.value === c.id) copiedId.value = null }, 1500)
  } catch (e) {
    toasts.error('Could not copy to clipboard')
  }
}

function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('de-CH', { year: 'numeric', month: 'short', day: '2-digit' })
}

const columns = computed<ColumnDef<RegistrationCode, unknown>[]>(() => [
  { id: 'code', accessorKey: 'code', header: 'Code', enableSorting: true },
  {
    id: 'label',
    accessorFn: (c) => c.label ?? '',
    header: 'Label',
    enableSorting: true,
    meta: { cellClass: 'text-[var(--color-text-muted)]' },
  },
  {
    id: 'status',
    accessorFn: (c) => (c.usedAt ? `used:${c.usedByEmail}` : 'unused'),
    header: 'Status',
    enableSorting: true,
  },
  {
    id: 'createdAt',
    accessorKey: 'createdAt',
    header: 'Created',
    enableSorting: true,
    meta: { cellClass: 'text-xs text-[var(--color-text-muted)]' },
  },
  {
    id: 'actions',
    header: '',
    enableSorting: false,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-10' },
  },
])
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Registration codes</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Single-use invite codes. Each can register exactly one new user.
      </p>
    </div>

    <form class="flex flex-wrap items-end gap-3" @submit.prevent="createCode">
      <div class="space-y-1 flex-1 min-w-[12rem]">
        <label class="label">Label (optional)</label>
        <input v-model="newLabel" placeholder="e.g. for Alice" class="input" />
      </div>
      <button type="submit" class="btn btn-primary" :disabled="creating">
        <Plus :size="14" />
        <span>{{ creating ? 'Creating…' : 'New code' }}</span>
      </button>
    </form>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>

    <DataTable
      v-else
      :data="codes"
      :columns="columns"
      empty-text="No codes yet. Create one to invite a user."
    >
      <template #cell-code="{ row }">
        <button
          type="button"
          class="font-mono text-sm flex items-center gap-1.5 hover:text-[var(--color-accent)] transition-colors"
          :title="copiedId === row.id ? 'Copied' : 'Click to copy'"
          @click="copyCode(row)"
        >
          <span class="tracking-wider">{{ row.code }}</span>
          <Check v-if="copiedId === row.id" :size="12" class="text-[var(--color-positive)]" />
          <Copy v-else :size="12" class="text-[var(--color-text-dim)]" />
        </button>
      </template>
      <template #cell-label="{ row }">
        {{ row.label ?? '—' }}
      </template>
      <template #cell-status="{ row }">
        <span v-if="row.usedAt" class="badge" style="color: var(--color-positive);">
          Used by {{ row.usedByEmail }}
        </span>
        <span v-else class="badge">Unused</span>
      </template>
      <template #cell-createdAt="{ row }">
        {{ shortDate(row.createdAt) }}
      </template>
      <template #cell-actions="{ row }">
        <button
          v-if="!row.usedAt"
          type="button"
          class="btn btn-danger p-1.5"
          aria-label="Revoke code"
          @click="revoke(row)"
        >
          <Trash2 :size="14" />
        </button>
      </template>
    </DataTable>
  </div>
</template>
