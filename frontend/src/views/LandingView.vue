<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import {
  Wallet,
  TrendingUp,
  PieChart,
  ShieldCheck,
  Upload,
  LineChart,
  ArrowRight,
  Sparkles,
  Coins,
  Globe,
  Lock,
  FileJson,
  Github,
  Check,
} from 'lucide-vue-next'

const stats = [
  { value: '3', label: 'Bank importers (ZKB, Degiro, IBKR)' },
  { value: '7+', label: 'Currencies + FX history' },
  { value: 'MIT', label: 'Open source on GitHub' },
  { value: '0', label: 'Trackers, ads or telemetry' },
]

// Reveal-on-scroll: elements with class="reveal" fade up when they enter the viewport.
let observer: IntersectionObserver | null = null
onMounted(() => {
  if (typeof window === 'undefined' || !('IntersectionObserver' in window)) return
  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible')
          observer?.unobserve(entry.target)
        }
      }
    },
    { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
  )
  document.querySelectorAll<HTMLElement>('.reveal').forEach((el) => observer!.observe(el))
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="landing">
    <!-- Decorative background: yellow + violet radial glows.
         Painted absolutely into the hero section only — not fixed across the
         whole page — to avoid per-scroll-frame compositor work that made scroll
         feel laggy. The glow is concentrated where it actually adds visual
         interest (the hero) and lets the rest of the page scroll plain-black. -->
    <div class="hero-glow" aria-hidden="true" />

    <!-- Top bar -->
    <header class="relative z-10 px-6 py-5">
      <div class="mx-auto max-w-6xl flex items-center justify-between">
        <div class="flex items-center gap-2 font-semibold tracking-tight">
          <Wallet :size="20" class="text-[var(--color-accent)]" />
          <span>Treasury</span>
        </div>
        <div class="flex items-center gap-2">
          <RouterLink :to="{ name: 'login' }" class="btn btn-ghost">Sign in</RouterLink>
          <RouterLink :to="{ name: 'register' }" class="btn btn-primary">
            <span>Get started</span>
            <ArrowRight :size="14" />
          </RouterLink>
        </div>
      </div>
    </header>

    <!-- Hero -->
    <section class="relative z-10 px-6 pt-12 sm:pt-20 pb-16 sm:pb-24">
      <div class="mx-auto max-w-5xl text-center space-y-7 reveal">
        <div
          class="inline-flex items-center gap-2 text-xs px-3 py-1.5 rounded-full border"
          style="background-color: color-mix(in srgb, var(--color-accent) 8%, transparent);
                 border-color: color-mix(in srgb, var(--color-accent) 30%, transparent);
                 color: var(--color-accent);"
        >
          <Sparkles :size="12" />
          <span class="font-medium tracking-wide">Personal finance, taken seriously</span>
        </div>

        <h1 class="text-5xl sm:text-6xl lg:text-7xl font-semibold tracking-tight leading-[1.05]">
          One place for every account,
          <br class="hidden sm:block" />
          <span class="text-[var(--color-accent)]">every CHF</span>,
          every gain.
        </h1>

        <p class="text-lg sm:text-xl text-[var(--color-text-muted)] max-w-2xl mx-auto leading-relaxed">
          Treasury pulls your bank exports, broker statements and manual valuables into a single
          tracker. Net worth, performance, allocations — all in one place. Use the hosted
          instance, or run the open-source app on your own server.
        </p>

        <div class="flex flex-wrap justify-center gap-3 pt-3">
          <RouterLink :to="{ name: 'register' }" class="btn btn-primary text-base px-6 py-3">
            <span>Create your account</span>
            <ArrowRight :size="16" />
          </RouterLink>
          <RouterLink :to="{ name: 'login' }" class="btn btn-secondary text-base px-6 py-3">
            I already have an account
          </RouterLink>
        </div>

        <div class="flex items-center justify-center gap-1.5 text-xs text-[var(--color-text-dim)] pt-2">
          <Lock :size="12" />
          <span>Open source · Self-host anytime · No tracking</span>
        </div>
      </div>

      <!-- Screenshot with glow -->
      <div class="mx-auto max-w-6xl mt-16 sm:mt-20 px-2 sm:px-0 reveal delay-2">
        <div class="screenshot-frame">
          <img
            src="/screenshot.png"
            alt="Treasury dashboard"
            class="w-full block rounded-lg"
            width="2880"
            height="1800"
            decoding="async"
            fetchpriority="high"
          />
        </div>
      </div>
    </section>

    <!-- Stats strip -->
    <section class="relative z-10 px-6 py-10">
      <div class="mx-auto max-w-6xl grid grid-cols-2 sm:grid-cols-4 gap-px overflow-hidden rounded-lg reveal"
           style="background-color: var(--color-border);">
        <div
          v-for="s in stats"
          :key="s.label"
          class="px-5 py-6 text-center"
          style="background-color: var(--color-surface);"
        >
          <div class="text-3xl font-semibold tracking-tight tabular text-[var(--color-accent)]">
            {{ s.value }}
          </div>
          <div class="text-xs text-[var(--color-text-muted)] mt-1.5">{{ s.label }}</div>
        </div>
      </div>
    </section>

    <!-- Feature 1: text left, illustration right -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-accent) 12%, transparent)',
                      color: 'var(--color-accent)' }">
            <TrendingUp :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Watch your net worth, <span class="text-[var(--color-accent)]">honestly</span>.
          </h2>
          <!-- (heading already uses solid accent color, no gradient to remove) -->
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Weekly snapshots of cash + holdings across every account, valued in your base
            currency. Pillar 3a, brokerage, crypto, real estate — all on the same chart.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Total view or stacked cash + holdings</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Historical FX so cross-currency portfolios stay honest</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Green for up, red for down — same as you'd expect</span>
            </li>
          </ul>
        </div>

        <!-- Mock chart card -->
        <div class="card p-5 space-y-4 mock-chart">
          <div class="flex items-baseline justify-between">
            <div>
              <p class="label">Net worth</p>
              <p class="text-2xl font-semibold tracking-tight tabular mt-1 text-[var(--color-positive)]">
                +CHF 142'350
              </p>
            </div>
            <div class="flex gap-1">
              <span class="text-xs px-2 py-0.5 rounded text-[var(--color-text-muted)]">1y</span>
              <span class="text-xs px-2 py-0.5 rounded text-[var(--color-text-muted)]">2y</span>
              <span class="text-xs px-2 py-0.5 rounded bg-[var(--color-surface-hover)]">5y</span>
            </div>
          </div>
          <svg viewBox="0 0 400 140" class="w-full h-32" preserveAspectRatio="none">
            <defs>
              <linearGradient id="line-grad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#22c55e" stop-opacity="0.45" />
                <stop offset="100%" stop-color="#22c55e" stop-opacity="0" />
              </linearGradient>
            </defs>
            <path
              d="M 0,110 C 40,95 80,98 120,80 C 160,62 200,72 240,55 C 280,38 320,42 360,28 L 400,18 L 400,140 L 0,140 Z"
              fill="url(#line-grad)"
            />
            <path
              d="M 0,110 C 40,95 80,98 120,80 C 160,62 200,72 240,55 C 280,38 320,42 360,28 L 400,18"
              fill="none"
              stroke="#22c55e"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </div>
      </div>
    </section>

    <!-- Feature 2: illustration left, text right -->
    <section class="relative z-10 px-6 py-20 sm:py-28"
      style="background-color: color-mix(in srgb, var(--color-surface) 60%, transparent);">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <!-- Mock allocation card (placed first in DOM so the order can flip on lg via order utility) -->
        <div class="card p-5 space-y-4 lg:order-1">
          <p class="label">Allocation</p>
          <div class="flex items-center gap-6">
            <svg viewBox="0 0 100 100" class="w-32 h-32 shrink-0">
              <circle cx="50" cy="50" r="38" fill="none" stroke="#facc15" stroke-width="14"
                stroke-dasharray="120 240" stroke-dashoffset="0" transform="rotate(-90 50 50)" />
              <circle cx="50" cy="50" r="38" fill="none" stroke="#a78bfa" stroke-width="14"
                stroke-dasharray="80 240" stroke-dashoffset="-120" transform="rotate(-90 50 50)" />
              <circle cx="50" cy="50" r="38" fill="none" stroke="#22c55e" stroke-width="14"
                stroke-dasharray="50 240" stroke-dashoffset="-200" transform="rotate(-90 50 50)" />
              <circle cx="50" cy="50" r="38" fill="none" stroke="#fb923c" stroke-width="14"
                stroke-dasharray="30 240" stroke-dashoffset="-250" transform="rotate(-90 50 50)" />
            </svg>
            <ul class="flex-1 space-y-1.5 text-sm">
              <li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm" style="background:#facc15"></span><span class="flex-1">Equities</span><span class="tabular text-[var(--color-text-muted)]">50%</span></li>
              <li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm" style="background:#a78bfa"></span><span class="flex-1">Cash</span><span class="tabular text-[var(--color-text-muted)]">33%</span></li>
              <li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm" style="background:#22c55e"></span><span class="flex-1">Gold</span><span class="tabular text-[var(--color-text-muted)]">12%</span></li>
              <li class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm" style="background:#fb923c"></span><span class="flex-1">Crypto</span><span class="tabular text-[var(--color-text-muted)]">5%</span></li>
            </ul>
          </div>
        </div>

        <div class="space-y-5 lg:order-2">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-highlight) 15%, transparent)',
                      color: 'var(--color-highlight)' }">
            <LineChart :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Performance, <span class="text-[var(--color-highlight)]">two honest ways</span>.
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Return-versus-deposits tells you how much your money has grown beyond contributions.
            Time-weighted return strips cashflow timing for an apples-to-apples comparison.
            Toggle between them on every account.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>1-week to all-time ranges</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Per-account or portfolio-wide</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>No survivorship bias — your real numbers, including the bad days</span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- Feature 3: text left, illustration right -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-positive) 15%, transparent)',
                      color: 'var(--color-positive)' }">
            <ShieldCheck :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Hosted or self-hosted.
            <span class="text-[var(--color-positive)]">Your call</span>.
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Sign up on the hosted instance and start tracking in minutes. Or grab the source
            from GitHub and run it on your own VPS — same code, same backup format. Move
            between them any time.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Open source — audit it, fork it, host it</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>One-click JSON export so your data is never trapped</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>No analytics, no telemetry, no third-party trackers</span>
            </li>
          </ul>
        </div>

        <!-- Trust card stack -->
        <div class="grid grid-cols-2 gap-3">
          <div class="card p-5 space-y-2">
            <Lock :size="18" class="text-[var(--color-accent)]" />
            <p class="font-medium text-sm">No tracking</p>
            <p class="text-xs text-[var(--color-text-muted)]">No analytics, ads, or telemetry.</p>
          </div>
          <div class="card p-5 space-y-2">
            <FileJson :size="18" class="text-[var(--color-highlight)]" />
            <p class="font-medium text-sm">JSON backup</p>
            <p class="text-xs text-[var(--color-text-muted)]">Export the entire DB anytime.</p>
          </div>
          <div class="card p-5 space-y-2">
            <Coins :size="18" class="text-[var(--color-accent)]" />
            <p class="font-medium text-sm">Multi-currency</p>
            <p class="text-xs text-[var(--color-text-muted)]">CHF, USD, EUR, GBP, JPY…</p>
          </div>
          <div class="card p-5 space-y-2">
            <Globe :size="18" class="text-[var(--color-highlight)]" />
            <p class="font-medium text-sm">Open source</p>
            <p class="text-xs text-[var(--color-text-muted)]">Self-host anytime, MIT.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- How it works -->
    <section class="relative z-10 px-6 py-20 sm:py-28"
      style="background-color: color-mix(in srgb, var(--color-surface) 60%, transparent);">
      <div class="mx-auto max-w-5xl space-y-10">
        <div class="text-center space-y-3 reveal">
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight">Three steps to set up.</h2>
          <p class="text-[var(--color-text-muted)]">From zero to a full picture of your finances in under ten minutes.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 reveal delay-1">
          <div class="card p-6 space-y-3 relative overflow-hidden">
            <div class="step-number">1</div>
            <div class="flex items-center gap-2">
              <Wallet :size="16" class="text-[var(--color-accent)]" />
              <h3 class="font-medium">Add your accounts</h3>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              Bank, brokerage, 3a, crypto, real estate — pick a type, set the currency, done.
            </p>
          </div>

          <div class="card p-6 space-y-3 relative overflow-hidden">
            <div class="step-number">2</div>
            <div class="flex items-center gap-2">
              <Upload :size="16" class="text-[var(--color-accent)]" />
              <h3 class="font-medium">Import or enter</h3>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              Drop a CSV from your bank, or add transactions manually. Importers auto-detect the format.
            </p>
          </div>

          <div class="card p-6 space-y-3 relative overflow-hidden">
            <div class="step-number">3</div>
            <div class="flex items-center gap-2">
              <PieChart :size="16" class="text-[var(--color-accent)]" />
              <h3 class="font-medium">Watch the picture form</h3>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              Net worth, allocation, performance — all the dashboards populate as your history grows.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Self-host -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-highlight) 15%, transparent)',
                      color: 'var(--color-highlight)' }">
            <Github :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Prefer to <span class="text-[var(--color-highlight)]">self-host</span>?
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Treasury is fully open source. Bring Docker and a MariaDB or MySQL
            database — clone the repo, fill in your secrets, run one command. Use
            the CLI to create users and refresh prices; JSON export gets your data
            out whenever you want.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Runs anywhere Docker runs — VPS, home server, NAS</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>MariaDB 10.6+ or MySQL 8+ for storage</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Migrations run automatically · CLI for users + price refresh</span>
            </li>
          </ul>
          <div class="flex flex-wrap gap-3 pt-2">
            <a
              href="https://github.com/glichfalls/treasury"
              target="_blank"
              rel="noopener"
              class="btn btn-primary text-base px-5 py-2.5"
            >
              <Github :size="16" />
              <span>View on GitHub</span>
              <ArrowRight :size="14" />
            </a>
          </div>
        </div>

        <!-- Mock terminal showing the deploy flow -->
        <div class="card overflow-hidden terminal-mock">
          <div class="flex items-center gap-1.5 px-3 py-2 border-b" style="border-color: var(--color-border);">
            <span class="w-2.5 h-2.5 rounded-full" style="background:#ff5f57"></span>
            <span class="w-2.5 h-2.5 rounded-full" style="background:#febc2e"></span>
            <span class="w-2.5 h-2.5 rounded-full" style="background:#28c840"></span>
            <span class="text-xs text-[var(--color-text-dim)] ml-2 tabular">treasury — bash</span>
          </div>
          <pre class="font-mono text-xs leading-relaxed p-4 overflow-x-auto m-0"><span class="text-[var(--color-text-dim)]"># Clone, configure, run.</span>
<span class="text-[var(--color-accent)]">$</span> git clone https://github.com/glichfalls/treasury
<span class="text-[var(--color-accent)]">$</span> cd treasury
<span class="text-[var(--color-accent)]">$</span> cp .env.prod.example .env.prod.local  <span class="text-[var(--color-text-dim)]"># set DATABASE_URL + APP_SECRET</span>
<span class="text-[var(--color-accent)]">$</span> docker compose -f compose.prod.yaml up -d
<span class="text-[var(--color-positive)]"> ✓</span> <span class="text-[var(--color-text-muted)]">treasury-php-1   Started</span>
<span class="text-[var(--color-positive)]"> ✓</span> <span class="text-[var(--color-text-muted)]">treasury-web-1   Started</span>

<span class="text-[var(--color-text-dim)]"># Create your first user.</span>
<span class="text-[var(--color-accent)]">$</span> docker compose exec php bin/console app:user:create me@example.com
<span class="text-[var(--color-positive)]"> ✓</span> <span class="text-[var(--color-text-muted)]">Created user me@example.com</span>

<span class="text-[var(--color-text-dim)]"># Backfill historical prices for tracked assets.</span>
<span class="text-[var(--color-accent)]">$</span> docker compose exec php bin/console app:prices:backfill</pre>
        </div>
      </div>
    </section>

    <!-- Final CTA -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-3xl text-center space-y-6 cta-card reveal">
        <h2 class="text-3xl sm:text-5xl font-semibold tracking-tight leading-tight">
          Ready to <span class="text-[var(--color-accent)]">take control</span>?
        </h2>
        <p class="text-[var(--color-text-muted)] text-lg">
          Sign in if you've got an account on the hosted instance, or grab the source
          and run it yourself.
        </p>
        <div class="flex flex-wrap justify-center gap-3 pt-2">
          <RouterLink :to="{ name: 'login' }" class="btn btn-primary text-base px-6 py-3">
            <span>Sign in</span>
            <ArrowRight :size="16" />
          </RouterLink>
          <a
            href="https://github.com/glichfalls/treasury"
            target="_blank"
            rel="noopener"
            class="btn btn-secondary text-base px-6 py-3"
          >
            <Github :size="16" />
            <span>Self-host on GitHub</span>
          </a>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="relative z-10 px-6 py-8 border-t" style="border-color: var(--color-border);">
      <div class="mx-auto max-w-6xl flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-[var(--color-text-dim)]">
        <div class="flex items-center gap-2">
          <Wallet :size="14" class="text-[var(--color-accent)]" />
          <span>Treasury &copy; 2026</span>
        </div>
        <span>Personal net-worth tracker · hosted &amp; open-source</span>
        <a
          href="https://github.com/glichfalls/treasury"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1.5 hover:text-[var(--color-text)] transition-colors"
        >
          <Github :size="14" />
          <span>Source</span>
        </a>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.landing {
  position: relative;
  min-height: 100vh;
  overflow-x: hidden;
}

/* Hero glow: absolutely positioned to the top of the page so it scrolls away
   normally — no `position: fixed`, no mask, no animation, no blur. The cheapest
   possible decoration the browser can render. */
.hero-glow {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 80rem;
  pointer-events: none;
  z-index: 0;
  background:
    radial-gradient(40rem 40rem at 15% 10%, rgba(250, 204, 21, 0.22), transparent 65%),
    radial-gradient(35rem 35rem at 85% 20%, rgba(167, 139, 250, 0.20), transparent 65%);
}

/* Reveal-on-scroll. Modest 12px translate + opacity transition, no `will-change`
   so the browser doesn't pre-promote every reveal element to its own compositor
   layer (which was the original cause of scroll input lag). Browsers GPU-
   accelerate transform/opacity transitions on demand. */
.reveal {
  opacity: 0;
  transform: translateY(12px);
  transition:
    opacity 0.4s cubic-bezier(.2,.8,.2,1),
    transform 0.4s cubic-bezier(.2,.8,.2,1);
  transition-delay: 0s;
}
.reveal.is-visible {
  opacity: 1;
  transform: translateY(0);
}
.reveal.delay-1 { transition-delay: 0.08s; }
.reveal.delay-2 { transition-delay: 0.16s; }
.reveal.delay-3 { transition-delay: 0.24s; }

@media (prefers-reduced-motion: reduce) {
  .reveal {
    opacity: 1;
    transform: none;
    transition: none;
  }
}

/* Screenshot frame with a single subtle drop-shadow. Previously had two huge
   blurred shadows (80px and 100px) which the browser has to re-compute on every
   paint — that paint cost during scroll showed up as scroll input lag. */
.screenshot-frame {
  position: relative;
  border-radius: 0.75rem;
  padding: 0.5rem;
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--color-accent) 15%, transparent),
    color-mix(in srgb, var(--color-highlight) 15%, transparent));
  border: 1px solid var(--color-border);
}
.screenshot-frame img {
  border: 1px solid var(--color-border);
}

/* Big numbered corner for "How it works" cards */
.step-number {
  position: absolute;
  top: -0.5rem;
  right: 0.5rem;
  font-size: 5rem;
  font-weight: 700;
  line-height: 1;
  color: color-mix(in srgb, var(--color-accent) 18%, transparent);
  pointer-events: none;
}

/* Terminal-style code block in the self-host section */
.terminal-mock pre {
  background-color: var(--color-bg);
  color: var(--color-text);
}

.cta-card {
  padding: 3rem 2rem;
  border-radius: 1rem;
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--color-accent) 8%, var(--color-surface)),
    color-mix(in srgb, var(--color-highlight) 8%, var(--color-surface)));
  border: 1px solid color-mix(in srgb, var(--color-accent) 25%, var(--color-border));
}

</style>
