<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { Download, Upload, AlertCircle, CheckCircle2 } from 'lucide-vue-next'

const accounts = useAccountsStore()
const mode = ref<'skip' | 'replace'>('skip')
const importing = ref(false)
const importResult = ref<{ imported: number; skipped: number; errors: string[] } | null>(null)
const importError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

async function pickAndImport(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (mode.value === 'replace' && !confirm('Overwrite existing accounts with matching IDs? This deletes their transactions first.')) {
    if (fileInput.value) fileInput.value.value = ''
    return
  }

  importing.value = true
  importResult.value = null
  importError.value = null
  try {
    const text = await file.text()
    const res = await fetch(`/api/accounts/import?mode=${mode.value}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: text,
    })
    const body = await res.json()
    if (!res.ok) throw new Error(body.error ?? res.statusText)
    importResult.value = body
    await accounts.fetchAll()
  } catch (e) {
    importError.value = e instanceof Error ? e.message : String(e)
  } finally {
    importing.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}
</script>

<template>
  <div class="card p-6 space-y-4">
    <div>
      <h2 class="text-lg font-medium">Backup &amp; restore</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Export all your accounts and transactions as a JSON file, or import a previous backup.
      </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <a class="btn btn-primary" href="/api/accounts/export" download>
        <Download :size="16" />
        <span>Export all</span>
      </a>

      <label class="btn btn-ghost cursor-pointer">
        <Upload :size="16" />
        <span>Import backup</span>
        <input
          ref="fileInput"
          type="file"
          accept="application/json,.json"
          hidden
          @change="pickAndImport"
        />
      </label>

      <label class="flex items-center gap-2 text-sm text-[var(--color-text-muted)]">
        <input
          type="checkbox"
          :checked="mode === 'replace'"
          @change="mode = ($event.target as HTMLInputElement).checked ? 'replace' : 'skip'"
        />
        Overwrite existing accounts
      </label>
    </div>

    <p v-if="importing" class="text-sm text-[var(--color-text-muted)]">Importing…</p>

    <p v-if="importResult" class="text-sm flex items-center gap-1.5">
      <CheckCircle2 :size="14" class="text-[var(--color-positive)]" />
      Imported {{ importResult.imported }} · skipped {{ importResult.skipped }}<span
        v-if="importResult.errors.length > 0"
        class="text-[var(--color-negative)]"
      >
        · {{ importResult.errors.length }} errors</span>
    </p>
    <ul
      v-if="importResult && importResult.errors.length > 0"
      class="text-xs text-[var(--color-negative)] space-y-0.5 pl-5 list-disc"
    >
      <li v-for="(err, i) in importResult.errors" :key="i">{{ err }}</li>
    </ul>

    <p v-if="importError" class="text-sm text-[var(--color-negative)] flex items-center gap-1.5">
      <AlertCircle :size="14" />
      {{ importError }}
    </p>
  </div>
</template>
