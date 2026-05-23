import { createRouter, createWebHistory } from 'vue-router'
import LandingView from '../views/LandingView.vue'
import LoginView from '../views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'landing', component: LandingView, meta: { public: true, hideShell: true } },
    { path: '/login', name: 'login', component: LoginView, meta: { public: true, hideShell: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { public: true, hideShell: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/ForgotPasswordView.vue'), meta: { public: true, hideShell: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('../views/ResetPasswordView.vue'), meta: { public: true, hideShell: true } },
    { path: '/setup', name: 'setup', component: () => import('../views/SetupView.vue'), meta: { hideShell: true } },
    { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
    { path: '/accounts', name: 'accounts', component: () => import('../views/AccountsView.vue') },
    { path: '/accounts/:id', name: 'account', component: () => import('../views/AccountView.vue') },
    { path: '/accounts/:accountId/transactions/:id', name: 'transaction', component: () => import('../views/TransactionDetailView.vue') },
    { path: '/assets/:isin', name: 'asset', component: () => import('../views/AssetDetailView.vue') },
    { path: '/tags/:tag', name: 'tag', component: () => import('../views/TagDetailView.vue') },
    { path: '/search', name: 'search', component: () => import('../views/SearchResultsView.vue') },
    { path: '/news', name: 'news', component: () => import('../views/NewsView.vue') },
    { path: '/insights', name: 'insights', component: () => import('../views/InsightsView.vue') },
    { path: '/plan', name: 'plan', component: () => import('../views/PlanView.vue'), meta: { wide: true } },
    { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.fetchMe()
  }

  // Unauthenticated users can't reach private routes.
  if (!to.meta.public && !auth.user) {
    return { name: 'login', query: { next: to.fullPath } }
  }

  if (auth.user) {
    // New users without a base currency must complete setup before the app loads.
    if (!auth.user.baseCurrency && to.name !== 'setup') {
      return { name: 'setup' }
    }
    // Authenticated users skip the auth/landing pages, but can still use /reset-password.
    if (to.name === 'landing' || to.name === 'login' || to.name === 'register' || to.name === 'forgot-password') {
      return { name: 'dashboard' }
    }
  }
})

export default router
