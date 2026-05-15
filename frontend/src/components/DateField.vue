<script setup lang="ts">
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import { Calendar } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    clearable?: boolean
    required?: boolean
    /** Min/max as YYYY-MM-DD strings */
    min?: string
    max?: string
  }>(),
  { placeholder: 'Pick a date', clearable: false, required: false },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

function onUpdate(v: string | null) {
  emit('update:modelValue', v ?? '')
}
</script>

<template>
  <VueDatePicker
    :model-value="modelValue || null"
    :placeholder="placeholder"
    :clearable="clearable"
    :required="required"
    :enable-time-picker="false"
    :auto-apply="true"
    :min-date="min"
    :max-date="max"
    model-type="yyyy-MM-dd"
    format="dd MMM yyyy"
    week-start="1"
    locale="de-CH"
    :format-locale="undefined"
    dark
    class="treasury-date-field"
    @update:model-value="onUpdate"
  >
    <template #input-icon>
      <Calendar :size="14" class="ml-2 text-[var(--color-text-dim)]" />
    </template>
  </VueDatePicker>
</template>

<style>
/* Override the picker's CSS variables to match the app's dark theme.
   Scoped to .treasury-date-field so we don't leak to anything else if the
   picker is ever used in a context that wants the default look. */
.treasury-date-field {
  --dp-background-color: var(--color-surface);
  --dp-text-color: var(--color-text);
  --dp-hover-color: var(--color-surface-hover);
  --dp-hover-text-color: var(--color-text);
  --dp-hover-icon-color: var(--color-text);
  --dp-primary-color: var(--color-accent);
  --dp-primary-disabled-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  --dp-primary-text-color: #fff;
  --dp-secondary-color: var(--color-text-muted);
  --dp-border-color: var(--color-border);
  --dp-menu-border-color: var(--color-border);
  --dp-border-color-hover: var(--color-text-dim);
  --dp-border-color-focus: var(--color-accent);
  --dp-disabled-color: var(--color-surface);
  --dp-scroll-bar-background: var(--color-bg);
  --dp-scroll-bar-color: var(--color-text-dim);
  --dp-success-color: var(--color-positive);
  --dp-success-color-disabled: color-mix(in srgb, var(--color-positive) 40%, transparent);
  --dp-icon-color: var(--color-text-muted);
  --dp-danger-color: var(--color-negative);
  --dp-marker-color: var(--color-accent);
  --dp-tooltip-color: var(--color-surface-hover);
  --dp-disabled-color-text: var(--color-text-dim);
  --dp-highlight-color: color-mix(in srgb, var(--color-accent) 15%, transparent);
  --dp-range-between-dates-background-color: color-mix(in srgb, var(--color-accent) 20%, transparent);
  --dp-range-between-dates-text-color: var(--color-text);
  --dp-input-padding: 0.5rem 0.625rem;
  --dp-input-icon-padding: 2rem;
  --dp-border-radius: 0.375rem;
  --dp-cell-border-radius: 0.25rem;
  --dp-font-family: inherit;
  --dp-font-size: 0.875rem;
}

/* Make the input look like the rest of our form inputs. */
.treasury-date-field .dp__input {
  background-color: var(--color-bg);
  border-color: var(--color-border);
  color: var(--color-text);
  font-size: 0.875rem;
  line-height: 1.25rem;
  transition: border-color 120ms ease, box-shadow 120ms ease;
}
.treasury-date-field .dp__input:hover {
  border-color: var(--color-text-dim);
}
.treasury-date-field .dp__input:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 20%, transparent);
}
</style>
