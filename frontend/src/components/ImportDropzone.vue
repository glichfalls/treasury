<script setup lang="ts">
import { ref } from 'vue'
import { ApiError } from '@/lib/api'
import { Upload, CheckCircle2, AlertCircle } from 'lucide-vue-next'

const props = defineProps<{ accountId: string }>()
const emit = defineEmits<{ imported: [] }>()

interface ImportResult {
  importer: string | null
  imported: number
  skipped: number
  errors: string[]
}

const dragging = ref(false)
const submitting = ref(false)
const result = ref<ImportResult | null>(null)
const error = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

async function upload(file: File) {
  error.value = null
  result.value = null
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
    result.value = body
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
</script>

<template>
  <div
    class="card p-6 transition-colors cursor-pointer"
    :class="{ 'border-[var(--color-accent)]': dragging }"
    @dragenter.prevent="dragging = true"
    @dragover.prevent="dragging = true"
    @dragleave.prevent="dragging = false"
    @drop="onDrop"
    @click="fileInput?.click()"
  >
    <input ref="fileInput" type="file" accept=".csv,text/csv" hidden @change="onFilePicked" />

    <div class="flex items-start gap-4">
      <div
        class="flex items-center justify-center w-10 h-10 rounded-md shrink-0"
        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-accent) 15%, transparent)', color: 'var(--color-accent)' }"
      >
        <Upload :size="20" />
      </div>

      <div class="flex-1 min-w-0">
        <p v-if="submitting" class="font-medium">Importing…</p>
        <template v-else-if="result">
          <p class="font-medium flex items-center gap-1.5">
            <CheckCircle2 :size="16" class="text-[var(--color-positive)]" />
            Imported from {{ result.importer }}
          </p>
          <p class="text-sm text-[var(--color-text-muted)] mt-0.5">
            {{ result.imported }} new · {{ result.skipped }} duplicates skipped
            <span v-if="result.errors.length > 0" class="text-[var(--color-negative)]"> · {{ result.errors.length }} errors</span>
          </p>
        </template>
        <template v-else>
          <p class="font-medium">Import CSV</p>
          <p class="text-sm text-[var(--color-text-muted)] mt-0.5">
            Drag a file here or click to browse. Auto-detects Degiro trades and IBKR Statement of Funds.
          </p>
        </template>

        <p v-if="error" class="mt-2 text-sm text-[var(--color-negative)] flex items-center gap-1.5">
          <AlertCircle :size="14" />
          {{ error }}
        </p>
      </div>
    </div>
  </div>
</template>
