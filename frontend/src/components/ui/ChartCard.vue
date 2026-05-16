<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string
    loading?: boolean
    empty?: boolean
    loadingText?: string
    emptyText?: string
    /** Tailwind/CSS height for the chart body (e.g. '18rem', '10rem'). */
    height?: string
  }>(),
  {
    loading: false,
    empty: false,
    loadingText: 'Loading…',
    emptyText: 'No data.',
    height: '18rem',
  },
)
</script>

<template>
  <div class="card p-4">
    <div v-if="title || $slots.title || $slots.actions" class="flex items-baseline justify-between mb-2 gap-2">
      <div class="flex items-baseline gap-3 min-w-0">
        <slot name="title">
          <h3 class="text-sm font-medium">{{ title }}</h3>
        </slot>
      </div>
      <div v-if="$slots.actions" class="shrink-0">
        <slot name="actions" />
      </div>
    </div>
    <div v-if="$slots.subactions" class="flex items-center gap-1 mb-2">
      <slot name="subactions" />
    </div>
    <div
      v-if="loading"
      class="flex items-center justify-center text-[var(--color-text-muted)] text-sm"
      :style="{ height }"
    >{{ loadingText }}</div>
    <div
      v-else-if="empty"
      class="flex items-center justify-center text-[var(--color-text-muted)] text-sm"
      :style="{ height }"
    >{{ emptyText }}</div>
    <slot v-else />
  </div>
</template>
