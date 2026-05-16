<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { useAuthStore } from '@/stores/auth'
import { Shield, ShieldOff, Trash2 } from 'lucide-vue-next'
import DataTable from '@/components/ui/DataTable.vue'
import Button from '@/components/ui/Button.vue'
import type { ColumnDef } from '@tanstack/vue-table'

interface AdminUser {
  id: string
  email: string
  roles: string[]
  isAdmin: boolean
}

const auth = useAuthStore()
const toasts = useToastsStore()

const users = ref<AdminUser[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    users.value = await api.get<AdminUser[]>('/api/users')
  } finally {
    loading.value = false
  }
}

onMounted(load)

const sortedUsers = computed(() =>
  [...users.value].sort((a, b) => {
    // Self first, then admins, then alphabetical
    if (a.id === auth.user?.id) return -1
    if (b.id === auth.user?.id) return 1
    if (a.isAdmin !== b.isAdmin) return a.isAdmin ? -1 : 1
    return a.email.localeCompare(b.email)
  }),
)

const columns = computed<ColumnDef<AdminUser, unknown>[]>(() => [
  { id: 'email', accessorKey: 'email', header: 'Email', enableSorting: true },
  {
    id: 'role',
    accessorFn: (u) => (u.isAdmin ? 'Admin' : 'User'),
    header: 'Role',
    enableSorting: true,
  },
  {
    id: 'actions',
    header: '',
    enableSorting: false,
    enableColumnFilter: false,
    meta: { align: 'right', headerClass: 'w-32' },
  },
])

async function toggleAdmin(u: AdminUser) {
  const action = u.isAdmin ? 'revoke admin from' : 'grant admin to'
  if (!confirm(`Sure you want to ${action} ${u.email}?`)) return
  try {
    const updated = await api.patch<AdminUser>(`/api/users/${u.id}/roles`, { isAdmin: !u.isAdmin })
    users.value = users.value.map((x) => (x.id === u.id ? updated : x))
    toasts.success(`${updated.isAdmin ? 'Granted' : 'Revoked'} admin for ${updated.email}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}

async function remove(u: AdminUser) {
  if (!confirm(`Delete ${u.email}? Their accounts and transactions will be removed too.`)) return
  try {
    await api.delete(`/api/users/${u.id}`)
    users.value = users.value.filter((x) => x.id !== u.id)
    toasts.success(`Deleted ${u.email}`)
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
  }
}
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Users</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Manage who can sign in. Admins can also generate registration codes.
      </p>
    </div>

    <div v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</div>

    <DataTable
      v-else
      :data="sortedUsers"
      :columns="columns"
      empty-text="No users."
    >
      <template #cell-email="{ row }">
        <span class="font-medium">{{ row.email }}</span>
        <span v-if="row.id === auth.user?.id" class="text-xs text-[var(--color-text-dim)] ml-2">(you)</span>
      </template>
      <template #cell-role="{ row }">
        <span v-if="row.isAdmin" class="badge" style="color: var(--color-accent);">Admin</span>
        <span v-else class="badge">User</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <Button
            variant="ghost"
            icon-only
            :aria-label="row.isAdmin ? `Revoke admin from ${row.email}` : `Grant admin to ${row.email}`"
            :title="row.isAdmin ? 'Revoke admin' : 'Grant admin'"
            :disabled="row.id === auth.user?.id && row.isAdmin"
            @click="toggleAdmin(row)"
          >
            <ShieldOff v-if="row.isAdmin" :size="14" />
            <Shield v-else :size="14" />
          </Button>
          <Button
            variant="danger"
            icon-only
            :aria-label="`Delete ${row.email}`"
            :disabled="row.id === auth.user?.id"
            @click="remove(row)"
          >
            <Trash2 :size="14" />
          </Button>
        </div>
      </template>
    </DataTable>
  </div>
</template>
