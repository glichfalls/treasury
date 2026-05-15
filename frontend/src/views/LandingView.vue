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
  Tag as TagIcon,
  Repeat,
  Target,
  Search,
} from 'lucide-vue-next'
import BrandMark from '@/components/BrandMark.vue'

const stats = [
  { value: '12', label: 'Account types (bank, broker, crypto, 3a, gold, …)' },
  { value: '3', label: 'CSV importers (ZKB, Degiro, IBKR)' },
  { value: 'FX', label: 'Historical rates for honest totals' },
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
    <!-- Hero glow — yellow only (brand palette is yellow-on-black). -->
    <div class="hero-glow" aria-hidden="true" />

    <!-- Top bar -->
    <header class="relative z-10 px-6 py-5">
      <div class="mx-auto max-w-6xl flex items-center justify-between">
        <div class="flex items-center gap-2 font-semibold tracking-tight">
          <BrandMark :size="24" />
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
          Net worth, performance, cashflow categories, tags, recurring rules and a retirement
          projection — all from your bank exports and a handful of manual entries. Use the
          hosted instance, or self-host the open-source app.
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

      <!-- Hero screenshot -->
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

    <!-- Feature 1: Net worth — text left, inline mock right -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg feature-badge">
            <TrendingUp :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Watch your net worth, <span class="text-[var(--color-accent)]">honestly</span>.
          </h2>
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
              <span>Per-account-type UI — no performance charts on a checking account</span>
            </li>
          </ul>
        </div>

        <!-- Inline mock chart (kept as SVG — no screenshot needed for an abstract example) -->
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

    <!-- Feature 2: Performance — screenshot left, text right -->
    <section class="relative z-10 px-6 py-20 sm:py-28"
      style="background-color: color-mix(in srgb, var(--color-surface) 60%, transparent);">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="lg:order-1 inline-screenshot">
          <img
            src="/screenshot-performance.png"
            alt="Performance comparison on an investment account"
            class="w-full block rounded-lg"
            loading="lazy"
            decoding="async"
          />
        </div>

        <div class="space-y-5 lg:order-2">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg feature-badge">
            <LineChart :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Performance, <span class="text-[var(--color-accent)]">two honest ways</span>.
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

    <!-- Feature 3: Categorize + tag — text left, screenshot right -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg feature-badge">
            <TagIcon :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Categorise, tag, <span class="text-[var(--color-accent)]">find the pattern</span>.
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Categories slot every banking transaction into "groceries", "rent", "subscriptions" —
            monthly cashflow charts emerge automatically. Tags add free-form grouping that cuts
            across categories: <em>netflix</em>, <em>annual-fees</em>, <em>kids</em>. Tag once,
            Treasury applies it to everything matching.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Auto-tagging — type "netflix" once, every Netflix charge gets tagged</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Tag detail pages with monthly totals + signed net</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Cashflow-by-category chart per banking account</span>
            </li>
          </ul>
        </div>

        <div class="inline-screenshot">
          <img
            src="/screenshot-categories.png"
            alt="Cashflow by category and tag totals"
            class="w-full block rounded-lg"
            loading="lazy"
            decoding="async"
          />
        </div>
      </div>
    </section>

    <!-- Feature 4: Find anything — screenshot left, text right -->
    <section class="relative z-10 px-6 py-20 sm:py-28"
      style="background-color: color-mix(in srgb, var(--color-surface) 60%, transparent);">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="lg:order-1 inline-screenshot">
          <img
            src="/screenshot-search.png"
            alt="Global search modal with grouped results"
            class="w-full block rounded-lg"
            loading="lazy"
            decoding="async"
          />
        </div>

        <div class="space-y-5 lg:order-2">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg feature-badge">
            <Search :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Find anything, <span class="text-[var(--color-accent)]">in milliseconds</span>.
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Hit ⌘K from anywhere. Search spans accounts, transactions, assets, tags and recurring
            rules. The dedicated results page adds filters (account, date, type) and surfaces
            spending stats for the query — like a tag detail page, but for any text.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Command-palette modal with grouped sections + "View all"</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Spending stats per query — net, spent, received, monthly</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Filter by account, date range, transaction type</span>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- Compact "everything else" feature grid -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl space-y-10">
        <div class="text-center space-y-3 reveal">
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight">Plus everything you'd expect.</h2>
          <p class="text-[var(--color-text-muted)]">The pieces that make the whole thing work.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 reveal delay-1">
          <div class="card p-5 space-y-3">
            <div class="feature-pill"><Repeat :size="18" /></div>
            <h3 class="font-medium">Recurring transactions</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              Rent, subscriptions, paychecks — define them once and Treasury materialises them on schedule.
            </p>
          </div>

          <div class="card p-5 space-y-3">
            <div class="feature-pill"><Target :size="18" /></div>
            <h3 class="font-medium">Retirement projection</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              Set savings rate, return and horizon. See the curve. Stress-test before you commit.
            </p>
          </div>

          <div class="card p-5 space-y-3">
            <div class="feature-pill"><Coins :size="18" /></div>
            <h3 class="font-medium">Multi-currency, properly</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              Pick a base currency. Every transaction converts at its historical FX. No fake totals.
            </p>
          </div>

          <div class="card p-5 space-y-3">
            <div class="feature-pill"><Upload :size="18" /></div>
            <h3 class="font-medium">CSV importers</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              Drop a CSV from ZKB, Degiro, or IBKR. Auto-detected format, deduped on external ref.
            </p>
          </div>

          <div class="card p-5 space-y-3">
            <div class="feature-pill"><FileJson :size="18" /></div>
            <h3 class="font-medium">JSON backup &amp; restore</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              Export the entire database to JSON. Import it back into any Treasury instance.
            </p>
          </div>

          <div class="card p-5 space-y-3">
            <div class="feature-pill"><Lock :size="18" /></div>
            <h3 class="font-medium">No tracking</h3>
            <p class="text-sm text-[var(--color-text-muted)]">
              No analytics, no ads, no third-party telemetry. Your data leaves only when you export it.
            </p>
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
              Bank, brokerage, 3a, crypto, real estate, gold — pick a type, set the currency, done.
            </p>
          </div>

          <div class="card p-6 space-y-3 relative overflow-hidden">
            <div class="step-number">2</div>
            <div class="flex items-center gap-2">
              <Upload :size="16" class="text-[var(--color-accent)]" />
              <h3 class="font-medium">Import or enter</h3>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              Drop a CSV from your bank, or add transactions manually. Auto-tagging fills in the rest.
            </p>
          </div>

          <div class="card p-6 space-y-3 relative overflow-hidden">
            <div class="step-number">3</div>
            <div class="flex items-center gap-2">
              <PieChart :size="16" class="text-[var(--color-accent)]" />
              <h3 class="font-medium">Plan the next move</h3>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              Net worth, allocation, performance, projection — every dashboard populates as your history grows.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Self-host -->
    <section class="relative z-10 px-6 py-20 sm:py-28">
      <div class="mx-auto max-w-6xl grid grid-cols-1 lg:grid-cols-2 gap-12 items-center reveal">
        <div class="space-y-5">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg feature-badge">
            <Github :size="22" />
          </div>
          <h2 class="text-3xl sm:text-4xl font-semibold tracking-tight leading-tight">
            Hosted or <span class="text-[var(--color-accent)]">self-hosted</span>. Your call.
          </h2>
          <p class="text-[var(--color-text-muted)] leading-relaxed">
            Sign up on the hosted instance and start tracking in minutes. Or grab the source
            from GitHub, bring Docker + MariaDB or MySQL, and run one command. Same code,
            same backup format — move between them any time.
          </p>
          <ul class="space-y-2.5 text-sm">
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Runs anywhere Docker runs — VPS, home server, NAS</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>Migrations run automatically on boot · CLI for users + price refresh</span>
            </li>
            <li class="flex items-start gap-2.5">
              <Check :size="16" class="mt-0.5 shrink-0 text-[var(--color-positive)]" />
              <span>MIT licence — audit it, fork it, host it</span>
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
          <BrandMark :size="16" />
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

/* Yellow-only hero glow (brand palette is yellow on black — no violet). */
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
    radial-gradient(35rem 35rem at 85% 20%, rgba(250, 204, 21, 0.12), transparent 65%);
}

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

/* Yellow-on-yellow frame for hero screenshot. */
.screenshot-frame {
  position: relative;
  border-radius: 0.75rem;
  padding: 0.5rem;
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--color-accent) 18%, transparent),
    color-mix(in srgb, var(--color-accent) 6%, transparent));
  border: 1px solid var(--color-border);
}
.screenshot-frame img {
  border: 1px solid var(--color-border);
}

/* In-feature screenshots: slightly subtler frame so the screenshot itself is the focus. */
.inline-screenshot {
  position: relative;
  border-radius: 0.5rem;
  padding: 0.375rem;
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--color-accent) 12%, transparent),
    transparent);
  border: 1px solid var(--color-border);
}
.inline-screenshot img {
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
}

/* Yellow-tinted badge sitting above feature section headings. */
.feature-badge {
  background-color: color-mix(in srgb, var(--color-accent) 12%, transparent);
  color: var(--color-accent);
}

/* Compact icon pill used in the feature grid cards. */
.feature-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  background-color: color-mix(in srgb, var(--color-accent) 14%, transparent);
  color: var(--color-accent);
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

.terminal-mock pre {
  background-color: var(--color-bg);
  color: var(--color-text);
}

.cta-card {
  padding: 3rem 2rem;
  border-radius: 1rem;
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--color-accent) 10%, var(--color-surface)),
    color-mix(in srgb, var(--color-accent) 4%, var(--color-surface)));
  border: 1px solid color-mix(in srgb, var(--color-accent) 25%, var(--color-border));
}
</style>
