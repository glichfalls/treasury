/**
 * Per-account-type feature flags for the AccountView UI.
 *
 *   showPerformance — performance/return chart. Meaningful when the value can
 *                     grow on its own (investments, retirement). Useless for
 *                     bank cash or single-item valuables (cars, houses) where
 *                     "return" is just a manual valuation delta.
 *   showAllocation  — allocation donut. Needs multiple holdings to make sense.
 *   showHoldings    — holdings tab. Same as above — needs asset positions.
 *   showRecurring   — recurring-transaction tab. Useful for accounts with
 *                     scheduled cash flows (rent, salary, mortgage). Less so
 *                     for investment accounts where trades are one-off.
 */

export interface AccountFeatures {
  showPerformance: boolean
  showAllocation: boolean
  showHoldings: boolean
  showRecurring: boolean
  /** Spending categories only make sense for cash flow (banks, credit cards, cash). */
  showCategories: boolean
}

// Investment-style accounts: charts + holdings, no recurring, no categories
// (trades aren't spending).
const INVESTMENT: AccountFeatures = {
  showPerformance: true,
  showAllocation: true,
  showHoldings: true,
  showRecurring: false,
  showCategories: false,
}

// Cash-style accounts: transactions, recurring, AND categories.
const CASH: AccountFeatures = {
  showPerformance: false,
  showAllocation: false,
  showHoldings: false,
  showRecurring: true,
  showCategories: true,
}

// Single-item valuables: just track value over time, nothing else applies.
const VALUABLE: AccountFeatures = {
  showPerformance: false,
  showAllocation: false,
  showHoldings: false,
  showRecurring: false,
  showCategories: false,
}

// Pillar 3a is an investment account with regular contributions, but trades
// aren't categorized as "groceries" — keep categories off.
const PILLAR_3A: AccountFeatures = {
  showPerformance: true,
  showAllocation: true,
  showHoldings: true,
  showRecurring: true,
  showCategories: false,
}

const TYPE_MAP: Record<string, AccountFeatures> = {
  bank_checking: CASH,
  bank_savings: CASH,
  cash: CASH,
  credit_card: CASH,
  brokerage: INVESTMENT,
  crypto_exchange: INVESTMENT,
  crypto_wallet: INVESTMENT,
  precious_metals: INVESTMENT,
  real_estate: VALUABLE,
  vehicle: VALUABLE,
  pillar_3a: PILLAR_3A,
}

// Unknown / 'other' falls through to everything-on so we don't accidentally hide a feature.
const DEFAULT: AccountFeatures = {
  showPerformance: true,
  showAllocation: true,
  showHoldings: true,
  showRecurring: true,
  showCategories: true,
}

export function featuresFor(accountType: string): AccountFeatures {
  return TYPE_MAP[accountType] ?? DEFAULT
}
