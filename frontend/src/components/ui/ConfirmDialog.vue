<script setup lang="ts">
import BaseModal from '@/components/ui/BaseModal.vue'
import Button from '@/components/ui/Button.vue'

withDefaults(
  defineProps<{
    open: boolean
    title?: string
    message: string
    confirmLabel?: string
    cancelLabel?: string
    destructive?: boolean
  }>(),
  { title: 'Confirm', confirmLabel: 'Confirm', cancelLabel: 'Cancel', destructive: false },
)

const emit = defineEmits<{
  confirm: []
  cancel: []
  'update:open': [boolean]
}>()

function onConfirm() {
  emit('confirm')
  emit('update:open', false)
}
function onCancel() {
  emit('cancel')
  emit('update:open', false)
}
</script>

<template>
  <BaseModal :open="open" size="sm" :title="title" @close="onCancel">
    <p class="text-sm whitespace-pre-line">{{ message }}</p>
    <template #footer>
      <Button variant="ghost" size="sm" @click="onCancel">{{ cancelLabel }}</Button>
      <Button :variant="destructive ? 'danger' : 'primary'" size="sm" @click="onConfirm">
        {{ confirmLabel }}
      </Button>
    </template>
  </BaseModal>
</template>
