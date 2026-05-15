// Capture the four landing-page screenshots against a running dev server.
//
// Prereqs:
//  1. Backend + frontend up:  docker compose up -d
//  2. Demo data seeded:       docker compose exec php bin/console app:seed:demo --reset
//  3. Playwright installed:   npm i -D @playwright/test && npx playwright install chromium
//
// Run with:  node scripts/capture-screenshots.mjs
//
// Output goes to public/screenshot{,-performance,-categories,-search}.png at
// 2880×1800 (1440×900 logical viewport × 2× devicePixelRatio for retina-ish).

import { chromium } from 'playwright'
import { mkdirSync, existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const PUBLIC_DIR = resolve(__dirname, '..', 'public')
if (!existsSync(PUBLIC_DIR)) mkdirSync(PUBLIC_DIR, { recursive: true })

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:5173'
const EMAIL = 'demo@treasury.local'
const PASSWORD = 'demo'
const VIEWPORT = { width: 1440, height: 900 }
const DEVICE_SCALE = 2

const log = (msg) => console.log(`[shots] ${msg}`)

async function main() {
  const browser = await chromium.launch({ headless: true })
  const ctx = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: DEVICE_SCALE,
    // Match the user's locale so dates render as Swiss German (matches the UI).
    locale: 'de-CH',
    timezoneId: 'Europe/Zurich',
    // Force the design's color-scheme so charts/text pick the dark theme correctly.
    colorScheme: 'dark',
  })
  const page = await ctx.newPage()
  page.setDefaultTimeout(15000)
  // Surface any console / page errors so we don't debug blind.
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log(`[browser:error] ${msg.text()}`)
  })
  page.on('pageerror', (err) => console.log(`[browser:pageerror] ${err.message}`))
  page.on('response', (res) => {
    if (res.status() >= 400) console.log(`[browser:response] ${res.status()} ${res.url()}`)
  })

  // ── Login ───────────────────────────────────────────────────────────
  log('logging in…')
  await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' })
  log(`landed at: ${page.url()}`)
  log(`page title: ${await page.title()}`)
  // Diagnostic: dump body text if input is missing.
  const hasEmail = await page.locator('input[type=email]').count()
  if (hasEmail === 0) {
    const bodyText = (await page.locator('body').innerText()).slice(0, 400)
    log(`no email input found. Body excerpt:\n${bodyText}`)
    await page.screenshot({ path: resolve(PUBLIC_DIR, '_debug-login.png') })
    throw new Error('Email input not found on /login — see _debug-login.png')
  }
  await page.fill('input[type=email]', EMAIL)
  await page.fill('input[type=password]', PASSWORD)
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/dashboard' || url.pathname === '/'),
    page.click('button[type=submit]'),
  ])

  // Fetch accounts so we know which UUIDs to navigate to.
  const accounts = await page.evaluate(async () => {
    const r = await fetch('/api/accounts', { credentials: 'include' })
    return r.json()
  })
  const byType = (t) => accounts.find((a) => a.type === t)
  const brokerage = byType('brokerage')
  const checking = byType('bank_checking')
  if (!brokerage || !checking) {
    throw new Error('Demo data missing brokerage or checking account — run `app:seed:demo --reset` first.')
  }

  // ── 1. Dashboard hero ───────────────────────────────────────────────
  log('capturing dashboard…')
  await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' })
  // Give ECharts a beat to settle. networkidle alone doesn't wait for the chart.
  await page.waitForTimeout(2500)
  await page.screenshot({ path: resolve(PUBLIC_DIR, 'screenshot.png'), fullPage: false })

  // ── 2. Performance on the brokerage account ─────────────────────────
  log('capturing performance…')
  await page.goto(`${BASE_URL}/accounts/${brokerage.id}`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(2500)
  await page.screenshot({ path: resolve(PUBLIC_DIR, 'screenshot-performance.png'), fullPage: false })

  // ── 3. Cashflow-by-category chart on the dashboard ─────────────────
  // The chart lives below the fold on /dashboard; scroll it to the top of
  // the viewport so the viewport screenshot captures it cleanly.
  log('capturing categories…')
  await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' })
  await page.waitForTimeout(2500)
  // Heading text is stable and unique enough to anchor a scrollIntoView.
  await page.evaluate(() => {
    const target = Array.from(document.querySelectorAll('h3, h2'))
      .find((el) => /cashflow.*categor/i.test(el.textContent || ''))
    if (target) {
      // Scroll so the heading sits ~80px below the sticky header.
      const y = target.getBoundingClientRect().top + window.scrollY - 80
      window.scrollTo({ top: y, behavior: 'instant' })
    }
  })
  await page.waitForTimeout(800)
  await page.screenshot({ path: resolve(PUBLIC_DIR, 'screenshot-categories.png'), fullPage: false })

  // ── 4. Search modal with a query that hits multiple sections ────────
  log('capturing search modal…')
  // Scroll back to top so the modal is centered cleanly over the hero.
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }))
  await page.waitForTimeout(300)
  // Open the modal: Ctrl+K works across all platforms (handler checks both meta + ctrl).
  await page.keyboard.press('Control+K')
  // Wait for the modal input — the placeholder is stable.
  const searchInput = page.locator('input[placeholder*="Search accounts"]')
  await searchInput.waitFor({ state: 'visible' })
  await searchInput.fill('migros')
  // Wait for the "Searching…" placeholder to disappear (results have rendered).
  await page.waitForFunction(() => {
    const txt = document.body.innerText
    return !txt.includes('Searching…') && (txt.includes('TRANSACTIONS') || txt.includes('Transactions') || txt.includes('No matches'))
  }, null, { timeout: 8000 }).catch(() => {})
  await page.waitForTimeout(500)
  await page.screenshot({ path: resolve(PUBLIC_DIR, 'screenshot-search.png'), fullPage: false })

  await browser.close()
  log('done — 4 screenshots in public/')
}

main().catch((err) => {
  console.error('[shots] failed:', err)
  process.exit(1)
})
