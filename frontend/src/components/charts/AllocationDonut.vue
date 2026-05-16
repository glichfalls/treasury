<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { VChart, chartColors, type EChartsOption } from '@/lib/charts'
import { formatMinor } from '@/lib/money'
import ChartCard from '@/components/ui/ChartCard.vue'

interface Slice {
  label: string
  isin: string | null
  valueBaseMinor: string
}
interface AllocationResponse {
  baseCurrency: string
  slices: Slice[]
}

const props = withDefaults(
  defineProps<{
    endpoint: string
    /** Slices below this percent get folded into "Other". */
    minPercent?: number
    /** Hard cap on individually shown slices. */
    topN?: number
  }>(),
  { minPercent: 2.5, topN: 9 },
)

const data = ref<AllocationResponse | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.get<AllocationResponse>(props.endpoint)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.endpoint, load)

interface Child {
  label: string
  valueMinor: bigint
  percent: number
}
interface DisplaySlice {
  label: string
  valueMinor: bigint
  percent: number
  isOther: boolean
  children: Child[]
}

const grouped = computed<{ display: DisplaySlice[]; total: bigint; currency: string }>(() => {
  if (!data.value) return { display: [], total: 0n, currency: '' }
  const currency = data.value.baseCurrency

  const sorted = [...data.value.slices].sort(
    (a, b) => Number(BigInt(b.valueBaseMinor) - BigInt(a.valueBaseMinor)),
  )
  const total = sorted.reduce((s, x) => s + BigInt(x.valueBaseMinor), 0n)
  if (total === 0n) return { display: [], total: 0n, currency }

  const totalNum = Number(total)
  const enriched: Child[] = sorted.map((s) => ({
    label: s.label,
    valueMinor: BigInt(s.valueBaseMinor),
    percent: (Number(s.valueBaseMinor) / totalNum) * 100,
  }))

  const kept: Child[] = []
  const small: Child[] = []
  for (const slice of enriched) {
    if (kept.length < props.topN && slice.percent >= props.minPercent) {
      kept.push(slice)
    } else {
      small.push(slice)
    }
  }

  const display: DisplaySlice[] = kept.map((s) => ({ ...s, isOther: false, children: [] }))

  // Don't create an "Other (1)" slice — just show that lone small slice on its own.
  if (small.length === 1) {
    display.push({ ...small[0]!, isOther: false, children: [] })
  } else if (small.length > 1) {
    const otherValue = small.reduce((s, x) => s + x.valueMinor, 0n)
    const otherPct = small.reduce((s, x) => s + x.percent, 0)
    display.push({
      label: `Other (${small.length})`,
      valueMinor: otherValue,
      percent: otherPct,
      isOther: true,
      children: small,
    })
  }

  return { display, total, currency }
})

function colorFor(i: number, isOther: boolean): string {
  return isOther ? chartColors.textDim : chartColors.palette[i % chartColors.palette.length]!
}

const option = computed<EChartsOption>(() => {
  const { display, currency } = grouped.value
  return {
    backgroundColor: 'transparent',
    tooltip: {
      trigger: 'item',
      backgroundColor: chartColors.surface,
      borderColor: chartColors.border,
      textStyle: { color: chartColors.text },
      formatter: (p: unknown) => {
        const param = p as { dataIndex: number; name: string; value: number; percent: number }
        const s = display[param.dataIndex]
        const head =
          `<div style="font-weight:600">${param.name}</div>` +
          `<div style="color:${chartColors.textMuted}">${formatMinor(String(Math.round(param.value * 100)), currency)} · ${param.percent.toFixed(1)}%</div>`
        if (s && s.children.length > 0) {
          const items = s.children
            .map(
              (c) =>
                `<div style="color:${chartColors.textMuted}">• ${c.label}: ${c.percent.toFixed(1)}%</div>`,
            )
            .join('')
          return head + items
        }
        return head
      },
    },
    series: [{
      type: 'pie',
      radius: ['55%', '80%'],
      center: ['50%', '50%'],
      avoidLabelOverlap: true,
      itemStyle: { borderRadius: 4, borderColor: chartColors.bg, borderWidth: 2 },
      label: { show: false },
      data: display.map((s, i) => ({
        name: s.label,
        value: Number(s.valueMinor) / 100,
        itemStyle: { color: colorFor(i, s.isOther) },
      })),
    }],
  }
})

const totalFormatted = computed(() => {
  const { total, currency } = grouped.value
  if (total === 0n) return ''
  return formatMinor(total.toString(), currency)
})

const showOtherDetails = ref(false)
</script>

<template>
  <ChartCard
    title="Allocation"
    :loading="loading"
    :empty="!data || data.slices.length === 0"
    empty-text="No holdings."
  >
    <template #actions>
      <span class="text-xs text-[var(--color-text-muted)] tabular">{{ totalFormatted }}</span>
    </template>
    <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-4 items-center">
      <VChart :option="option" style="height: 18rem" autoresize />
      <ul class="space-y-1 text-sm overflow-y-auto" style="max-height: 18rem">
        <template v-for="(s, i) in grouped.display" :key="s.label">
          <li class="flex items-center gap-2">
            <span
              class="w-2.5 h-2.5 rounded-sm shrink-0"
              :style="{ backgroundColor: colorFor(i, s.isOther) }"
            />
            <span class="flex-1 truncate" :class="s.isOther ? 'text-[var(--color-text-muted)]' : ''">
              {{ s.label }}
            </span>
            <button
              v-if="s.isOther"
              class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
              @click="showOtherDetails = !showOtherDetails"
            >
              {{ showOtherDetails ? 'hide' : 'show' }}
            </button>
            <span class="tabular text-[var(--color-text-muted)] w-12 text-right">
              {{ s.percent.toFixed(1) }}%
            </span>
          </li>
          <template v-if="s.isOther && showOtherDetails">
            <li
              v-for="c in s.children"
              :key="c.label"
              class="flex items-center gap-2 pl-5 text-xs text-[var(--color-text-muted)]"
            >
              <span class="flex-1 truncate">{{ c.label }}</span>
              <span class="tabular w-12 text-right">{{ c.percent.toFixed(2) }}%</span>
            </li>
          </template>
        </template>
      </ul>
    </div>
  </ChartCard>
</template>
