<script setup lang="ts">
import { ref } from 'vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import RangeSelector from '@/components/ui/RangeSelector.vue'
import CurrenciesTab from '@/components/insights/CurrenciesTab.vue'
import FeesTab from '@/components/insights/FeesTab.vue'
import DividendsTab from '@/components/insights/DividendsTab.vue'
import type { Range } from '@/lib/charts'

type InsightTab = 'currencies' | 'fees' | 'dividends'

const activeTab = ref<InsightTab>('currencies')
const range = ref<Range>('1y')

const tabs: { value: InsightTab; label: string }[] = [
  { value: 'currencies', label: 'Currencies' },
  { value: 'fees', label: 'Fees' },
  { value: 'dividends', label: 'Dividends' },
]
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Insights</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">FX exposure, fees, and dividend income.</p>
      </div>
      <RangeSelector v-model="range" />
    </header>

    <SegmentedControl v-model="activeTab" variant="tabs" :options="tabs" />

    <CurrenciesTab v-if="activeTab === 'currencies'" :range="range" />
    <FeesTab v-else-if="activeTab === 'fees'" :range="range" />
    <DividendsTab v-else-if="activeTab === 'dividends'" :range="range" />
  </div>
</template>
