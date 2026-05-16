<script setup lang="ts">
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | number | null | undefined
    type?: string
    hint?: string
    error?: string | null
    /** Set by Vue when `v-model.number` / `v-model.trim` is used on this component. */
    modelModifiers?: { number?: boolean; trim?: boolean }
  }>(),
  { type: 'text', modelModifiers: () => ({}) },
)

const emit = defineEmits<{ 'update:modelValue': [string | number] }>()

function onInput(e: Event) {
  const raw = (e.target as HTMLInputElement).value
  if (props.modelModifiers.number) {
    emit('update:modelValue', raw === '' ? Number.NaN : Number(raw))
  } else if (props.modelModifiers.trim) {
    emit('update:modelValue', raw.trim())
  } else {
    emit('update:modelValue', raw)
  }
}
</script>

<template>
  <div class="space-y-1.5">
    <label class="label">{{ label }}</label>
    <input
      class="input"
      :type="type"
      :value="modelValue ?? ''"
      v-bind="$attrs"
      @input="onInput"
    />
    <p v-if="hint" class="text-xs text-[var(--color-text-dim)]">{{ hint }}</p>
    <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
  </div>
</template>
