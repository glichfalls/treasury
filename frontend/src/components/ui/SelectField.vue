<script setup lang="ts" generic="T extends string | number">
import { computed, ref } from 'vue'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
  ComboboxButton,
} from '@headlessui/vue'
import { ChevronDown, Check } from 'lucide-vue-next'

interface Option { value: T; label: string; hint?: string }

const props = withDefaults(
  defineProps<{
    label?: string
    modelValue: T | null
    options: readonly Option[]
    placeholder?: string
    /** Allow the empty/null selection. */
    allowEmpty?: boolean
    emptyLabel?: string
    hint?: string
    error?: string | null
    disabled?: boolean
  }>(),
  {
    allowEmpty: false,
    emptyLabel: 'Any',
    placeholder: 'Select…',
  },
)

const emit = defineEmits<{ 'update:modelValue': [T | null] }>()

const query = ref('')

// Synthesise an "any/empty" entry when allowed so the user can clear from
// inside the popover without needing a separate button.
const allOptions = computed<Option[]>(() => {
  if (!props.allowEmpty) return [...props.options]
  return [{ value: null as unknown as T, label: props.emptyLabel }, ...props.options]
})

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (q === '') return allOptions.value
  return allOptions.value.filter((o) => {
    const haystack = `${o.label} ${o.hint ?? ''}`.toLowerCase()
    return haystack.includes(q)
  })
})

const selectedLabel = computed(() => {
  const match = props.options.find((o) => o.value === props.modelValue)
  if (match) return match.label
  if (props.modelValue === null && props.allowEmpty) return props.emptyLabel
  return ''
})

function onChange(value: T | null) {
  emit('update:modelValue', value)
  query.value = ''
}
</script>

<template>
  <div class="space-y-1.5">
    <label v-if="label" class="label">{{ label }}</label>
    <Combobox
      :model-value="modelValue"
      :disabled="disabled"
      nullable
      as="div"
      class="relative"
      @update:model-value="onChange"
    >
      <div class="relative">
        <ComboboxInput
          class="input pr-8"
          :placeholder="placeholder"
          :display-value="() => selectedLabel"
          @change="query = ($event.target as HTMLInputElement).value"
        />
        <ComboboxButton
          class="absolute inset-y-0 right-0 flex items-center px-2 text-[var(--color-text-muted)]"
        >
          <ChevronDown :size="16" />
        </ComboboxButton>
      </div>
      <ComboboxOptions
        class="absolute z-30 mt-1 w-full max-h-60 overflow-auto rounded-md py-1 text-sm shadow-lg focus:outline-none"
        style="background-color: var(--color-surface); border: 1px solid var(--color-border);"
      >
        <div
          v-if="filtered.length === 0"
          class="px-3 py-2 text-[var(--color-text-muted)]"
        >No matches.</div>
        <ComboboxOption
          v-for="opt in filtered"
          :key="String(opt.value)"
          v-slot="{ active, selected }"
          :value="opt.value"
          as="template"
        >
          <li
            class="flex items-center justify-between gap-2 px-3 py-1.5 cursor-pointer select-none"
            :class="active ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]' : 'text-[var(--color-text)]'"
          >
            <span class="truncate">
              {{ opt.label }}
              <span
                v-if="opt.hint"
                class="ml-1 text-xs text-[var(--color-text-dim)]"
              >{{ opt.hint }}</span>
            </span>
            <Check
              v-if="selected"
              :size="14"
              class="text-[var(--color-accent)] shrink-0"
            />
          </li>
        </ComboboxOption>
      </ComboboxOptions>
    </Combobox>
    <p v-if="hint" class="text-xs text-[var(--color-text-dim)]">{{ hint }}</p>
    <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
  </div>
</template>
