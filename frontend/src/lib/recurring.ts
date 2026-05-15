import { api } from '@/lib/api'

export type RecurringFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly'

export interface RecurringRule {
  id: string
  accountId: string
  description: string
  amountMinor: string
  currency: string
  type: string
  category: string | null
  frequency: RecurringFrequency
  dayOfMonth: number | null
  dayOfWeek: number | null
  monthOfYear: number | null
  startsAt: string
  endsAt: string | null
  active: boolean
  lastGeneratedAt: string | null
  nextOccurrenceAt: string | null
}

export interface RecurringInput {
  description: string
  amountMinor: string
  currency?: string
  type?: string
  category?: string | null
  frequency: RecurringFrequency
  dayOfMonth?: number | null
  dayOfWeek?: number | null
  monthOfYear?: number | null
  startsAt?: string
  endsAt?: string | null
  active?: boolean
}

export const recurringApi = {
  list: (accountId: string) =>
    api.get<RecurringRule[]>(`/api/accounts/${accountId}/recurring`),

  create: (accountId: string, input: RecurringInput) =>
    api.post<RecurringRule>(`/api/accounts/${accountId}/recurring`, input),

  update: (id: string, input: Partial<RecurringInput>) =>
    api.patch<RecurringRule>(`/api/recurring/${id}`, input),

  remove: (id: string) => api.delete<void>(`/api/recurring/${id}`),

  run: (id: string) =>
    api.post<{ created: number; rule: RecurringRule }>(`/api/recurring/${id}/run`, {}),
}

export const DAYS_OF_WEEK = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 7, label: 'Sunday' },
]

export const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/** Human-readable description of when a rule fires. */
export function describeSchedule(r: RecurringRule): string {
  switch (r.frequency) {
    case 'daily':
      return 'Every day'
    case 'weekly': {
      const d = DAYS_OF_WEEK.find((x) => x.value === r.dayOfWeek)?.label ?? '—'
      return `Every ${d}`
    }
    case 'monthly':
      return `Day ${r.dayOfMonth ?? '?'} of every month`
    case 'yearly': {
      const m = r.monthOfYear ? MONTHS[r.monthOfYear - 1] : '?'
      return `${m} ${r.dayOfMonth ?? '?'} every year`
    }
  }
}
