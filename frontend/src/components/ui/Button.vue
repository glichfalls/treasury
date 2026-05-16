<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { Loader2 } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg' | 'xl'
    /** Square padding for icon-only buttons. */
    iconOnly?: boolean
    fullWidth?: boolean
    /** Shows a spinner and disables interaction. */
    loading?: boolean
    /** Text shown in place of the slot while loading (optional). */
    loadingText?: string
    disabled?: boolean
    type?: 'button' | 'submit' | 'reset'
    /** Content alignment along the main axis. Useful for full-width nav buttons. */
    align?: 'center' | 'start' | 'end'
    /** Renders as an <a href>. */
    href?: string
    /** Renders as a RouterLink. */
    to?: RouteLocationRaw
    /** Override the rendered element when href/to don't apply (e.g. 'label' for a file-input wrapper). */
    as?: 'button' | 'a' | 'label' | 'span'
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    iconOnly: false,
    fullWidth: false,
    loading: false,
    disabled: false,
    type: 'button',
    as: 'button',
    align: 'center',
  },
)

const tag = computed(() => {
  if (props.to) return RouterLink
  if (props.href) return 'a'
  return props.as
})

const isDisabled = computed(() => props.disabled || props.loading)

const spinnerSize = computed(() => ({ sm: 12, md: 14, lg: 16, xl: 18 }[props.size]))

const classes = computed(() => {
  const alignMap = { center: 'justify-center', start: 'justify-start', end: 'justify-end' } as const
  // cursor-pointer is always set; disabled:/aria-disabled: variants override to
  // cursor-not-allowed when the button isn't actionable. Tailwind variants are
  // emitted after the base utility, so they win when their condition matches.
  const base = [
    'inline-flex items-center gap-2 rounded-md font-medium cursor-pointer',
    alignMap[props.align],
    'transition-colors no-underline',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    'aria-disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:pointer-events-none',
  ]

  const sizeMap = {
    sm: props.iconOnly ? 'p-1 text-xs' : 'px-2.5 py-1 text-xs gap-1.5',
    md: props.iconOnly ? 'p-1.5 text-sm' : 'px-3.5 py-2 text-sm',
    lg: props.iconOnly ? 'p-2 text-base' : 'px-5 py-2.5 text-base',
    xl: props.iconOnly ? 'p-2.5 text-base' : 'px-6 py-3 text-base',
  } as const
  const sizeClasses = sizeMap[props.size]

  const variantMap = {
    primary:
      'bg-[var(--color-accent)] text-[var(--color-accent-text)] hover:bg-[var(--color-accent-hover)]',
    secondary:
      'bg-[var(--color-surface)] text-[var(--color-text)] border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] hover:border-[var(--color-border-strong)]',
    ghost:
      'text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]',
    danger:
      'text-[var(--color-negative)] hover:bg-[color-mix(in_srgb,var(--color-negative)_12%,transparent)]',
  } as const
  const variantClasses = variantMap[props.variant]

  const width = props.fullWidth ? 'w-full' : ''

  return [...base, sizeClasses, variantClasses, width].filter(Boolean).join(' ')
})

const elementProps = computed(() => {
  const p: Record<string, unknown> = {}
  const t = tag.value
  if (t === 'button') {
    p.type = props.type
    p.disabled = isDisabled.value
  } else if (t === RouterLink) {
    p.to = props.to
    if (isDisabled.value) {
      p['aria-disabled'] = 'true'
      p.tabindex = -1
    }
  } else if (t === 'a') {
    if (props.href && !isDisabled.value) p.href = props.href
    if (isDisabled.value) {
      p['aria-disabled'] = 'true'
      p.tabindex = -1
    }
  }
  return p
})
</script>

<template>
  <component :is="tag" v-bind="elementProps" :class="classes">
    <Loader2 v-if="loading" :size="spinnerSize" class="animate-spin" />
    <template v-if="loading && loadingText">{{ loadingText }}</template>
    <slot v-else />
  </component>
</template>
