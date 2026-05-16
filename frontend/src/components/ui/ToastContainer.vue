<script setup lang="ts">
import { useToastsStore } from '@/stores/toasts'
import { CheckCircle2, AlertCircle, Info, X } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

const toasts = useToastsStore()
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 px-4 pb-4 sm:items-end sm:pb-6 sm:pr-6">
      <TransitionGroup
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-150 ease-in absolute"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0 translate-y-2"
        tag="div"
        class="contents"
      >
        <div
          v-for="t in toasts.toasts"
          :key="t.id"
          class="pointer-events-auto card flex items-start gap-3 px-3.5 py-3 min-w-[16rem] max-w-md shadow-lg"
          role="status"
          :aria-live="t.kind === 'error' ? 'assertive' : 'polite'"
        >
          <CheckCircle2
            v-if="t.kind === 'success'"
            :size="18"
            class="shrink-0 mt-0.5 text-[var(--color-positive)]"
          />
          <AlertCircle
            v-else-if="t.kind === 'error'"
            :size="18"
            class="shrink-0 mt-0.5 text-[var(--color-negative)]"
          />
          <Info
            v-else
            :size="18"
            class="shrink-0 mt-0.5 text-[var(--color-text-muted)]"
          />
          <p class="text-sm flex-1 break-words">{{ t.message }}</p>
          <Button
            variant="ghost"
            size="sm"
            icon-only
            class="shrink-0"
            aria-label="Dismiss"
            @click="toasts.dismiss(t.id)"
          >
            <X :size="14" />
          </Button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
