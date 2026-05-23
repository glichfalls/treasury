<script setup lang="ts">
import { ref, computed } from 'vue'
import BackupPanel from '@/components/panels/BackupPanel.vue'
import ChangePasswordPanel from '@/components/panels/ChangePasswordPanel.vue'
import IntegrationsPanel from '@/components/panels/IntegrationsPanel.vue'
import PreferencesPanel from '@/components/panels/PreferencesPanel.vue'
import PricesAdminPanel from '@/components/panels/PricesAdminPanel.vue'
import RegistrationCodesPanel from '@/components/panels/RegistrationCodesPanel.vue'
import TagsPanel from '@/components/panels/TagsPanel.vue'
import UsersPanel from '@/components/panels/UsersPanel.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

type Tab = 'account' | 'data' | 'admin'
const tab = ref<Tab>('account')

const tabs = computed<{ value: Tab; label: string }[]>(() => {
  const t: { value: Tab; label: string }[] = [
    { value: 'account', label: 'Account' },
    { value: 'data', label: 'Data' },
  ]
  if (auth.isAdmin) {
    t.push({ value: 'admin', label: 'Admin' })
  }
  return t
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">Account, data, and admin preferences.</p>
    </header>

    <SegmentedControl v-model="tab" variant="tabs" :options="tabs" />

    <div v-if="tab === 'account'" class="space-y-6">
      <PreferencesPanel />
      <ChangePasswordPanel />
    </div>

    <div v-else-if="tab === 'data'" class="space-y-6">
      <TagsPanel />
      <BackupPanel />
    </div>

    <div v-else-if="tab === 'admin' && auth.isAdmin" class="space-y-6">
      <UsersPanel />
      <RegistrationCodesPanel />
      <IntegrationsPanel />
      <PricesAdminPanel />
    </div>
  </div>
</template>
