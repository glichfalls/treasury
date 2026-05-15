// One-shot Playwright script: log in as the demo user and capture the dashboard.
// Run via the playwright Docker image attached to the treasury network so it can
// reach the Vite container at http://frontend:5173.
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2, // crisp on retina-style displays
  });
  const page = await ctx.newPage();

  page.on('console', (msg) => console.log('[page]', msg.type(), msg.text()));
  page.on('pageerror', (err) => console.log('[pageerror]', err.message));
  page.on('requestfailed', (req) => console.log('[reqfail]', req.url(), req.failure()?.errorText));

  const response = await page.goto('http://frontend:5173/login', { waitUntil: 'networkidle' });
  console.log('status', response?.status(), 'url', page.url());
  console.log('title', await page.title());
  console.log('html length', (await page.content()).length);
  await page.screenshot({ path: '/work/debug.png', fullPage: true });

  await page.locator('input[type=email]').fill('demo@treasury.local');
  await page.locator('input[type=password]').fill('demo');
  await page.locator('button[type=submit]').click();

  // After login the router redirects to /dashboard.
  await page.waitForURL('**/dashboard');
  await page.waitForSelector('canvas', { state: 'visible', timeout: 20000 });
  // Give ECharts a beat to finish animating in.
  await page.waitForTimeout(1800);

  // Hide the floating vue-devtools panel so it doesn't appear in the screenshot.
  await page.addStyleTag({ content: `
    [data-v-devtools-anchor],
    div[id^="__vue-devtools-container__"],
    div[id^="__vue-inspector-container__"],
    iframe[id^="__vue-devtools"] { display: none !important; }
  ` });

  // Viewport-only screenshot (not fullPage) — the landing page hero looks better
  // with a fixed-aspect-ratio image rather than a tall scrolled-out dump.
  await page.screenshot({ path: '/work/screenshot.png', fullPage: false });

  await browser.close();
  console.log('saved /work/screenshot.png');
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
