const exponents: Record<string, number> = {
  JPY: 0, KRW: 0, VND: 0,
  KWD: 3, BHD: 3, OMR: 3, TND: 3, JOD: 3,
}

export function exponentOf(currency: string): number {
  return exponents[currency] ?? 2
}

export function formatMinor(amountMinor: string | number, currency: string, locale = 'de-CH'): string {
  const exp = exponentOf(currency)
  const value = Number(amountMinor) / Math.pow(10, exp)
  return new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: exp, maximumFractionDigits: exp }).format(value)
}

/**
 * Compact currency formatter for tight UI spots (summary tiles, chart axis):
 *  - >= 1B  → "1.2B"
 *  - >= 1M  → "5.0M"
 *  - >= 1k  → "12.3k"
 *  -  else  → integer with grouping
 * The currency code is appended without a symbol so it stays narrow and
 * predictable at small font sizes.
 */
export function formatMinorCompact(amountMinor: string | number, currency: string, locale = 'de-CH'): string {
  const exp = exponentOf(currency)
  const value = Number(amountMinor) / Math.pow(10, exp)
  const abs = Math.abs(value)
  const sign = value < 0 ? '-' : ''
  let body: string
  if (abs >= 1_000_000_000) body = `${(abs / 1_000_000_000).toFixed(1)}B`
  else if (abs >= 1_000_000) body = `${(abs / 1_000_000).toFixed(1)}M`
  else if (abs >= 10_000) body = `${(abs / 1_000).toFixed(0)}k`
  else if (abs >= 1_000) body = `${(abs / 1_000).toFixed(1)}k`
  else body = abs.toLocaleString(locale, { maximumFractionDigits: 0 })
  return `${sign}${body} ${currency}`
}

/**
 * Format an asset quantity for display. Stripping the trailing zeros from
 * "10.00000000" → "10" and "1.50000000" → "1.5". Stays exact for fractional
 * quantities like 0.12345678 (up to 8 decimals).
 */
export function formatQuantity(qty: string | number | null | undefined, locale = 'de-CH'): string {
  if (qty === null || qty === undefined || qty === '') return ''
  const n = Number(qty)
  if (!Number.isFinite(n)) return String(qty)
  return n.toLocaleString(locale, { maximumFractionDigits: 8, useGrouping: false })
}

export function parseMajor(input: string, currency: string): string {
  const exp = exponentOf(currency)
  const normalized = input.replace(/[\s']/g, '').replace(',', '.')
  if (!/^-?\d+(\.\d+)?$/.test(normalized)) {
    throw new Error('Invalid amount')
  }
  const parts = normalized.split('.')
  const intPart = parts[0] ?? '0'
  const fracPart = parts[1] ?? ''
  const padded = (fracPart + '0'.repeat(exp)).slice(0, exp)
  const sign = intPart.startsWith('-') ? '-' : ''
  const intDigits = intPart.replace(/^-/, '')
  const combined = (intDigits + padded).replace(/^0+(?=\d)/, '')
  return sign + (combined === '' ? '0' : combined)
}
