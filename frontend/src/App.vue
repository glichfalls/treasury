<script setup lang="ts">
import { RouterView } from 'vue-router'
import AppShell from '@/components/layout/AppShell.vue'
import ToastContainer from '@/components/ui/ToastContainer.vue'
</script>

<template>
  <AppShell>
    <RouterView />
  </AppShell>
  <ToastContainer />
</template>
