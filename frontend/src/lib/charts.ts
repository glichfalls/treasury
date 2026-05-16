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
