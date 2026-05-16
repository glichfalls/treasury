<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { X } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    /** Tailwind max-w class for the panel. */
    size?: 'sm' | 'md' | 'lg' | 'xl'
  }>(),
  { size: 'md' },
)
const emit = defineEmits<{ 'update:open': [boolean]; close: [] }>()

const maxWidth = computed(() => ({
  sm: 'max-w-sm',
  md: 'max-w-xl',
  lg: 'max-w-3xl',
  xl: 'max-w-5xl',
}[props.size]))

const panel = ref<HTMLDivElement | null>(null)

function close() {
  emit('update:open', false)
  emit('close')
}

function onBackdropClick(ev: MouseEvent) {
  if (ev.target === ev.currentTarget) close()
}

function onKeydown(ev: KeyboardEvent) {
  if (!props.open) return
  if (ev.key === 'Escape') {
    ev.preventDefault()
    close()
  }
}

// Lock scroll on body while open and focus the panel for keyboard users.
watch(
  () => props.open,
  (isOpen) => {
    if (typeof document === 'undefined') return
    if (isOpen) {
      document.body.style.overflow = 'hidden'
      // Wait a tick for teleport to mount, then focus.
      requestAnimationFrame(() => panel.value?.focus())
    } else {
      document.body.style.overflow = ''
    }
  },
  { immediate: true },
)

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        style="background-color: color-mix(in srgb, #000 60%, transparent);"
        role="presentation"
        @mousedown="onBackdropClick"
      >
        <Transition
          enter-active-class="transition duration-150 ease-out"
          enter-from-class="opacity-0 scale-95"
          enter-to-class="opacity-100 scale-100"
          leave-active-class="transition duration-100 ease-in"
          leave-from-class="opacity-100 scale-100"
          leave-to-class="opacity-0 scale-95"
        >
          <div
            v-if="open"
            ref="panel"
            class="card w-full outline-none"
            :class="maxWidth"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            tabindex="-1"
            @mousedown.stop
          >
            <header
              v-if="title || $slots.header"
              class="flex items-center justify-between px-5 py-4 border-b"
              style="border-color: var(--color-border);"
            >
              <slot name="header">
                <h3 class="font-medium">{{ title }}</h3>
              </slot>
              <Button variant="ghost" size="sm" icon-only aria-label="Close" @click="close">
                <X :size="16" />
              </Button>
            </header>
            <div class="px-5 py-4">
              <slot />
            </div>
            <footer
              v-if="$slots.footer"
              class="px-5 py-3 border-t flex items-center justify-end gap-2"
              style="border-color: var(--color-border);"
            >
              <slot name="footer" />
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
