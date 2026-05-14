import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import LoginView from '../views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/', name: 'home', component: HomeView },
    { path: '/accounts/:id', name: 'account', component: () => import('../views/AccountView.vue') },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.fetchMe()
  }
  if (!to.meta.public && !auth.user) {
    return { name: 'login', query: { next: to.fullPath } }
  }
  if (to.name === 'login' && auth.user) {
    return { name: 'home' }
  }
})

export default router
