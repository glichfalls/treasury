<script setup lang="ts">
import BackupPanel from '@/components/BackupPanel.vue'
import RegistrationCodesPanel from '@/components/RegistrationCodesPanel.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">Backup, restore, and account preferences.</p>
    </header>

    <BackupPanel />

    <RegistrationCodesPanel v-if="auth.isAdmin" />
  </div>
</template>
