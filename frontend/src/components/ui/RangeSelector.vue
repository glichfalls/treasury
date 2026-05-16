<script setup lang="ts">
import { RANGES, type Range } from '@/lib/charts'

defineProps<{ modelValue: Range }>()
defineEmits<{ 'update:modelValue': [Range] }>()
</script>

<template>
  <div class="flex gap-1">
    <button
      v-for="r in RANGES"
      :key="r"
      type="button"
      :class="['text-xs px-2 py-0.5 rounded transition-colors',
        r === modelValue
          ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
          : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]']"
      @click="$emit('update:modelValue', r)"
    >{{ r.toUpperCase() }}</button>
  </div>
</template>
