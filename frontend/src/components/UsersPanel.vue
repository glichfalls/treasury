<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { useAuthStore } from '@/stores/auth'
import { Shield, ShieldOff, Trash2 } from 'lucide-vue-next'

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

    <div v-else-if="users.length === 0" class="text-sm text-[var(--color-text-muted)]">
      No users.
    </div>

    <div v-else class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr>
            <th>Email</th>
            <th>Role</th>
            <th class="w-32 text-right"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in sortedUsers" :key="u.id">
            <td>
              <span class="font-medium">{{ u.email }}</span>
              <span v-if="u.id === auth.user?.id" class="text-xs text-[var(--color-text-dim)] ml-2">(you)</span>
            </td>
            <td>
              <span v-if="u.isAdmin" class="badge" style="color: var(--color-accent);">Admin</span>
              <span v-else class="badge">User</span>
            </td>
            <td class="text-right">
              <div class="flex justify-end gap-1">
                <button
                  type="button"
                  class="btn btn-ghost p-1.5"
                  :aria-label="u.isAdmin ? `Revoke admin from ${u.email}` : `Grant admin to ${u.email}`"
                  :title="u.isAdmin ? 'Revoke admin' : 'Grant admin'"
                  :disabled="u.id === auth.user?.id && u.isAdmin"
                  @click="toggleAdmin(u)"
                >
                  <ShieldOff v-if="u.isAdmin" :size="14" />
                  <Shield v-else :size="14" />
                </button>
                <button
                  type="button"
                  class="btn btn-danger p-1.5"
                  :aria-label="`Delete ${u.email}`"
                  :disabled="u.id === auth.user?.id"
                  @click="remove(u)"
                >
                  <Trash2 :size="14" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
