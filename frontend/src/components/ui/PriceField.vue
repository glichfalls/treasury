<script setup lang="ts">
defineOptions({ inheritAttrs: false })

defineProps<{
  label: string
  modelValue: string | number | null | undefined
  currency: string
  hint?: string
  error?: string | null
}>()

defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <div class="space-y-1.5">
    <label class="label">{{ label }} ({{ currency }})</label>
    <input
      class="input tabular"
      type="text"
      inputmode="decimal"
      :value="modelValue ?? ''"
      v-bind="$attrs"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <p v-if="hint" class="text-xs text-[var(--color-text-dim)]">{{ hint }}</p>
    <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
  </div>
</template>
