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
    { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
    { path: '/accounts', name: 'accounts', component: () => import('../views/AccountsView.vue') },
    { path: '/accounts/:id', name: 'account', component: () => import('../views/AccountView.vue') },
    { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.fetchMe()
  }
  // Authenticated users skip the landing/login/register pages.
  if (auth.user && (to.name === 'landing' || to.name === 'login' || to.name === 'register')) {
    return { name: 'dashboard' }
  }
  if (!to.meta.public && !auth.user) {
    return { name: 'login', query: { next: to.fullPath } }
  }
})

export default router
