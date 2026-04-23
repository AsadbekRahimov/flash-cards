import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'landing',
    component: () => import('@/views/LandingView.vue'),
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
  // Catch-all -> landing. Telegram sometimes appends junk query params.
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory('/twa/'),
  routes,
})

export default router
