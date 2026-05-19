<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { formatMinor, formatMinorCompact } from '@/lib/money'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { TrendingUp, RotateCcw, Pencil, AlertTriangle, Plus, Trash2 } from 'lucide-vue-next'
import MoneyDisplay from '@/components/ui/MoneyDisplay.vue'
import Button from '@/components/ui/Button.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import RangeField from '@/components/ui/RangeField.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import PromptDialog from '@/components/ui/PromptDialog.vue'

type PlanWindow = '1y' | '3y' | '5y' | 'inception'

interface PlanAccount {
  id: string
  name: string
  type: string
  currency: string
  startingMinor: string
  startingMinorBase: string
  baseCurrency: string
  historicalContribAnnualMinorBase: string | null
  historicalReturnPct: number | null
  historicalVolPct: number | null
  windowYearsAvailable: number
  hasSufficientHistory: boolean
}

interface PlanResponse {
  baseCurrency: string
  window: PlanWindow
  accounts: PlanAccount[]
}

// Per-account user overrides. `null` means "use the historical default";
// numeric means "user-typed override." Shape is intentionally plain & JSON-
// serializable so future persistence (POST /api/plan/scenarios) is a drop-in.
interface AccountOverride {
  included: boolean
  contributionMajor: number | null
  returnPct: number | null
}

// Scenario-persisted state. `window` is intentionally OUT — it controls how
// historical defaults are derived (a UI concern) and shouldn't be saved with
// the scenario. Two scenarios opened with the same window must look identical.
interface PlanState {
  horizonYears: number
  inflationPct: number
  accounts: Record<string, AccountOverride>
}

// Soft caps applied ONLY to historical defaults when the user hasn't typed an
// override. Treasury can record real CAGRs of 100 %+ on short windows (e.g. a
// concentrated brokerage account during a bull leg). Compounding that out 30
// years gives nonsense — projections explode. Defaults clamp at sane values
// the user can override upward in the edit modal if they actively want to
// model an optimistic scenario.
const MAX_DEFAULT_RETURN_PCT = 20

const planData = ref<PlanResponse | null>(null)
const loading = ref(false)

const state = ref<PlanState>({
  horizonYears: 30,
  inflationPct: 1,
  accounts: {},
})

// Session-only — drives defaults but is not part of any scenario.
const windowSelection = ref<PlanWindow>('3y')

async function loadPlan(window: PlanWindow) {
  loading.value = true
  try {
    const res = await api.get<PlanResponse>(`/api/plan/accounts?window=${window}`)
    planData.value = res
    for (const a of res.accounts) {
      if (!state.value.accounts[a.id]) {
        state.value.accounts[a.id] = {
          included: true,
          contributionMajor: null,
          returnPct: null,
        }
      }
    }
  } finally {
    loading.value = false
  }
}

// Scenario state.
interface Scenario {
  id: string
  name: string
  payload: unknown   // server may shape empty {} as [] in JSON; normalize on apply
  createdAt: string
  updatedAt: string
}

const scenarios = ref<Scenario[]>([])
const activeScenarioId = ref<string | null>(null)
const lastSavedState = ref<PlanState | null>(null)
const saving = ref(false)

function freshDefaultPayload(): PlanState {
  return { horizonYears: 30, inflationPct: 1, accounts: {} }
}

function clonePayload(p: PlanState): PlanState {
  return JSON.parse(JSON.stringify(p))
}

function normalizePayload(p: unknown): PlanState {
  const obj = (typeof p === 'object' && p !== null ? p as Record<string, unknown> : {})
  const accountsRaw = obj.accounts
  const accounts: Record<string, AccountOverride> =
    accountsRaw && typeof accountsRaw === 'object' && !Array.isArray(accountsRaw)
      ? accountsRaw as Record<string, AccountOverride>
      : {}
  // Older payloads may still carry a `window` field — silently drop it.
  return {
    horizonYears: typeof obj.horizonYears === 'number' ? obj.horizonYears : 30,
    inflationPct: typeof obj.inflationPct === 'number' ? obj.inflationPct : 1,
    accounts,
  }
}

const isDirty = computed(() => {
  if (!lastSavedState.value) return false
  return JSON.stringify(state.value) !== JSON.stringify(lastSavedState.value)
})

const activeScenario = computed(() =>
  scenarios.value.find((s) => s.id === activeScenarioId.value) ?? null,
)

async function applyScenario(s: Scenario) {
  const payload = normalizePayload(s.payload)
  state.value = clonePayload(payload)
  lastSavedState.value = clonePayload(payload)
  activeScenarioId.value = s.id
  // Window is session-only, so reuse the current selection.
  await loadPlan(windowSelection.value)
}

async function bootstrap() {
  scenarios.value = await api.get<Scenario[]>('/api/plan/scenarios')
  if (scenarios.value.length === 0) {
    const created = await api.post<Scenario>('/api/plan/scenarios', {
      name: 'Default',
      payload: freshDefaultPayload(),
    })
    scenarios.value = [created]
    await applyScenario(created)
  } else {
    // Server orders by updatedAt DESC, so [0] is the most-recently-touched.
    const first = scenarios.value[0]
    if (first) await applyScenario(first)
  }
}

onMounted(bootstrap)
watch(windowSelection, (w) => {
  // Only reload account defaults when window actually changes from what's
  // mounted — bootstrap+applyScenario already calls loadPlan once.
  if (planData.value && planData.value.window !== w) {
    loadPlan(w)
  }
})

// In-app dialogs replace native confirm() / prompt(). Each helper opens its
// modal and returns a Promise that resolves on confirm/submit or rejects-as-
// null on cancel — so the scenario actions stay linear async/await flows.
interface ConfirmOpts {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  destructive?: boolean
}
interface PromptOpts {
  title?: string
  label?: string
  initialValue?: string
  placeholder?: string
  confirmLabel?: string
}

const confirmState = ref<{ open: boolean; opts: ConfirmOpts; resolve: ((v: boolean) => void) | null }>({
  open: false,
  opts: { message: '' },
  resolve: null,
})
const promptState = ref<{ open: boolean; opts: PromptOpts; resolve: ((v: string | null) => void) | null }>({
  open: false,
  opts: {},
  resolve: null,
})

function askConfirm(opts: ConfirmOpts): Promise<boolean> {
  return new Promise((resolve) => {
    confirmState.value = { open: true, opts, resolve }
  })
}
function askPrompt(opts: PromptOpts): Promise<string | null> {
  return new Promise((resolve) => {
    promptState.value = { open: true, opts, resolve }
  })
}
function onConfirmResult(v: boolean) {
  confirmState.value.resolve?.(v)
  confirmState.value = { open: false, opts: { message: '' }, resolve: null }
}
function onPromptResult(v: string | null) {
  promptState.value.resolve?.(v)
  promptState.value = { open: false, opts: {}, resolve: null }
}

async function selectScenario(id: string) {
  if (id === activeScenarioId.value) return
  if (isDirty.value) {
    const ok = await askConfirm({
      title: 'Discard changes?',
      message: 'You have unsaved changes in the current scenario. Switching will discard them.',
      confirmLabel: 'Discard',
      destructive: true,
    })
    if (!ok) return
  }
  const s = scenarios.value.find((x) => x.id === id)
  if (s) await applyScenario(s)
}

async function saveCurrent() {
  if (!activeScenarioId.value) return
  saving.value = true
  try {
    const updated = await api.put<Scenario>(`/api/plan/scenarios/${activeScenarioId.value}`, {
      payload: clonePayload(state.value),
    })
    const idx = scenarios.value.findIndex((s) => s.id === updated.id)
    if (idx >= 0) scenarios.value[idx] = updated
    // Re-sort by updatedAt so saving bumps this scenario to first.
    scenarios.value.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
    lastSavedState.value = clonePayload(state.value)
  } finally {
    saving.value = false
  }
}

async function createNewScenario() {
  if (isDirty.value) {
    const ok = await askConfirm({
      title: 'Discard changes?',
      message: 'You have unsaved changes in the current scenario. Creating a new one will discard them.',
      confirmLabel: 'Discard & create',
      destructive: true,
    })
    if (!ok) return
  }
  const name = await askPrompt({
    title: 'New scenario',
    label: 'Name',
    placeholder: 'e.g. Conservative, Aggressive, Status quo',
    confirmLabel: 'Create',
  })
  if (!name) return
  const created = await api.post<Scenario>('/api/plan/scenarios', {
    name,
    payload: freshDefaultPayload(),
  })
  scenarios.value.unshift(created)
  await applyScenario(created)
}

async function renameActive() {
  if (!activeScenario.value) return
  const current = activeScenario.value
  const next = await askPrompt({
    title: 'Rename scenario',
    label: 'Name',
    initialValue: current.name,
    confirmLabel: 'Rename',
  })
  if (!next || next === current.name) return
  const updated = await api.put<Scenario>(`/api/plan/scenarios/${current.id}`, {
    name: next,
  })
  const idx = scenarios.value.findIndex((s) => s.id === updated.id)
  if (idx >= 0) scenarios.value[idx] = updated
}

async function deleteActive() {
  if (!activeScenario.value) return
  const current = activeScenario.value
  const ok = await askConfirm({
    title: 'Delete scenario',
    message: `Delete "${current.name}"? This can't be undone.`,
    confirmLabel: 'Delete',
    destructive: true,
  })
  if (!ok) return
  await api.delete(`/api/plan/scenarios/${current.id}`)
  scenarios.value = scenarios.value.filter((s) => s.id !== current.id)
  if (scenarios.value.length === 0) {
    // Always keep at least one scenario so the page has something to show.
    const created = await api.post<Scenario>('/api/plan/scenarios', {
      name: 'Default',
      payload: freshDefaultPayload(),
    })
    scenarios.value = [created]
    await applyScenario(created)
  } else {
    const first = scenarios.value[0]
    if (first) await applyScenario(first)
  }
}

const baseCurrency = computed(() => planData.value?.baseCurrency || 'CHF')

const WINDOW_OPTIONS: { value: PlanWindow; label: string }[] = [
  { value: '1y', label: '1 year' },
  { value: '3y', label: '3 years' },
  { value: '5y', label: '5 years' },
  { value: 'inception', label: 'All' },
]

interface EffectiveInputs {
  startingMajor: number
  contributionMajor: number
  returnPct: number
}

// What the projection uses. Override wins; otherwise historical return is
// clamped to MAX_DEFAULT_RETURN_PCT so a one-off bull-leg history doesn't
// compound to nonsense.
function effectiveFor(account: PlanAccount): EffectiveInputs {
  const override = state.value.accounts[account.id]
  const histContribMajor = account.historicalContribAnnualMinorBase
    ? Number(account.historicalContribAnnualMinorBase) / 100
    : 0
  const histReturnRaw = account.historicalReturnPct ?? 0
  const histReturnCapped = Math.min(histReturnRaw, MAX_DEFAULT_RETURN_PCT)
  return {
    startingMajor: Number(account.startingMinorBase) / 100,
    contributionMajor: override?.contributionMajor ?? histContribMajor,
    returnPct: override?.returnPct ?? histReturnCapped,
  }
}

const includedAccounts = computed(() =>
  (planData.value?.accounts ?? []).filter((a) => state.value.accounts[a.id]?.included),
)

const totalStartingMajor = computed(() =>
  includedAccounts.value.reduce((s, a) => s + effectiveFor(a).startingMajor, 0),
)

const totalContributionMajor = computed(() =>
  includedAccounts.value.reduce((s, a) => s + effectiveFor(a).contributionMajor, 0),
)

interface Point {
  year: number
  expected: number
  real: number          // expected, deflated by inflation each year
  contributed: number
}

// Simple compounding: each account walks (balance + contribution) * (1 + r)
// per year. Sums across accounts. Scenario bands are deferred — the previous
// naive ±σ-per-year math compounded out to absurd spreads that dominated the
// y-axis and visually broke the chart.
const projection = computed<Point[]>(() => {
  const points: Point[] = []
  const horizon = state.value.horizonYears
  const inflRate = state.value.inflationPct / 100
  const accountsList = includedAccounts.value.map((a) => {
    const e = effectiveFor(a)
    return { e, balance: e.startingMajor }
  })

  let contributed = totalStartingMajor.value
  points.push({
    year: 0,
    expected: totalStartingMajor.value,
    real: totalStartingMajor.value,
    contributed,
  })

  for (let year = 1; year <= horizon; year++) {
    let expected = 0
    for (const acc of accountsList) {
      const r = acc.e.returnPct / 100
      const c = acc.e.contributionMajor
      acc.balance = (acc.balance + c) * (1 + r)
      expected += acc.balance
    }
    contributed += totalContributionMajor.value
    const real = expected / Math.pow(1 + inflRate, year)
    points.push({ year, expected, real, contributed })
  }
  return points
})

const final = computed(() => projection.value[projection.value.length - 1])

const realFinal = computed(() => {
  if (!final.value) return null
  return final.value.expected / Math.pow(1 + state.value.inflationPct / 100, state.value.horizonYears)
})

const growth = computed(() =>
  final.value ? final.value.expected - final.value.contributed : 0,
)

function toMinor(major: number): string {
  return Math.round(major * 100).toString()
}

const thisYear = new Date().getFullYear()

const option = computed<EChartsOption>(() => {
  const years = projection.value.map((p) => String(thisYear + p.year))
  const expected = projection.value.map((p) => p.expected)
  const contributed = projection.value.map((p) => p.contributed)

  return {
    backgroundColor: 'transparent',
    grid: { top: 40, right: 10, bottom: 30, left: 60, containLabel: true },
    tooltip: {
      trigger: 'axis' as const,
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      valueFormatter: (v: unknown) =>
        formatMinor(toMinor(Number(v)), baseCurrency.value),
    },
    legend: {
      top: 4,
      textStyle: { color: chartColors.textMuted, fontSize: 11 },
      itemWidth: 10,
      itemHeight: 10,
    },
    xAxis: {
      type: 'category' as const,
      data: years,
      axisLine: { lineStyle: { color: chartColors.border } },
      axisLabel: { color: chartColors.textMuted, fontSize: 11 },
      boundaryGap: false,
    },
    yAxis: {
      type: 'value' as const,
      axisLine: { show: false },
      splitLine: { lineStyle: { color: chartColors.border, opacity: 0.4 } },
      axisLabel: {
        color: chartColors.textMuted,
        fontSize: 11,
        formatter: (v: number) => {
          const abs = Math.abs(v)
          if (abs >= 1_000_000_000) return `${(v / 1_000_000_000).toFixed(1)}B`
          if (abs >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
          if (abs >= 1_000) return `${(v / 1_000).toFixed(0)}k`
          return v.toFixed(0)
        },
      },
    },
    series: [
      {
        name: 'Projected (nominal)',
        type: 'line' as const,
        smooth: true,
        showSymbol: false,
        sampling: 'lttb' as const,
        color: chartColors.accent,
        itemStyle: { color: chartColors.accent },
        lineStyle: { color: chartColors.accent, width: 2 },
        areaStyle: {
          color: {
            type: 'linear' as const,
            x: 0, y: 0, x2: 0, y2: 1,
            colorStops: [
              { offset: 0, color: 'rgba(250,204,21,0.35)' },
              { offset: 1, color: 'rgba(250,204,21,0.00)' },
            ],
          },
        },
        data: expected,
      },
      {
        name: 'Contributions only',
        type: 'line' as const,
        smooth: true,
        showSymbol: false,
        sampling: 'lttb' as const,
        color: chartColors.textDim,
        itemStyle: { color: chartColors.textDim },
        lineStyle: { color: chartColors.textDim, width: 1.5, type: 'dotted' },
        data: contributed,
      },
    ],
  }
})

// Edit modal state.
const editingId = ref<string | null>(null)
const editingAccount = computed<PlanAccount | null>(() => {
  if (!editingId.value || !planData.value) return null
  return planData.value.accounts.find((a) => a.id === editingId.value) ?? null
})

// Local draft values for the modal. Contribution stays a string (empty = use
// default). Return % is numeric — driven by the slider, always has a value.
const draftContribution = ref<string>('')
const draftReturn = ref<number>(0)
// Track whether the user moved the slider this session, so an unchanged-from-
// default value still gets saved as null (= "follow history") on Apply.
const draftReturnTouched = ref<boolean>(false)

function openEditor(id: string) {
  const override = state.value.accounts[id]
  const account = planData.value?.accounts.find((a) => a.id === id)
  if (!override || !account) return
  editingId.value = id
  draftContribution.value = override.contributionMajor !== null ? String(override.contributionMajor) : ''
  // Slider starts where the projection currently is — override value if set,
  // otherwise the historical-capped default.
  draftReturn.value = override.returnPct ?? effectiveFor(account).returnPct
  draftReturnTouched.value = override.returnPct !== null
}

function closeEditor() {
  editingId.value = null
}

function parseNumberOrNull(v: string): number | null {
  if (v.trim() === '') return null
  const n = Number(v.replace(/[\s']/g, '').replace(',', '.'))
  return Number.isFinite(n) ? n : null
}

function applyEditor() {
  if (!editingId.value) return
  const id = editingId.value
  const prev = state.value.accounts[id]
  if (!prev) return
  // Replace the override object wholesale rather than mutating fields one by
  // one. Defensive against any reactivity edge case where deep-property writes
  // might not invalidate downstream computeds; replacement is unambiguous.
  state.value.accounts[id] = {
    ...prev,
    contributionMajor: parseNumberOrNull(draftContribution.value),
    returnPct: draftReturnTouched.value ? draftReturn.value : null,
  }
  closeEditor()
}

function resetEditor() {
  draftContribution.value = ''
  if (editingAccount.value) {
    draftReturn.value = effectiveFor(editingAccount.value).returnPct
  }
  draftReturnTouched.value = false
}

function onDraftReturnChange(v: number) {
  draftReturn.value = v
  draftReturnTouched.value = true
}

function isOverridden(id: string): boolean {
  const o = state.value.accounts[id]
  if (!o) return false
  return o.contributionMajor !== null || o.returnPct !== null
}

const accountTypeLabels: Record<string, string> = {
  brokerage: 'Brokerage',
  crypto_exchange: 'Crypto',
  crypto_wallet: 'Crypto',
  precious_metals: 'Metals',
  pillar_3a: 'Pillar 3a',
  real_estate: 'Real estate',
}
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Plan</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">
        Project where your investable assets land. Defaults come from each account's actual history.
        Edit per account to override. Numbers are estimates — not financial advice.
      </p>
    </header>

    <!-- Scenario picker -->
    <section class="card p-3 flex flex-wrap items-center gap-2">
      <div class="flex flex-wrap items-center gap-1.5 flex-1 min-w-0">
        <button
          v-for="s in scenarios"
          :key="s.id"
          type="button"
          class="text-sm px-3 py-1.5 rounded transition-colors cursor-pointer"
          :class="s.id === activeScenarioId
            ? 'bg-[var(--color-accent)] text-black font-medium'
            : 'bg-[var(--color-surface-hover)] text-[var(--color-text-muted)] hover:text-[var(--color-text)]'"
          @click="selectScenario(s.id)"
        >{{ s.name }}</button>
        <Button variant="ghost" size="sm" @click="createNewScenario">
          <Plus :size="12" />
          <span>New</span>
        </Button>
      </div>
      <div v-if="activeScenarioId" class="flex items-center gap-1.5">
        <span
          v-if="isDirty"
          class="text-xs text-[var(--color-accent)] uppercase tracking-wide"
        >unsaved</span>
        <Button
          v-if="isDirty"
          variant="primary"
          size="sm"
          :disabled="saving"
          @click="saveCurrent"
        >{{ saving ? 'Saving…' : 'Save' }}</Button>
        <Button variant="ghost" size="sm" @click="renameActive">Rename</Button>
        <Button
          variant="ghost"
          size="sm"
          icon-only
          title="Delete scenario"
          @click="deleteActive"
        >
          <Trash2 :size="12" />
        </Button>
      </div>
    </section>

    <!-- Side-by-side body: controls left, outcomes right -->
    <section class="grid grid-cols-1 lg:grid-cols-5 gap-4">
      <!-- Left: controls (2/5 of width — wider than 1/3 so the tabular
           account columns don't get cramped) -->
      <div class="space-y-4 lg:col-span-2">
        <div class="card p-4 space-y-4">
          <div class="space-y-1.5">
            <label class="label">History window</label>
            <SegmentedControl v-model="windowSelection" :options="WINDOW_OPTIONS" />
          </div>
          <RangeField
            v-model="state.horizonYears"
            label="Horizon"
            :min="1" :max="50" :step="1"
            :format="(v: number) => `${v}y → ${thisYear + v}`"
          />
          <RangeField
            v-model="state.inflationPct"
            label="Inflation"
            :min="0" :max="8" :step="0.1"
            :format="(v: number) => `${v.toFixed(1)} %`"
          />
        </div>

        <!-- Accounts card (nested in left column) -->
        <div class="card p-4 space-y-3">
          <h2 class="text-sm font-medium">Included accounts</h2>

          <div v-if="loading && !planData" class="text-sm text-[var(--color-text-muted)]">Loading…</div>
          <div
            v-else-if="!planData?.accounts.length"
            class="text-xs text-[var(--color-text-muted)]"
          >
            No investable accounts yet.
          </div>
          <!-- Column headers for the tabular section, only shown when there's data. -->
          <div
            v-if="planData?.accounts.length"
            class="grid grid-cols-[1fr_auto_auto_auto_auto] gap-3 px-1 text-[10px] uppercase tracking-wide text-[var(--color-text-dim)]"
          >
            <div></div>
            <div class="text-right w-16">Starting</div>
            <div class="text-right w-16">Contrib/yr</div>
            <div class="text-right w-12">Return</div>
            <div class="w-6"></div>
          </div>
          <ul v-if="planData?.accounts.length" class="divide-y divide-[var(--color-border)] text-sm">
            <li
              v-for="a in planData.accounts"
              :key="a.id"
              class="grid grid-cols-[1fr_auto_auto_auto_auto] gap-3 items-center py-2.5 first:pt-1.5 last:pb-1.5"
              :class="state.accounts[a.id]?.included ? '' : 'opacity-50'"
            >
              <!-- Left: checkbox + name + meta -->
              <div class="flex items-center gap-2 min-w-0">
                <input
                  :id="`acc-${a.id}`"
                  type="checkbox"
                  :checked="state.accounts[a.id]?.included ?? false"
                  class="accent-[var(--color-accent)] w-4 h-4 rounded shrink-0"
                  @change="(e) => { const o = state.accounts[a.id]; if (o) o.included = (e.target as HTMLInputElement).checked }"
                />
                <label
                  :for="`acc-${a.id}`"
                  class="flex-1 min-w-0 cursor-pointer"
                >
                  <div class="flex items-center gap-1.5">
                    <span class="truncate font-medium">{{ a.name }}</span>
                    <span
                      v-if="isOverridden(a.id)"
                      class="text-[var(--color-accent)] text-[10px] uppercase tracking-wide"
                      title="Custom values set"
                    >custom</span>
                    <span
                      v-if="!a.hasSufficientHistory"
                      :title="`Only ${a.windowYearsAvailable.toFixed(1)}y of history — defaults aren't reliable`"
                      class="inline-flex shrink-0 cursor-help"
                    >
                      <AlertTriangle :size="13" class="text-[var(--color-negative)]" />
                    </span>
                  </div>
                  <div class="text-xs text-[var(--color-text-dim)] truncate">
                    {{ accountTypeLabels[a.type] ?? a.type }}
                    <span class="mx-1">·</span>
                    <span
                      :class="a.currency !== baseCurrency ? 'text-[var(--color-text-muted)]' : ''"
                      :title="a.currency !== baseCurrency ? `Converted to ${baseCurrency} at today's FX` : undefined"
                    >{{ a.currency }}</span>
                    <span class="mx-1">·</span>
                    {{ a.windowYearsAvailable.toFixed(1) }}y history
                  </div>
                </label>
              </div>

              <!-- Three tabular columns, right-aligned, fixed widths so they align across rows. -->
              <div class="tabular text-right w-16 text-sm whitespace-nowrap">
                <MoneyDisplay :minor="a.startingMinorBase" :currency="baseCurrency" compact sensitive />
              </div>
              <div
                class="tabular text-right w-16 text-sm whitespace-nowrap"
                :class="effectiveFor(a).contributionMajor > 0
                  ? 'text-[var(--color-text)]'
                  : 'text-[var(--color-text-dim)]'"
              >
                <MoneyDisplay
                  v-if="effectiveFor(a).contributionMajor > 0"
                  :minor="toMinor(effectiveFor(a).contributionMajor)"
                  :currency="baseCurrency"
                  compact
                  sensitive
                />
                <span v-else>—</span>
              </div>
              <div class="tabular text-right w-12 text-sm whitespace-nowrap">
                {{ effectiveFor(a).returnPct.toFixed(1) }}%
              </div>

              <Button
                variant="ghost"
                size="sm"
                icon-only
                title="Edit projection inputs for this account"
                @click="openEditor(a.id)"
              >
                <Pencil :size="12" />
              </Button>
            </li>
          </ul>
        </div>
      </div>

      <!-- Right: outcomes — sticky on lg so it stays in view while the left
           column (controls + account list) scrolls. -->
      <div class="space-y-4 lg:col-span-3 lg:sticky lg:top-4 lg:self-start">
        <!-- Summary stats -->
        <div
          v-if="final"
          class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
          style="background-color: var(--color-border);"
        >
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Final value</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-accent)]"
          :title="formatMinor(toMinor(final.expected), baseCurrency)"
        >
          {{ formatMinorCompact(toMinor(final.expected), baseCurrency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">In today's money</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-text-muted)]"
          :title="realFinal !== null ? formatMinor(toMinor(realFinal), baseCurrency) : '—'"
        >
          {{ realFinal !== null ? formatMinorCompact(toMinor(realFinal), baseCurrency) : '—' }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Total contributed</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1"
          :title="formatMinor(toMinor(final.contributed), baseCurrency)"
        >
          {{ formatMinorCompact(toMinor(final.contributed), baseCurrency) }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Growth</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1"
          :class="growth >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
          :title="formatMinor(toMinor(growth), baseCurrency)"
        >
          <TrendingUp v-if="growth >= 0" :size="20" class="inline -mt-1" />
          {{ formatMinorCompact(toMinor(growth), baseCurrency) }}
        </p>
      </div>
        </div>

        <!-- Chart -->
        <div class="card p-4">
          <div class="flex items-baseline justify-between mb-2">
            <h3 class="text-sm font-medium">Projection</h3>
            <span class="text-xs text-[var(--color-text-muted)]">
              {{ thisYear }} – {{ thisYear + state.horizonYears }}
            </span>
          </div>
          <VChart :option="option" class="w-full" style="height: 26rem" autoresize />
        </div>

        <p class="text-xs text-[var(--color-text-dim)]">
          Default return is capped at {{ MAX_DEFAULT_RETURN_PCT }} %/yr; edit any account to use its
          uncapped history or your own number. No tax modeling, no fee drag beyond what historical
          returns already reflect.
        </p>
      </div>
    </section>

    <!-- Per-account edit modal -->
    <BaseModal
      :open="editingId !== null"
      :title="editingAccount ? `Edit · ${editingAccount.name}` : 'Edit'"
      size="md"
      @close="closeEditor"
    >
      <div v-if="editingAccount" class="space-y-4">
        <!-- Historical context, read-only -->
        <div class="rounded border border-[var(--color-border)] p-3 text-sm">
          <p class="label mb-1.5">
            From {{ editingAccount.windowYearsAvailable.toFixed(1) }}y of history
          </p>
          <dl class="grid grid-cols-2 gap-2 text-xs">
            <div>
              <dt class="text-[var(--color-text-dim)]">Annualized return</dt>
              <dd class="tabular mt-0.5">
                {{ editingAccount.historicalReturnPct !== null
                    ? `${editingAccount.historicalReturnPct.toFixed(1)} %`
                    : '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-[var(--color-text-dim)]">Avg contribution / yr</dt>
              <dd class="tabular mt-0.5">
                {{ editingAccount.historicalContribAnnualMinorBase
                    ? formatMinorCompact(editingAccount.historicalContribAnnualMinorBase, baseCurrency)
                    : '—' }}
              </dd>
            </div>
          </dl>
          <p
            v-if="
              editingAccount.historicalReturnPct !== null
              && editingAccount.historicalReturnPct > MAX_DEFAULT_RETURN_PCT
            "
            class="text-xs text-[var(--color-text-muted)] mt-2"
          >
            Projection uses {{ MAX_DEFAULT_RETURN_PCT }} %/yr by default — uncapped history would
            compound to unrealistic terminal values. Type a value below to override.
          </p>
        </div>

        <!-- Editable inputs -->
        <div class="space-y-4">
          <div class="space-y-1">
            <label class="label">Contribution / yr</label>
            <input
              v-model="draftContribution"
              type="text"
              inputmode="decimal"
              class="input tabular w-full"
              :placeholder="
                editingAccount.historicalContribAnnualMinorBase
                  ? (Number(editingAccount.historicalContribAnnualMinorBase) / 100).toFixed(0)
                  : '0'
              "
            />
            <p class="text-[10px] text-[var(--color-text-dim)]">
              In {{ baseCurrency }}. Empty = follow {{ editingAccount.windowYearsAvailable.toFixed(1) }}y history.
            </p>
          </div>
          <RangeField
            label="Expected return"
            :model-value="draftReturn"
            :min="0" :max="25" :step="0.5"
            :format="(v: number) => `${v.toFixed(1)} %`"
            :hint="
              draftReturnTouched
                ? 'Custom value — will be saved when you Apply.'
                : 'Following history (capped). Drag to override.'
            "
            @update:model-value="onDraftReturnChange"
          />
        </div>
      </div>
      <template #footer>
        <Button variant="ghost" size="sm" @click="resetEditor">
          <RotateCcw :size="12" />
          <span>Reset</span>
        </Button>
        <Button variant="ghost" size="sm" @click="closeEditor">Cancel</Button>
        <Button variant="primary" size="sm" @click="applyEditor">Apply</Button>
      </template>
    </BaseModal>

    <!-- App-level dialogs (replacing native confirm() / prompt()) -->
    <ConfirmDialog
      :open="confirmState.open"
      :title="confirmState.opts.title"
      :message="confirmState.opts.message"
      :confirm-label="confirmState.opts.confirmLabel"
      :cancel-label="confirmState.opts.cancelLabel"
      :destructive="confirmState.opts.destructive"
      @confirm="onConfirmResult(true)"
      @cancel="onConfirmResult(false)"
    />
    <PromptDialog
      :open="promptState.open"
      :title="promptState.opts.title"
      :label="promptState.opts.label"
      :initial-value="promptState.opts.initialValue"
      :placeholder="promptState.opts.placeholder"
      :confirm-label="promptState.opts.confirmLabel"
      @submit="onPromptResult"
      @cancel="onPromptResult(null)"
    />
  </div>
</template>
