import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { installStartParamRedirect } from './startParam'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'landing',
    component: () => import('@/views/LandingView.vue'),
  },
  {
    path: '/today',
    name: 'today',
    component: () => import('@/views/TodayView.vue'),
  },
  {
    path: '/training/:sessionId(\\d+)',
    name: 'training',
    component: () => import('@/views/TrainingView.vue'),
    props: (route) => ({ sessionId: Number(route.params.sessionId) }),
  },
  {
    path: '/finished',
    name: 'finished',
    component: () => import('@/views/FinishedView.vue'),
    props: (route) => ({
      reviewed: Number(route.query.reviewed ?? 0),
      nextInDays: Number(route.query.next ?? 0),
      reason: typeof route.query.reason === 'string' ? route.query.reason : null,
    }),
  },
  {
    path: '/exam/:sessionId(\\d+)',
    name: 'exam',
    component: () => import('@/views/ExamView.vue'),
    props: (route) => ({ sessionId: Number(route.params.sessionId) }),
  },
  {
    path: '/exam/:sessionId(\\d+)/result',
    name: 'exam-result',
    component: () => import('@/views/ExamResultView.vue'),
    props: (route) => ({ sessionId: Number(route.params.sessionId) }),
  },
  {
    path: '/courses',
    name: 'courses',
    component: () => import('@/views/CoursesView.vue'),
  },
  {
    path: '/courses/:slug',
    name: 'course',
    component: () => import('@/views/CourseView.vue'),
    props: (route) => ({ slug: String(route.params.slug) }),
  },
  {
    path: '/decks',
    name: 'decks',
    component: () => import('@/views/MyDecksView.vue'),
  },
  {
    path: '/decks/:slug',
    name: 'deck',
    component: () => import('@/views/DeckEditView.vue'),
    props: (route) => ({ slug: String(route.params.slug) }),
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
  },
  {
    path: '/billing',
    name: 'billing',
    component: () => import('@/views/BillingView.vue'),
  },
  {
    path: '/referrals',
    name: 'referrals',
    component: () => import('@/views/ReferralsView.vue'),
  },
  {
    path: '/league',
    name: 'league',
    component: () => import('@/views/LeagueView.vue'),
  },
  {
    path: '/achievements',
    name: 'achievements',
    component: () => import('@/views/AchievementsView.vue'),
  },
  {
    path: '/friends',
    name: 'friends',
    component: () => import('@/views/FriendsView.vue'),
  },
  // Catch-all -> landing. Telegram sometimes appends junk query params.
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory('/twa/'),
  routes,
})

// Direct links from group chats (`t.me/bot?startapp=…`) carry the target screen in `start_param`.
installStartParamRedirect(router)

export default router
