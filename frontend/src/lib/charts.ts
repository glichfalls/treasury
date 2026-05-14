// ECharts setup: register only the chart types we actually use to keep bundle small.
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { LineChart, PieChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  TitleComponent,
  LegendComponent,
  DataZoomComponent,
} from 'echarts/components'
import VChart, { THEME_KEY } from 'vue-echarts'
import type { EChartsOption } from 'echarts/types/dist/shared'

use([
  CanvasRenderer,
  LineChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  TitleComponent,
  LegendComponent,
  DataZoomComponent,
])

export { VChart, THEME_KEY }
export type { EChartsOption }

// Shared dark theme tokens — read from the CSS variables defined in main.css.
export const chartColors = {
  bg: '#0a0a0c',
  surface: '#131316',
  border: '#26262c',
  text: '#f4f4f5',
  textMuted: '#a1a1aa',
  textDim: '#71717a',
  accent: '#6366f1',
  positive: '#10b981',
  negative: '#ef4444',
  palette: ['#6366f1', '#10b981', '#f59e0b', '#ec4899', '#06b6d4', '#a855f7', '#22c55e', '#ef4444', '#3b82f6'],
}
