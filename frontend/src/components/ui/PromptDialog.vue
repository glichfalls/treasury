<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import Button from '@/components/ui/Button.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    label?: string
    initialValue?: string
    placeholder?: string
    confirmLabel?: string
    /** Block submit when empty. Default true. */
    required?: boolean
  }>(),
  { title: 'Enter value', confirmLabel: 'OK', required: true, initialValue: '' },
)

const emit = defineEmits<{
  submit: [string]
  cancel: []
  'update:open': [boolean]
}>()

const value = ref('')
const input = ref<HTMLInputElement | null>(null)

// Re-seed value + auto-focus the input whenever the modal opens.
watch(
  () => props.open,
  async (o) => {
    if (!o) return
    value.value = props.initialValue ?? ''
    await nextTick()
    input.value?.focus()
    input.value?.select()
  },
)

function canSubmit(): boolean {
  if (!props.required) return true
  return value.value.trim() !== ''
}

function onSubmit() {
  if (!canSubmit()) return
  emit('submit', value.value.trim())
  emit('update:open', false)
}
function onCancel() {
  emit('cancel')
  emit('update:open', false)
}
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    onSubmit()
  }
}
</script>

<template>
  <BaseModal :open="open" size="sm" :title="title" @close="onCancel">
    <div class="space-y-2">
      <label v-if="label" class="label">{{ label }}</label>
      <input
        ref="input"
        v-model="value"
        type="text"
        class="input w-full"
        :placeholder="placeholder"
        @keydown="onKeydown"
      />
    </div>
    <template #footer>
      <Button variant="ghost" size="sm" @click="onCancel">Cancel</Button>
      <Button variant="primary" size="sm" :disabled="!canSubmit()" @click="onSubmit">
        {{ confirmLabel }}
      </Button>
    </template>
  </BaseModal>
</template>
