<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useAccountsStore } from '@/stores/accounts'
import { formatMinor } from '@/lib/money'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { TrendingUp, RotateCcw } from 'lucide-vue-next'
import Button from '@/components/ui/Button.vue'

const accounts = useAccountsStore()

onMounted(() => {
  if (!accounts.loaded) accounts.fetchAll()
})

// Account types that contribute to long-term wealth-building. The user can opt
// individual accounts in or out below, but this is the default set.
const INVESTABLE_TYPES = new Set([
  'brokerage',
  'crypto_exchange',
  'crypto_wallet',
  'precious_metals',
  'pillar_3a',
  'real_estate',
])

const baseCurrency = ref('CHF')

// Track which accounts are included; default to investable types only.
const includedAccountIds = ref<Set<string>>(new Set())
const initializedAccountsAt = ref<number>(0)

watch(
  () => accounts.accounts,
  (list) => {
    if (initializedAccountsAt.value !== 0 && list.length === initializedAccountsAt.value) return
    initializedAccountsAt.value = list.length
    includedAccountIds.value = new Set(
      list.filter((a) => INVESTABLE_TYPES.has(a.type)).map((a) => a.id),
    )
  },
  { immediate: true },
)

const startingValue = computed(() => {
  // Sum balances of included accounts, ignoring those in other currencies.
  // Simpler than building cross-currency FX for the projection — the user can
  // adjust the starting number directly if they want everything in one bucket.
  let sum = 0n
  for (const a of accounts.accounts) {
    if (!includedAccountIds.value.has(a.id)) continue
    if (a.currency !== baseCurrency.value) continue
    sum += BigInt(a.balanceMinor)
  }
  return sum
})

// User-tweakable inputs (annual contribution + rates + horizon).
const customStartingMajor = ref<string>('')
const yearlyContributionMajor = ref<string>('12000') // CHF per year, reasonable default
const annualReturnPct = ref<number>(5)
const inflationPct = ref<number>(2)
const yearsHorizon = ref<number>(30)

const startingMajor = computed(() => {
  if (customStartingMajor.value !== '') {
    const n = Number(customStartingMajor.value.replace(/[\s']/g, '').replace(',', '.'))
    return Number.isFinite(n) ? n : 0
  }
  // Convert minor (cents) → major.
  return Number(startingValue.value) / 100
})

function resetStartingValue() {
  customStartingMajor.value = ''
}

interface Point {
  year: number
  nominal: number
  real: number
  contributed: number
}

const projection = computed<Point[]>(() => {
  const points: Point[] = []
  const r = annualReturnPct.value / 100
  const inflRate = inflationPct.value / 100
  const contribution = Number(yearlyContributionMajor.value) || 0
  let nominal = startingMajor.value
  let contributed = startingMajor.value
  for (let year = 0; year <= yearsHorizon.value; year++) {
    if (year > 0) {
      // Contribute at start of year, then grow over the year (typical model).
      nominal = (nominal + contribution) * (1 + r)
      contributed += contribution
    }
    const real = nominal / Math.pow(1 + inflRate, year)
    points.push({ year, nominal, real, contributed })
  }
  return points
})

const final = computed(() => projection.value[projection.value.length - 1])

function toMinor(major: number): string {
  return Math.round(major * 100).toString()
}

const thisYear = new Date().getFullYear()

const option = computed<EChartsOption>(() => {
  const years = projection.value.map((p) => String(thisYear + p.year))
  const nominalSeries = projection.value.map((p) => p.nominal)
  const realSeries = projection.value.map((p) => p.real)
  const contributedSeries = projection.value.map((p) => p.contributed)

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
        data: nominalSeries,
      },
      {
        name: 'Inflation-adjusted',
        type: 'line' as const,
        smooth: true,
        showSymbol: false,
        sampling: 'lttb' as const,
        color: chartColors.highlight,
        itemStyle: { color: chartColors.highlight },
        lineStyle: { color: chartColors.highlight, width: 1.5, type: 'dashed' },
        data: realSeries,
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
        data: contributedSeries,
      },
    ],
  }
})

const growth = computed(() => (final.value ? final.value.nominal - final.value.contributed : 0))
</script>

<template>
  <div class="space-y-8">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Plan</h1>
      <p class="text-sm text-[var(--color-text-muted)] mt-1">
        Project where your investable assets land, given a return rate and yearly contribution.
        Numbers are estimates — not financial advice.
      </p>
    </header>

    <!-- Inputs -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="card p-5 space-y-4 lg:col-span-2">
        <h2 class="text-sm font-medium">Assumptions</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1.5">
            <div class="flex items-baseline justify-between">
              <label class="label">Starting value ({{ baseCurrency }})</label>
              <Button
                v-if="customStartingMajor !== ''"
                variant="ghost"
                size="sm"
                @click="resetStartingValue"
              >
                <RotateCcw :size="12" />
                <span>Reset</span>
              </Button>
            </div>
            <input
              v-model="customStartingMajor"
              type="text"
              :placeholder="(Number(startingValue) / 100).toFixed(0)"
              class="input tabular"
            />
            <p class="text-xs text-[var(--color-text-dim)]">
              Defaults to {{ formatMinor(startingValue.toString(), baseCurrency) }} from your
              investable accounts. Adjust if needed.
            </p>
          </div>

          <div class="space-y-1.5">
            <label class="label">Annual contribution ({{ baseCurrency }})</label>
            <input
              v-model="yearlyContributionMajor"
              type="text"
              class="input tabular"
            />
            <p class="text-xs text-[var(--color-text-dim)]">
              How much you add each year. E.g. 7'056 for max Pillar 3a + savings.
            </p>
          </div>

          <div class="space-y-1.5">
            <div class="flex items-baseline justify-between">
              <label class="label">Expected return</label>
              <span class="text-xs tabular text-[var(--color-text-muted)]">{{ annualReturnPct.toFixed(1) }} %</span>
            </div>
            <input
              v-model.number="annualReturnPct"
              type="range" min="0" max="15" step="0.1"
              class="w-full accent-[var(--color-accent)]"
            />
            <p class="text-xs text-[var(--color-text-dim)]">
              Long-term stock-market average is ~7 % nominal, ~5 % real.
            </p>
          </div>

          <div class="space-y-1.5">
            <div class="flex items-baseline justify-between">
              <label class="label">Inflation</label>
              <span class="text-xs tabular text-[var(--color-text-muted)]">{{ inflationPct.toFixed(1) }} %</span>
            </div>
            <input
              v-model.number="inflationPct"
              type="range" min="0" max="8" step="0.1"
              class="w-full accent-[var(--color-accent)]"
            />
            <p class="text-xs text-[var(--color-text-dim)]">
              Swiss CPI has averaged ~1 % over the last decade.
            </p>
          </div>

          <div class="space-y-1.5 sm:col-span-2">
            <div class="flex items-baseline justify-between">
              <label class="label">Horizon</label>
              <span class="text-xs tabular text-[var(--color-text-muted)]">{{ yearsHorizon }} years (until {{ thisYear + yearsHorizon }})</span>
            </div>
            <input
              v-model.number="yearsHorizon"
              type="range" min="1" max="50" step="1"
              class="w-full accent-[var(--color-accent)]"
            />
          </div>
        </div>
      </div>

      <!-- Account inclusions -->
      <div class="card p-5 space-y-3">
        <h2 class="text-sm font-medium">Included accounts</h2>
        <p class="text-xs text-[var(--color-text-muted)]">
          Only {{ baseCurrency }} accounts count toward the starting value. Others are listed but
          ignored for now.
        </p>
        <ul class="space-y-2 max-h-72 overflow-y-auto text-sm">
          <li
            v-for="a in accounts.accounts"
            :key="a.id"
            class="flex items-center gap-2"
          >
            <input
              :id="`acc-${a.id}`"
              type="checkbox"
              :checked="includedAccountIds.has(a.id)"
              :disabled="a.currency !== baseCurrency"
              class="accent-[var(--color-accent)] w-4 h-4 rounded shrink-0"
              @change="(e) => {
                const next = new Set(includedAccountIds)
                if ((e.target as HTMLInputElement).checked) next.add(a.id)
                else next.delete(a.id)
                includedAccountIds = next
              }"
            />
            <label
              :for="`acc-${a.id}`"
              class="flex-1 truncate cursor-pointer"
              :class="a.currency === baseCurrency ? '' : 'text-[var(--color-text-dim)]'"
            >
              {{ a.name }}
              <span class="text-xs text-[var(--color-text-dim)] ml-1">{{ a.currency }}</span>
            </label>
            <span class="tabular text-xs text-[var(--color-text-muted)]">
              {{ formatMinor(a.balanceMinor, a.currency) }}
            </span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Summary stats -->
    <section class="grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg"
             style="background-color: var(--color-border);">
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Final value (nominal)</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-accent)]">
          {{ final ? formatMinor(toMinor(final.nominal), baseCurrency) : '—' }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">In today's money</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-highlight)]">
          {{ final ? formatMinor(toMinor(final.real), baseCurrency) : '—' }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Total contributed</p>
        <p class="text-2xl font-semibold tracking-tight tabular mt-1">
          {{ final ? formatMinor(toMinor(final.contributed), baseCurrency) : '—' }}
        </p>
      </div>
      <div class="px-5 py-4" style="background-color: var(--color-surface);">
        <p class="label">Growth</p>
        <p
          class="text-2xl font-semibold tracking-tight tabular mt-1"
          :class="growth >= 0 ? 'text-[var(--color-positive)]' : 'text-[var(--color-negative)]'"
        >
          <TrendingUp v-if="growth >= 0" :size="20" class="inline -mt-1" />
          {{ formatMinor(toMinor(growth), baseCurrency) }}
        </p>
      </div>
    </section>

    <!-- Chart -->
    <section class="card p-4">
      <div class="flex items-baseline justify-between mb-2">
        <h3 class="text-sm font-medium">Projection</h3>
        <span class="text-xs text-[var(--color-text-muted)]">
          {{ thisYear }} – {{ thisYear + yearsHorizon }}
        </span>
      </div>
      <VChart :option="option" class="w-full" style="height: 22rem" autoresize />
    </section>

    <p class="text-xs text-[var(--color-text-dim)]">
      Compound math only — assumes contributions at the start of each year, returns applied annually.
      No tax modeling, no fee drag, no sequence-of-returns risk. Real-world outcomes vary.
    </p>
  </div>
</template>
