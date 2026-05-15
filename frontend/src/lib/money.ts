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
