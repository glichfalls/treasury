<script setup lang="ts">
import { computed } from 'vue'
import { formatMinor, formatMinorCompact } from '@/lib/money'
import { usePrivacyMode } from '@/composables/usePrivacyMode'

const props = withDefaults(defineProps<{
  minor: string | number
  currency: string
  /** Show compact notation (e.g. "1.2M"). Full precision is shown on hover. */
  compact?: boolean
  /**
   * Mark this value as sensitive (a current account balance).
   * Blurred when privacy mode is on. Do NOT use for projections or
   * calculated results — only for values that reveal what you own today.
   */
  sensitive?: boolean
}>(), {
  compact: false,
  sensitive: false,
})

const { hideBalances } = usePrivacyMode()

const display = computed(() => {
  const m = String(props.minor)
  return props.compact ? formatMinorCompact(m, props.currency) : formatMinor(m, props.currency)
})

// Compact values show full precision on hover, suppressed in privacy mode.
const title = computed(() => {
  if (props.sensitive && hideBalances.value) return undefined
  return props.compact ? formatMinor(String(props.minor), props.currency) : undefined
})
</script>

<template>
  <span :class="sensitive ? 'private-value' : undefined" :title="title">{{ display }}</span>
</template>
