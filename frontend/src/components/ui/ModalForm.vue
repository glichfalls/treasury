<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import Button from '@/components/ui/Button.vue'

const props = withDefaults(
  defineProps<{
    title: string
    submitLabel?: string
    submittingLabel?: string
    cancelLabel?: string
    /** Optional v-model for external open state. Omit to let the component manage it internally. */
    open?: boolean
    submitting?: boolean
    error?: string | null
    size?: 'sm' | 'md' | 'lg' | 'xl'
  }>(),
  {
    submitLabel: 'Save',
    submittingLabel: 'Saving…',
    cancelLabel: 'Cancel',
    submitting: false,
    size: 'md',
  },
)

const emit = defineEmits<{
  'update:open': [boolean]
  submit: []
  /** Called whenever the modal closes (cancel, backdrop, escape, or after successful submit if the caller closes it). */
  close: []
}>()

const internalOpen = ref(false)
const isOpen = computed<boolean>({
  get: () => (props.open !== undefined ? props.open : internalOpen.value),
  set: (v) => {
    if (props.open !== undefined) emit('update:open', v)
    else internalOpen.value = v
  },
})

function openModal() {
  isOpen.value = true
}

function closeModal() {
  isOpen.value = false
}

function onClose() {
  emit('close')
}

// Random id so multiple forms on the same page don't collide on `form=` attr.
const formId = `modal-form-${Math.random().toString(36).slice(2, 9)}`
</script>

<template>
  <slot name="trigger" :open="openModal" />

  <BaseModal v-model:open="isOpen" :title="title" :size="size" @close="onClose">
    <form :id="formId" class="space-y-4" @submit.prevent="emit('submit')">
      <slot />
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </form>

    <template #footer>
      <Button variant="ghost" @click="closeModal">{{ cancelLabel }}</Button>
      <Button
        type="submit"
        variant="primary"
        :form="formId"
        :loading="submitting"
        :loading-text="submittingLabel"
      >{{ submitLabel }}</Button>
    </template>
  </BaseModal>
</template>
