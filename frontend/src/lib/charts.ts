// ECharts setup: register only the chart types we actually use to keep bundle small.
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, PieChart, BarChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  TitleComponent,
  LegendComponent,
  DataZoomComponent,
  VisualMapComponent,
  MarkLineComponent,
} from 'echarts/components'
import VChart, { THEME_KEY } from 'vue-echarts'
import type { EChartsOption } from 'echarts/types/dist/shared'

use([
  CanvasRenderer,
  LineChart,
  PieChart,
  BarChart,
  GridComponent,
  TooltipComponent,
  TitleComponent,
  LegendComponent,
  DataZoomComponent,
  VisualMapComponent,
  MarkLineComponent,
])

export { VChart, THEME_KEY }
export type { EChartsOption }

// Shared dark theme tokens — read from the CSS variables defined in main.css.
export const chartColors = {
  bg: '#000000',
  surface: '#0f0f10',
  border: '#262629',
  text: '#fafafa',
  textMuted: '#a1a1aa',
  textDim: '#71717a',
  accent: '#facc15',
  highlight: '#a78bfa',
  positive: '#22c55e',
  negative: '#f87171',
  // Yellow first, violet second; rest are tuned to read well on pure black for donut /
  // multi-series charts.
  palette: ['#facc15', '#a78bfa', '#22c55e', '#fb923c', '#22d3ee', '#f472b6', '#60a5fa', '#94a3b8', '#fde047'],
}

export type Range = '1w' | '1m' | '3m' | '6m' | 'ytd' | '1y' | '2y' | '5y' | 'all'
export const RANGES: readonly Range[] = ['1w', '1m', '3m', '6m', 'ytd', '1y', '2y', '5y', 'all'] as const

export type Granularity = 'daily' | 'weekly' | 'monthly'

/** Auto-pick sampling cadence so short windows don't render with two data points. */
export function granularityFor(range: Range): Granularity {
  if (range === '1w' || range === '1m' || range === '3m' || range === '6m' || range === 'ytd' || range === '1y') return 'daily'
  if (range === '2y' || range === '5y') return 'weekly'
  return 'monthly'
}

/** ISO-date bounds for a named range, anchored on today. */
export function rangeBounds(range: Range): { from: string; to: string } {
  const to = new Date()
  const from = new Date()
  if (range === '1w') from.setDate(from.getDate() - 7)
  else if (range === '1m') from.setMonth(from.getMonth() - 1)
  else if (range === '3m') from.setMonth(from.getMonth() - 3)
  else if (range === '6m') from.setMonth(from.getMonth() - 6)
  else if (range === 'ytd') from.setMonth(0, 1)
  else if (range === '1y') from.setFullYear(from.getFullYear() - 1)
  else if (range === '2y') from.setFullYear(from.getFullYear() - 2)
  else if (range === '5y') from.setFullYear(from.getFullYear() - 5)
  else from.setFullYear(from.getFullYear() - 20)
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}
