<script setup lang="ts">
import { ref } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { useToastsStore } from '@/stores/toasts'
import { Download, Upload } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

const accounts = useAccountsStore()
const toasts = useToastsStore()
const mode = ref<'skip' | 'replace'>('skip')
const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

async function pickAndImport(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (mode.value === 'replace' && !confirm('Overwrite existing accounts with matching IDs? This deletes their transactions first.')) {
    if (fileInput.value) fileInput.value.value = ''
    return
  }

  importing.value = true
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
    await accounts.fetchAll()

    const summary = `Imported ${body.imported} · skipped ${body.skipped}`
    if (body.errors.length > 0) {
      toasts.error(`${summary} · ${body.errors.length} errors: ${body.errors[0]}`)
    } else {
      toasts.success(summary)
    }
  } catch (e) {
    toasts.error(e instanceof Error ? e.message : String(e))
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
      <Button variant="primary" href="/api/accounts/export" download>
        <Download :size="16" />
        <span>Export all</span>
      </Button>

      <Button variant="ghost" as="label">
        <Upload :size="16" />
        <span>Import backup</span>
        <input
          ref="fileInput"
          type="file"
          accept="application/json,.json"
          hidden
          @change="pickAndImport"
        />
      </Button>

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
  </div>
</template>
