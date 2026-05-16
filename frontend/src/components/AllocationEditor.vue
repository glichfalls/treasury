<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { Pencil, Plus, Save, Trash2, History, X, Check, Copy } from 'lucide-vue-next'
import { chartColors } from '@/lib/charts'
import { useAccountsStore } from '@/stores/accounts'
import DateField from '@/components/ui/DateField.vue'
import SelectField from '@/components/ui/SelectField.vue'

interface Rule {
  assetIsin: string
  percent: number
  ticker: string | null
  name: string | null
}
interface Version {
  effectiveFrom: string
  rules: Rule[]
}
interface CurrentResponse { effectiveFrom: string | null; rules: Rule[] }
interface CatalogEntry { isin: string; ticker: string | null; name: string | null; currency: string | null }

const props = defineProps<{ accountId: string }>()
const emit = defineEmits<{ saved: [] }>()

const accountsStore = useAccountsStore()

const current = ref<CurrentResponse>({ effectiveFrom: null, rules: [] })
const history = ref<Version[]>([])
const catalog = ref<CatalogEntry[]>([])
const loading = ref(false)
const showHistory = ref(false)

const editing = ref(false)
const draftRules = ref<Rule[]>([])
const draftEffectiveFrom = ref('')
const saving = ref(false)
const error = ref<string | null>(null)
const copySourceId = ref<string | null>(null)

const otherPillar3aAccounts = computed(() =>
  accountsStore.accounts.filter((a) => a.type === 'pillar_3a' && a.id !== props.accountId),
)

const today = () => new Date().toISOString().slice(0, 10)

async function load() {
  loading.value = true
  try {
    current.value = await api.get<CurrentResponse>(`/api/accounts/${props.accountId}/strategy`)
  } finally {
    loading.value = false
  }
}

async function loadHistory() {
  history.value = await api.get<Version[]>(`/api/accounts/${props.accountId}/strategy/history`)
}

async function loadCatalog() {
  if (catalog.value.length > 0) return
  catalog.value = await api.get<CatalogEntry[]>('/api/accounts/assets/catalog')
}

onMounted(load)

const totalDraft = computed(() =>
  draftRules.value.reduce((s, r) => s + (Number(r.percent) || 0), 0),
)
const draftValid = computed(() => totalDraft.value <= 100)

const paletteFor = (i: number) =>
  chartColors.palette[i % chartColors.palette.length]

// Used by the editable rows so the colored bar height/intensity tracks the percent.
function rowDisplayLabel(r: Rule): { primary: string; secondary: string | null } {
  // Resolve via catalog if the rule doesn't yet have ticker/name (e.g. just typed ISIN).
  const meta = catalog.value.find((c) => c.isin === r.assetIsin.toUpperCase())
  const ticker = r.ticker ?? meta?.ticker ?? null
  const name = r.name ?? meta?.name ?? null
  if (ticker && name) return { primary: ticker, secondary: name }
  if (ticker) return { primary: ticker, secondary: r.assetIsin || null }
  if (name) return { primary: name, secondary: r.assetIsin || null }
  return { primary: r.assetIsin || '(unknown ISIN)', secondary: null }
}

async function beginEdit() {
  draftRules.value = current.value.rules.map((r) => ({ ...r }))
  if (draftRules.value.length === 0) {
    draftRules.value.push({ assetIsin: '', percent: 0, ticker: null, name: null })
  }
  draftEffectiveFrom.value = today()
  error.value = null
  editing.value = true
  await loadCatalog()
}

function cancelEdit() {
  editing.value = false
  draftRules.value = []
  error.value = null
}

function addRow() {
  draftRules.value.push({ assetIsin: '', percent: 0, ticker: null, name: null })
}

function removeRow(i: number) {
  draftRules.value.splice(i, 1)
}

async function save() {
  if (!draftValid.value) return
  error.value = null
  saving.value = true
  try {
    const payload = {
      effectiveFrom: draftEffectiveFrom.value || today(),
      allocations: draftRules.value
        .filter((r) => r.assetIsin.trim() !== '' && Number(r.percent) > 0)
        .map((r) => ({
          assetIsin: r.assetIsin.trim().toUpperCase(),
          percent: Number(r.percent),
        })),
    }
    const res = await fetch(`/api/accounts/${props.accountId}/strategy`, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Save failed (${res.status})`)
    }
    editing.value = false
    await load()
    if (showHistory.value) await loadHistory()
    catalog.value = [] // force refresh next edit (in case new assets were auto-created)
    emit('saved')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function toggleHistory() {
  showHistory.value = !showHistory.value
  if (showHistory.value && history.value.length === 0) {
    await loadHistory()
  }
}

function formatPercent(n: number): string {
  return n.toFixed(n % 1 === 0 ? 0 : 2) + ' %'
}

function ruleLabel(r: Rule): string {
  return r.ticker ?? r.name ?? r.assetIsin
}

function ruleSubLabel(r: Rule): string | null {
  if (r.ticker && r.name) return r.name
  if (r.ticker || r.name) return r.assetIsin
  return null
}

// When the user types/selects in the asset input, look up ticker/name from the catalog
// so the inline label updates without a round trip. Free-text ISINs we don't know yet
// fall through and get resolved server-side on save.
function onAssetInput(rule: Rule, raw: string) {
  rule.assetIsin = raw
  const match = catalog.value.find((c) =>
    c.isin === raw.toUpperCase()
    || (c.ticker !== null && c.ticker.toUpperCase() === raw.toUpperCase()),
  )
  if (match !== undefined) {
    rule.assetIsin = match.isin
    rule.ticker = match.ticker
    rule.name = match.name
  } else {
    rule.ticker = null
    rule.name = null
  }
}

async function copyFrom(sourceId: string) {
  if (!sourceId) return
  if (draftRules.value.some((r) => r.assetIsin.trim() !== '' || r.percent > 0)) {
    if (!confirm('Replace the current draft with the copied strategy?')) {
      copySourceId.value = null
      return
    }
  }
  try {
    const src = await api.get<CurrentResponse>(`/api/accounts/${sourceId}/strategy`)
    draftRules.value = src.rules.length > 0
      ? src.rules.map((r) => ({ ...r }))
      : [{ assetIsin: '', percent: 0, ticker: null, name: null }]
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    copySourceId.value = null
  }
}
</script>

<template>
  <div class="card p-5 space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h3 class="font-medium">Strategy</h3>
        <p v-if="current.effectiveFrom && !editing" class="text-xs text-[var(--color-text-dim)] mt-0.5">
          In effect since {{ current.effectiveFrom }}
        </p>
        <p v-else-if="!loading && !editing && current.rules.length === 0" class="text-xs text-[var(--color-text-dim)] mt-0.5">
          No strategy configured yet.
        </p>
        <p v-else-if="editing" class="text-xs text-[var(--color-text-dim)] mt-0.5">
          Editing — saving creates a new version.
        </p>
      </div>
      <div v-if="!editing" class="flex items-center gap-1">
        <button class="btn btn-ghost text-xs" type="button" @click="toggleHistory">
          <History :size="14" />
          <span>{{ showHistory ? 'Hide history' : 'History' }}</span>
        </button>
        <button class="btn btn-secondary text-xs" type="button" @click="beginEdit">
          <Pencil :size="14" />
          <span>Edit</span>
        </button>
      </div>
    </div>

    <!-- Read-only display -->
    <div v-if="!editing">
      <p v-if="loading" class="text-sm text-[var(--color-text-muted)]">Loading…</p>

      <div v-else-if="current.rules.length === 0" class="text-sm text-[var(--color-text-muted)]">
        Click <strong>Edit</strong> to define how new contributions should be split.
      </div>

      <ul v-else class="space-y-2.5">
        <li v-for="(r, i) in current.rules" :key="r.assetIsin" class="space-y-1">
          <div class="flex items-baseline justify-between gap-2 text-sm">
            <div class="min-w-0">
              <span class="font-medium">{{ ruleLabel(r) }}</span>
              <span v-if="ruleSubLabel(r)" class="text-[var(--color-text-dim)] text-xs ml-2">
                {{ ruleSubLabel(r) }}
              </span>
            </div>
            <span class="tabular text-[var(--color-text)] font-medium shrink-0">{{ formatPercent(r.percent) }}</span>
          </div>
          <div class="h-1.5 rounded-full overflow-hidden" :style="{ backgroundColor: 'var(--color-bg)' }">
            <div
              class="h-full rounded-full"
              :style="{ width: r.percent + '%', backgroundColor: paletteFor(i) }"
            />
          </div>
        </li>
      </ul>
    </div>

    <!-- Edit mode -->
    <div v-else class="space-y-4">
      <datalist id="assetCatalogOptions">
        <option v-for="c in catalog" :key="c.isin" :value="c.isin">
          {{ c.ticker ?? c.isin }}{{ c.name ? ' — ' + c.name : '' }}
        </option>
      </datalist>

      <div
        v-if="otherPillar3aAccounts.length > 0"
        class="flex items-center gap-2 text-xs"
      >
        <Copy :size="14" class="text-[var(--color-text-dim)]" />
        <span class="text-[var(--color-text-muted)]">Copy strategy from</span>
        <SelectField
          v-model="copySourceId"
          :options="otherPillar3aAccounts.map((a) => ({ value: a.id, label: a.name }))"
          placeholder="Pick an account…"
          size="sm"
          :full-width="false"
          @update:model-value="(v) => v && copyFrom(v)"
        />
      </div>

      <ul class="space-y-2.5">
        <li
          v-for="(r, i) in draftRules"
          :key="i"
          class="p-3.5 rounded-md space-y-2"
          :style="{ backgroundColor: 'var(--color-bg)', border: '1px solid var(--color-border)' }"
        >
          <!-- Row 1: asset input | percent | delete -->
          <div class="grid grid-cols-[1fr,6rem,2rem] gap-3 items-center">
            <input
              :value="r.assetIsin"
              list="assetCatalogOptions"
              placeholder="Type ticker (e.g. VTI) or paste an ISIN"
              class="input"
              @input="onAssetInput(r, ($event.target as HTMLInputElement).value)"
            />
            <div class="relative">
              <input
                v-model.number="r.percent"
                type="number"
                min="0"
                max="100"
                step="0.01"
                class="input tabular pr-7 text-right"
                placeholder="0"
              />
              <span class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-[var(--color-text-dim)]">%</span>
            </div>
            <button
              class="p-1.5 rounded transition-colors text-[var(--color-text-dim)] hover:text-[var(--color-negative)] hover:bg-[var(--color-surface-hover)]"
              type="button"
              :aria-label="`Remove row ${i + 1}`"
              @click="removeRow(i)"
            >
              <Trash2 :size="14" />
            </button>
          </div>

          <!-- Row 2: resolved name (left) + colored weight bar (right) -->
          <div class="grid grid-cols-[1fr,8rem] gap-3 items-center">
            <p class="text-xs text-[var(--color-text-muted)] truncate min-h-[1rem]">
              <template v-if="r.ticker || r.name">
                <span class="font-medium text-[var(--color-text)]">{{ r.ticker ?? '—' }}</span>
                <span v-if="r.name" class="text-[var(--color-text-muted)] ml-2">{{ r.name }}</span>
                <span v-if="r.ticker && r.assetIsin" class="text-[var(--color-text-dim)] ml-2">{{ r.assetIsin }}</span>
              </template>
              <template v-else-if="r.assetIsin">
                <span class="text-[var(--color-text-dim)] italic">Unknown — will resolve on save</span>
              </template>
              <template v-else>
                <span class="text-[var(--color-text-dim)] italic">No asset selected</span>
              </template>
            </p>
            <div class="h-2 rounded-full overflow-hidden" :style="{ backgroundColor: 'var(--color-surface-hover)' }">
              <div
                class="h-full rounded-full transition-all"
                :style="{
                  width: Math.min(100, Math.max(0, Number(r.percent) || 0)) + '%',
                  backgroundColor: paletteFor(i),
                }"
              />
            </div>
          </div>
        </li>
      </ul>

      <button class="btn btn-ghost text-xs" type="button" @click="addRow">
        <Plus :size="14" />
        <span>Add asset</span>
      </button>

      <!-- Footer: total + effective from + actions -->
      <div class="pt-3 border-t border-[var(--color-border)] flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-[10rem] space-y-1">
          <div class="flex items-baseline justify-between gap-2">
            <span class="label">Total</span>
            <span
              class="tabular text-sm font-medium"
              :class="draftValid ? 'text-[var(--color-text)]' : 'text-[var(--color-negative)]'"
            >
              {{ totalDraft.toFixed(2) }} %
            </span>
          </div>
          <div class="h-1 rounded-full overflow-hidden" :style="{ backgroundColor: 'var(--color-bg)' }">
            <div
              class="h-full rounded-full transition-all"
              :style="{
                width: Math.min(100, totalDraft) + '%',
                backgroundColor: draftValid ? 'var(--color-accent)' : 'var(--color-negative)',
              }"
            />
          </div>
          <p class="text-xs text-[var(--color-text-dim)]">
            <span v-if="!draftValid">Must be ≤ 100 %.</span>
            <span v-else-if="totalDraft < 100">Remainder stays as cash.</span>
            <span v-else>Fully allocated.</span>
          </p>
        </div>

        <div class="space-y-1">
          <label class="label">Effective from</label>
          <DateField v-model="draftEffectiveFrom" />
        </div>

        <div class="flex items-end gap-2 ml-auto">
          <button class="btn btn-ghost" type="button" @click="cancelEdit">
            <X :size="14" />
            <span>Cancel</span>
          </button>
          <button class="btn btn-primary" type="button" :disabled="saving || !draftValid" @click="save">
            <Save :size="14" />
            <span>{{ saving ? 'Saving…' : 'Save strategy' }}</span>
          </button>
        </div>
      </div>
      <p v-if="error" class="text-sm text-[var(--color-negative)]">{{ error }}</p>
    </div>

    <!-- History accordion -->
    <div v-if="showHistory && !editing" class="space-y-3 pt-3 border-t border-[var(--color-border)]">
      <h4 class="text-xs uppercase tracking-wide text-[var(--color-text-dim)]">Past versions</h4>
      <div v-if="history.length <= 1" class="text-sm text-[var(--color-text-muted)]">
        No earlier versions.
      </div>
      <div v-for="(v, vi) in history.slice(1)" :key="v.effectiveFrom" class="space-y-2">
        <div class="flex items-baseline gap-2 text-xs">
          <Check :size="12" class="text-[var(--color-text-dim)]" />
          <span class="text-[var(--color-text-muted)]">From {{ v.effectiveFrom }}</span>
          <span v-if="vi === 0" class="badge text-[10px]">previous</span>
        </div>
        <ul class="space-y-1 pl-5">
          <li v-for="(r, i) in v.rules" :key="r.assetIsin" class="flex items-baseline justify-between text-xs gap-2">
            <span class="text-[var(--color-text-muted)] min-w-0 truncate">
              {{ ruleLabel(r) }}
              <span v-if="ruleSubLabel(r)" class="text-[var(--color-text-dim)] ml-2">{{ ruleSubLabel(r) }}</span>
            </span>
            <div class="flex items-center gap-2 tabular text-[var(--color-text-muted)] shrink-0">
              <div class="h-1 w-16 rounded-full overflow-hidden" :style="{ backgroundColor: 'var(--color-bg)' }">
                <div class="h-full rounded-full" :style="{ width: r.percent + '%', backgroundColor: paletteFor(i) }" />
              </div>
              <span class="w-12 text-right">{{ formatPercent(r.percent) }}</span>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
