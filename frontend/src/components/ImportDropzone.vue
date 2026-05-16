<script setup lang="ts">
import { ref } from 'vue'
import { ApiError } from '@/lib/api'
import { useToastsStore } from '@/stores/toasts'
import { Upload, AlertCircle } from 'lucide-vue-next'
import BaseModal from '@/components/ui/BaseModal.vue'
import Button from '@/components/ui/Button.vue'

const props = defineProps<{ accountId: string }>()
const emit = defineEmits<{ imported: [] }>()

interface ImportResult {
  importer: string | null
  imported: number
  skipped: number
  errors: string[]
}

const toasts = useToastsStore()

const open = ref(false)
const dragging = ref(false)
const submitting = ref(false)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

async function upload(file: File) {
  error.value = null
  submitting.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await fetch(`/api/accounts/${props.accountId}/imports`, {
      method: 'POST',
      credentials: 'include',
      body: form,
    })
    const body = (await res.json()) as ImportResult & { error?: string }
    if (!res.ok) {
      throw new ApiError(res.status, body.error ?? res.statusText)
    }
    const summary = `Imported ${body.imported} from ${body.importer ?? 'CSV'} · skipped ${body.skipped}`
    if (body.errors.length > 0) {
      toasts.error(`${summary} · ${body.errors.length} errors: ${body.errors[0]}`)
    } else {
      toasts.success(summary)
    }
    open.value = false
    emit('imported')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    submitting.value = false
  }
}

function onDrop(ev: DragEvent) {
  ev.preventDefault()
  dragging.value = false
  const file = ev.dataTransfer?.files?.[0]
  if (file) void upload(file)
}

function onFilePicked(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (file) void upload(file)
}

function reset() {
  error.value = null
  submitting.value = false
}
</script>

<template>
  <Button @click="open = true">
    <Upload :size="16" />
    <span>Import CSV</span>
  </Button>

  <BaseModal v-model:open="open" title="Import CSV" @close="reset">
    <div
      class="rounded-md border-2 border-dashed p-8 transition-colors cursor-pointer text-center"
      :style="{ borderColor: dragging ? 'var(--color-accent)' : 'var(--color-border)' }"
      @dragenter.prevent="dragging = true"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop="onDrop"
      @click="fileInput?.click()"
    >
      <input ref="fileInput" type="file" accept=".csv,text/csv" hidden @change="onFilePicked" />
      <div class="flex justify-center mb-3 text-[var(--color-accent)]">
        <Upload :size="28" />
      </div>
      <p v-if="submitting" class="font-medium">Importing…</p>
      <template v-else>
        <p class="font-medium">Drop a CSV here or click to browse</p>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">
          Auto-detects ZKB, Degiro trades, and IBKR Statement of Funds.
        </p>
      </template>
    </div>
    <p v-if="error" class="mt-3 text-sm text-[var(--color-negative)] flex items-center gap-1.5">
      <AlertCircle :size="14" />
      {{ error }}
    </p>
  </BaseModal>
</template>
