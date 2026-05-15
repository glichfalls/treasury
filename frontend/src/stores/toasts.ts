import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  kind: ToastKind
  message: string
  // Auto-dismiss timeout id so we can clear it if the user dismisses manually.
  timer: ReturnType<typeof setTimeout> | null
}

let nextId = 1

export const useToastsStore = defineStore('toasts', () => {
  const toasts = ref<Toast[]>([])

  function push(kind: ToastKind, message: string, durationMs = 4000): number {
    const id = nextId++
    const timer = durationMs > 0 ? setTimeout(() => dismiss(id), durationMs) : null
    toasts.value = [...toasts.value, { id, kind, message, timer }]
    return id
  }

  function dismiss(id: number) {
    const t = toasts.value.find((x) => x.id === id)
    if (t?.timer) clearTimeout(t.timer)
    toasts.value = toasts.value.filter((x) => x.id !== id)
  }

  // Convenience wrappers. Errors stick around longer so the user can read them.
  const success = (msg: string) => push('success', msg, 3500)
  const error = (msg: string) => push('error', msg, 6000)
  const info = (msg: string) => push('info', msg, 3500)

  return { toasts, push, dismiss, success, error, info }
})
