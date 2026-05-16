<script setup lang="ts" generic="T extends string | number">
import { computed } from 'vue'

interface Option<V> {
  value: V
  label: string
  /** Optional secondary count/badge shown after the label (e.g. tab counts). */
  count?: number | string
}

const props = withDefaults(
  defineProps<{
    modelValue: T
    options: readonly Option<T>[]
    /**
     * - `chip`: pill-style toggles for inline controls (e.g. chart mode switchers, range pickers).
     * - `tabs`: underlined tab nav with a border indicator.
     */
    variant?: 'chip' | 'tabs'
  }>(),
  { variant: 'chip' },
)

const emit = defineEmits<{ 'update:modelValue': [T] }>()

const wrapperClass = computed(() =>
  props.variant === 'tabs'
    ? 'flex items-center border-b border-[var(--color-border)]'
    : 'flex gap-1',
)

const itemClass = (active: boolean) => {
  if (props.variant === 'tabs') {
    return [
      'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors cursor-pointer',
      active
        ? 'text-[var(--color-text)] border-[var(--color-accent)]'
        : 'text-[var(--color-text-muted)] border-transparent hover:text-[var(--color-text)]',
    ].join(' ')
  }
  return [
    'text-xs px-2 py-0.5 rounded transition-colors cursor-pointer',
    active
      ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
      : 'text-[var(--color-text-muted)] hover:text-[var(--color-text)]',
  ].join(' ')
}
</script>

<template>
  <div :class="wrapperClass" role="tablist">
    <button
      v-for="opt in options"
      :key="String(opt.value)"
      type="button"
      role="tab"
      :aria-selected="opt.value === modelValue"
      :class="itemClass(opt.value === modelValue)"
      @click="emit('update:modelValue', opt.value)"
    >
      {{ opt.label }}
      <span
        v-if="opt.count !== undefined"
        class="ml-1 text-[var(--color-text-dim)]"
      >{{ opt.count }}</span>
    </button>
  </div>
</template>
