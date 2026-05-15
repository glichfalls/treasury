<script setup lang="ts">
withDefaults(defineProps<{ size?: number | string }>(), { size: 18 })
</script>

<template>
  <img
    src="/logo.png"
    :width="size"
    :height="size"
    alt="Treasury"
    style="object-fit: contain; display: inline-block;"
  />
</template>
