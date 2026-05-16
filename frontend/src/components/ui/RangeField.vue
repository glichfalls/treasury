<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    label: string
    modelValue: number
    min: number
    max: number
    step?: number
    /** Custom value display. Default: `${value}${suffix}`. */
    format?: (v: number) => string
    suffix?: string
    /** Small caption below the slider. */
    hint?: string
  }>(),
  { step: 1, suffix: '' },
)

const emit = defineEmits<{ 'update:modelValue': [number] }>()

function display(v: number): string {
  if (props.format) return props.format(v)
  return `${v}${props.suffix}`
}
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex items-baseline justify-between gap-2">
      <label class="label">{{ label }}</label>
      <span class="text-xs tabular text-[var(--color-text-muted)]">{{ display(modelValue) }}</span>
    </div>
    <input
      type="range"
      :min="min"
      :max="max"
      :step="step"
      :value="modelValue"
      class="w-full accent-[var(--color-accent)]"
      @input="emit('update:modelValue', Number(($event.target as HTMLInputElement).value))"
    />
    <p v-if="hint" class="text-[10px] text-[var(--color-text-dim)]">{{ hint }}</p>
  </div>
</template>
