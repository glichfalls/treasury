<script setup lang="ts" generic="T extends string | number">
import { computed, nextTick, ref, watch } from 'vue'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
  ComboboxButton,
  Listbox,
  ListboxButton,
  ListboxOptions,
  ListboxOption,
} from '@headlessui/vue'
import { ChevronDown, Check, X } from 'lucide-vue-next'

export interface SelectOption<V> {
  value: V
  label: string
  hint?: string
  disabled?: boolean
}

const props = withDefaults(
  defineProps<{
    label?: string
    modelValue: T | null
    options: readonly SelectOption<T>[]
    placeholder?: string
    hint?: string
    error?: string | null
    disabled?: boolean

    /** Show a typeahead search input inside the dropdown. */
    searchable?: boolean
    /** Placeholder for the in-dropdown search input. */
    searchPlaceholder?: string

    /** Show an inline clear (X) button when a value is selected. */
    clearable?: boolean

    /** Adds an "Any/empty" entry at the top of the list so users can pick null. */
    allowEmpty?: boolean
    emptyLabel?: string

    /** Visual density. */
    size?: 'sm' | 'md'

    /** Stretch the trigger to fill its container (default true). Disable for inline use. */
    fullWidth?: boolean
  }>(),
  {
    placeholder: 'Select…',
    searchable: false,
    searchPlaceholder: 'Search…',
    clearable: false,
    allowEmpty: false,
    emptyLabel: 'Any',
    size: 'md',
    fullWidth: true,
  },
)

const emit = defineEmits<{ 'update:modelValue': [T | null] }>()

defineSlots<{
  /** Custom rendering of an option in the dropdown. */
  option?: (props: { option: SelectOption<T>; selected: boolean; active: boolean }) => unknown
  /** Custom rendering of the selected value in the trigger. */
  selected?: (props: { option: SelectOption<T> | null }) => unknown
}>()

const query = ref('')
const searchInput = ref<InstanceType<typeof ComboboxInput> | null>(null)
const triggerRef = ref<{ $el?: HTMLElement } | HTMLElement | null>(null)
const openUp = ref(false)

// Roughly: max-h-60 panel (240px) + search bar (~40px when searchable) + padding.
// We bias toward opening downward; only flip when below is genuinely too tight.
const REQUIRED_BELOW_PX = 280

/** Measure available space below the trigger and flip the panel if needed.
 * Fired on pointerdown/keydown — before the panel is rendered — so the
 * panel paints in the right direction on its first frame. */
function updateOpenDirection() {
  const raw = triggerRef.value
  const el = (raw as { $el?: HTMLElement } | null)?.$el ?? (raw as HTMLElement | null)
  if (!el?.getBoundingClientRect) return
  const rect = el.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom
  const spaceAbove = rect.top
  openUp.value = spaceBelow < REQUIRED_BELOW_PX && spaceAbove > spaceBelow
}

const selectedOption = computed<SelectOption<T> | null>(
  () => props.options.find((o) => o.value === props.modelValue) ?? null,
)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) => {
    return `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q)
  })
})

const hasValue = computed(() => props.modelValue !== null && props.modelValue !== undefined)

function onChange(value: T | null) {
  emit('update:modelValue', value)
  query.value = ''
}

function clear(event: Event) {
  event.stopPropagation()
  event.preventDefault()
  onChange(null)
}

// In searchable mode, focus the in-dropdown search input when the panel opens.
function focusSearch() {
  nextTick(() => {
    const el = (searchInput.value as unknown as { el?: HTMLInputElement })?.el
      ?? (searchInput.value as unknown as HTMLInputElement | null)
    el?.focus?.()
  })
}

// Reset the query whenever the selection changes from the outside.
watch(() => props.modelValue, () => { query.value = '' })

const triggerClass = computed(() => {
  const base = [
    'group relative flex items-center gap-2 rounded-md text-left transition-colors',
    'border bg-[var(--color-bg)] text-[var(--color-text)]',
    'hover:border-[var(--color-border-strong)] focus:outline-none focus-visible:border-[var(--color-accent)]',
    'disabled:opacity-50 disabled:cursor-not-allowed',
  ]
  const size = props.size === 'sm'
    ? 'px-2 py-1 text-xs'
    : 'px-3 py-2 text-sm'
  // w-full stretches; w-fit sizes to content so small selects (page-size picker)
  // don't clip their labels.
  const width = props.fullWidth ? 'w-full' : 'w-fit'
  const border = props.error ? 'border-[var(--color-negative)]' : 'border-[var(--color-border)]'
  return [...base, size, width, border].join(' ')
})

const triggerLabelClass = computed(() =>
  props.fullWidth ? 'flex-1 truncate' : 'whitespace-nowrap',
)

const panelClass = computed(() => [
  'absolute z-30 overflow-hidden rounded-md py-1 shadow-lg focus:outline-none',
  'bg-[var(--color-surface)] border border-[var(--color-border)]',
  props.size === 'sm' ? 'text-xs' : 'text-sm',
  // min-w-full ensures the panel is at least as wide as the trigger, but it can
  // grow if options are longer (e.g. an "All accounts" entry next to a 3-digit page size).
  'min-w-full w-max max-w-[min(20rem,calc(100vw-2rem))]',
  openUp.value ? 'bottom-full mb-1' : 'top-full mt-1',
].join(' '))

const optionClass = (active: boolean, optDisabled: boolean | undefined) => [
  'flex items-center justify-between gap-2 cursor-pointer select-none',
  props.size === 'sm' ? 'px-2 py-1' : 'px-3 py-1.5',
  active ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]' : 'text-[var(--color-text)]',
  optDisabled ? 'opacity-50 cursor-not-allowed' : '',
].join(' ')
</script>

<template>
  <div class="space-y-1.5" :class="fullWidth ? '' : 'inline-block'">
    <label v-if="label" class="label">{{ label }}</label>

    <!-- Non-searchable: Listbox (cleaner — no input element involved). -->
    <Listbox
      v-if="!searchable"
      :model-value="modelValue"
      :disabled="disabled"
      as="div"
      class="relative"
      :class="fullWidth ? '' : 'inline-block'"
      @update:model-value="onChange"
    >
      <ListboxButton
        ref="triggerRef"
        :class="triggerClass"
        :disabled="disabled"
        @pointerdown="updateOpenDirection"
        @keydown="updateOpenDirection"
      >
        <span :class="triggerLabelClass">
          <slot name="selected" :option="selectedOption">
            <template v-if="selectedOption">{{ selectedOption.label }}</template>
            <span v-else class="text-[var(--color-text-dim)]">{{ placeholder }}</span>
          </slot>
        </span>
        <button
          v-if="clearable && hasValue && !disabled"
          type="button"
          tabindex="-1"
          aria-label="Clear selection"
          class="-mr-1 p-0.5 rounded text-[var(--color-text-dim)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
          @click="clear"
          @pointerdown.stop.prevent
        >
          <X :size="size === 'sm' ? 12 : 14" />
        </button>
        <ChevronDown
          :size="size === 'sm' ? 12 : 16"
          class="text-[var(--color-text-muted)] shrink-0"
        />
      </ListboxButton>

      <ListboxOptions :class="panelClass">
        <div class="max-h-60 overflow-auto">
          <ListboxOption
            v-if="allowEmpty"
            v-slot="{ active, selected }"
            :value="null"
            as="template"
          >
            <li :class="optionClass(active, false)">
              <span class="truncate text-[var(--color-text-muted)]">{{ emptyLabel }}</span>
              <Check v-if="selected" :size="14" class="text-[var(--color-accent)] shrink-0" />
            </li>
          </ListboxOption>
          <ListboxOption
            v-for="opt in options"
            :key="String(opt.value)"
            v-slot="{ active, selected }"
            :value="opt.value"
            :disabled="opt.disabled"
            as="template"
          >
            <li :class="optionClass(active, opt.disabled)">
              <slot name="option" :option="opt" :selected="selected" :active="active">
                <span class="truncate">
                  {{ opt.label }}
                  <span v-if="opt.hint" class="ml-1 text-xs text-[var(--color-text-dim)]">{{ opt.hint }}</span>
                </span>
                <Check v-if="selected" :size="14" class="text-[var(--color-accent)] shrink-0" />
              </slot>
            </li>
          </ListboxOption>
        </div>
      </ListboxOptions>
    </Listbox>

    <!-- Searchable: Combobox with typeahead input embedded at the top of the panel. -->
    <Combobox
      v-else
      :model-value="modelValue"
      :disabled="disabled"
      nullable
      as="div"
      class="relative"
      :class="fullWidth ? '' : 'inline-block'"
      @update:model-value="onChange"
    >
      <ComboboxButton
        ref="triggerRef"
        :class="triggerClass"
        :disabled="disabled"
        @click="focusSearch"
        @pointerdown="updateOpenDirection"
        @keydown="updateOpenDirection"
      >
        <span :class="triggerLabelClass">
          <slot name="selected" :option="selectedOption">
            <template v-if="selectedOption">{{ selectedOption.label }}</template>
            <span v-else class="text-[var(--color-text-dim)]">{{ placeholder }}</span>
          </slot>
        </span>
        <button
          v-if="clearable && hasValue && !disabled"
          type="button"
          tabindex="-1"
          aria-label="Clear selection"
          class="-mr-1 p-0.5 rounded text-[var(--color-text-dim)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
          @click="clear"
          @pointerdown.stop.prevent
        >
          <X :size="size === 'sm' ? 12 : 14" />
        </button>
        <ChevronDown
          :size="size === 'sm' ? 12 : 16"
          class="text-[var(--color-text-muted)] shrink-0"
        />
      </ComboboxButton>

      <ComboboxOptions :class="panelClass">
        <div class="border-b border-[var(--color-border)] p-1">
          <ComboboxInput
            ref="searchInput"
            class="w-full bg-transparent px-2 py-1 text-sm text-[var(--color-text)] placeholder:text-[var(--color-text-dim)] focus:outline-none"
            :placeholder="searchPlaceholder"
            :display-value="() => query"
            autocomplete="off"
            @change="query = ($event.target as HTMLInputElement).value"
          />
        </div>

        <div class="max-h-60 overflow-auto">
          <div
            v-if="filtered.length === 0 && !allowEmpty"
            class="px-3 py-2 text-[var(--color-text-muted)]"
          >No matches.</div>
          <ComboboxOption
            v-if="allowEmpty && !query"
            v-slot="{ active, selected }"
            :value="null"
            as="template"
          >
            <li :class="optionClass(active, false)">
              <span class="truncate text-[var(--color-text-muted)]">{{ emptyLabel }}</span>
              <Check v-if="selected" :size="14" class="text-[var(--color-accent)] shrink-0" />
            </li>
          </ComboboxOption>
          <ComboboxOption
            v-for="opt in filtered"
            :key="String(opt.value)"
            v-slot="{ active, selected }"
            :value="opt.value"
            :disabled="opt.disabled"
            as="template"
          >
            <li :class="optionClass(active, opt.disabled)">
              <slot name="option" :option="opt" :selected="selected" :active="active">
                <span class="truncate">
                  {{ opt.label }}
                  <span v-if="opt.hint" class="ml-1 text-xs text-[var(--color-text-dim)]">{{ opt.hint }}</span>
                </span>
                <Check v-if="selected" :size="14" class="text-[var(--color-accent)] shrink-0" />
              </slot>
            </li>
          </ComboboxOption>
        </div>
      </ComboboxOptions>
    </Combobox>

    <p v-if="hint" class="text-xs text-[var(--color-text-dim)]">{{ hint }}</p>
    <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
  </div>
</template>
