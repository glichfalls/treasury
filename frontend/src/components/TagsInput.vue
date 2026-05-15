<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { X } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: string[]
    placeholder?: string
  }>(),
  { placeholder: 'Add a tag…' },
)
const emit = defineEmits<{ 'update:modelValue': [string[]] }>()

interface TagSuggestion { tag: string; count: number }
const allTags = ref<TagSuggestion[]>([])

onMounted(async () => {
  try {
    allTags.value = await api.get<TagSuggestion[]>('/api/tags')
  } catch {
    // Non-fatal: autocomplete just shows nothing.
  }
})

const inputValue = ref('')
const focused = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

function normalize(t: string): string {
  return t.trim().toLowerCase()
}

const currentMatchesExisting = computed(() => {
  const q = normalize(inputValue.value)
  return q !== '' && allTags.value.some((s) => s.tag === q)
})

// Suggestions: tags the user has used elsewhere matching the current input,
// minus tags already on this transaction.
const suggestions = computed<TagSuggestion[]>(() => {
  const q = normalize(inputValue.value)
  const pool = allTags.value.filter((s) => !props.modelValue.includes(s.tag))
  if (q === '') return pool.slice(0, 8)
  return pool.filter((s) => s.tag.includes(q)).slice(0, 8)
})

function commit(raw: string) {
  const t = normalize(raw)
  inputValue.value = ''
  if (t === '' || props.modelValue.includes(t)) return
  emit('update:modelValue', [...props.modelValue, t])
}

function removeTag(t: string) {
  emit('update:modelValue', props.modelValue.filter((x) => x !== t))
}

function onKeydown(ev: KeyboardEvent) {
  if (ev.key === 'Enter' || ev.key === ',') {
    ev.preventDefault()
    commit(inputValue.value)
  } else if (ev.key === 'Backspace' && inputValue.value === '' && props.modelValue.length > 0) {
    // Pop the last chip when backspacing on empty input — standard chip-input pattern.
    removeTag(props.modelValue[props.modelValue.length - 1]!)
  } else if (ev.key === 'Escape') {
    inputValue.value = ''
    focused.value = false
    inputRef.value?.blur()
  }
}

// SYNCHRONOUS commit on blur. The previous version used setTimeout(120ms) which
// raced with the form submit click: blur fired → setTimeout scheduled → form
// submitted with pending text NOT in the array yet → tag lost on save.
// Now blur commits the pending text immediately, so by the time the click
// handler fires the modelValue is up to date.
function onBlur() {
  if (inputValue.value.trim() !== '') commit(inputValue.value)
  focused.value = false
}

function pickSuggestion(s: TagSuggestion) {
  commit(s.tag)
  // Refocus so the user can keep adding without an extra click.
  inputRef.value?.focus()
}

function focusInput() {
  inputRef.value?.focus()
}
</script>

<template>
  <div class="relative">
    <div
      class="input flex flex-wrap items-center gap-1.5 cursor-text min-h-[2.5rem]"
      @click="focusInput"
    >
      <span
        v-for="tag in modelValue"
        :key="tag"
        class="inline-flex items-center gap-1 text-xs rounded px-2 py-0.5 select-none"
        style="background-color: color-mix(in srgb, var(--color-accent) 18%, transparent); color: var(--color-accent);"
        @click.stop
      >
        {{ tag }}
        <button
          type="button"
          class="hover:text-[var(--color-text)] transition-colors"
          :aria-label="`Remove ${tag}`"
          @click.stop="removeTag(tag)"
        >
          <X :size="12" />
        </button>
      </span>
      <input
        ref="inputRef"
        v-model="inputValue"
        type="text"
        :placeholder="modelValue.length === 0 ? placeholder : ''"
        class="flex-1 min-w-[6rem] bg-transparent outline-none text-sm border-0 p-0"
        autocomplete="off"
        @focus="focused = true"
        @blur="onBlur"
        @keydown="onKeydown"
      />
    </div>

    <!-- Suggestion dropdown -->
    <div
      v-if="focused && (suggestions.length > 0 || (inputValue.trim() !== '' && !currentMatchesExisting))"
      class="absolute z-20 mt-1 w-full card shadow-lg overflow-hidden max-h-56 overflow-y-auto"
    >
      <!-- "Create new" hint when the typed value isn't a known tag -->
      <button
        v-if="inputValue.trim() !== '' && !currentMatchesExisting"
        type="button"
        class="w-full text-left px-3 py-1.5 flex items-center justify-between text-sm hover:bg-[var(--color-surface-hover)] transition-colors"
        @mousedown.prevent="commit(inputValue)"
      >
        <span>
          Create <span class="font-medium text-[var(--color-accent)]">{{ normalize(inputValue) }}</span>
        </span>
        <span class="text-[10px] text-[var(--color-text-dim)]">Enter</span>
      </button>

      <button
        v-for="s in suggestions"
        :key="s.tag"
        type="button"
        class="w-full text-left px-3 py-1.5 flex items-center justify-between text-sm hover:bg-[var(--color-surface-hover)] transition-colors"
        @mousedown.prevent="pickSuggestion(s)"
      >
        <span>{{ s.tag }}</span>
        <span class="text-xs text-[var(--color-text-dim)]">{{ s.count }}</span>
      </button>
    </div>
  </div>
</template>
