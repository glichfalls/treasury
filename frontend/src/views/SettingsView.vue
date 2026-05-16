<script setup lang="ts">
import BackupPanel from '@/components/panels/BackupPanel.vue'
import ChangePasswordPanel from '@/components/panels/ChangePasswordPanel.vue'
import PreferencesPanel from '@/components/panels/PreferencesPanel.vue'
import PricesAdminPanel from '@/components/panels/PricesAdminPanel.vue'
import RegistrationCodesPanel from '@/components/panels/RegistrationCodesPanel.vue'
import TagsPanel from '@/components/panels/TagsPanel.vue'
import UsersPanel from '@/components/panels/UsersPanel.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">Account, backup, and admin preferences.</p>
    </header>

    <PreferencesPanel />

    <ChangePasswordPanel />

    <TagsPanel />

    <BackupPanel />

    <template v-if="auth.isAdmin">
      <UsersPanel />
      <RegistrationCodesPanel />
      <PricesAdminPanel />
    </template>
  </div>
</template>
