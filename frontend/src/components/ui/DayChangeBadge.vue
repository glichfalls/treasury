<script setup lang="ts">
import { computed } from 'vue'
import { TrendingUp, TrendingDown } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    /** Percent change. Null renders nothing. */
    pct: number | null
    /** "sm" for table cells, "md" for headers. */
    size?: 'sm' | 'md'
    /** Show an up/down icon next to the percent. */
    showIcon?: boolean
  }>(),
  { size: 'sm', showIcon: true },
)

const positive = computed(() => (props.pct ?? 0) >= 0)
const text = computed(() => {
  if (props.pct === null) return ''
  const sign = props.pct >= 0 ? '+' : ''
  return `${sign}${props.pct.toFixed(2)}%`
})
</script>

<template>
  <span
    v-if="pct !== null"
    class="inline-flex items-center gap-0.5 tabular font-medium"
    :class="[
      positive ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]',
      size === 'sm' ? 'text-xs' : 'text-sm',
    ]"
  >
    <component
      v-if="showIcon"
      :is="positive ? TrendingUp : TrendingDown"
      :size="size === 'sm' ? 10 : 12"
    />
    <span>{{ text }}</span>
  </span>
</template>
