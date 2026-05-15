// Shared category metadata — kept in one place so labels and palette colors stay
// consistent between forms, badges, the filter dropdown, and the cashflow chart.

import { chartColors } from '@/lib/charts'

export interface CategoryMeta {
  value: string
  label: string
  /** Color used in the badge + cashflow-by-category chart. */
  color: string
}

// Order matters: this is the order shown in the picker dropdown.
export const CATEGORIES: CategoryMeta[] = [
  // Income
  { value: 'salary',        label: 'Salary',        color: '#22c55e' },
  { value: 'interest',      label: 'Interest',      color: '#86efac' },
  { value: 'dividend',      label: 'Dividend',      color: '#4ade80' },
  { value: 'gift',          label: 'Gift',          color: '#bef264' },
  // Recurring household
  { value: 'rent',          label: 'Rent',          color: '#facc15' },
  { value: 'utilities',     label: 'Utilities',     color: '#fde047' },
  { value: 'insurance',     label: 'Insurance',     color: '#fbbf24' },
  { value: 'subscriptions', label: 'Subscriptions', color: '#f59e0b' },
  // Day-to-day
  { value: 'groceries',     label: 'Groceries',     color: '#fb923c' },
  { value: 'dining',        label: 'Dining',        color: '#f97316' },
  { value: 'transport',     label: 'Transport',     color: '#a78bfa' },
  { value: 'healthcare',    label: 'Healthcare',    color: '#f472b6' },
  { value: 'entertainment', label: 'Entertainment', color: '#c084fc' },
  { value: 'travel',        label: 'Travel',        color: '#22d3ee' },
  { value: 'shopping',      label: 'Shopping',      color: '#e879f9' },
  { value: 'education',     label: 'Education',     color: '#60a5fa' },
  // Money movement
  { value: 'transfer',      label: 'Transfer',      color: '#64748b' },
  { value: 'savings',       label: 'Savings',       color: '#34d399' },
  { value: 'tax',           label: 'Tax',           color: '#f87171' },
  { value: 'fees',          label: 'Fees',          color: '#fda4af' },
  // Catch-all
  { value: 'other',         label: 'Other',         color: chartColors.textDim },
]

const BY_VALUE: Record<string, CategoryMeta> = Object.fromEntries(
  CATEGORIES.map((c) => [c.value, c]),
)

export function categoryMeta(value: string | null | undefined): CategoryMeta | null {
  if (!value) return null
  return BY_VALUE[value] ?? null
}
