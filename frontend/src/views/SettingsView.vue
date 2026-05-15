<script setup lang="ts">
import BackupPanel from '@/components/BackupPanel.vue'
import ChangePasswordPanel from '@/components/ChangePasswordPanel.vue'
import PreferencesPanel from '@/components/PreferencesPanel.vue'
import RegistrationCodesPanel from '@/components/RegistrationCodesPanel.vue'
import TagsPanel from '@/components/TagsPanel.vue'
import UsersPanel from '@/components/UsersPanel.vue'
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
    </template>
  </div>
</template>
