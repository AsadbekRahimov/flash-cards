import { createRouter, createWebHistory, type RouteRecordRaw, type RouterScrollBehavior } from 'vue-router'
import { installStartParamRedirect } from './startParam'

/** The tabs of the bottom navigation (docs/14 §5.1). */
export type TabId = 'today' | 'courses' | 'rating' | 'profile'

declare module 'vue-router' {
  interface RouteMeta {
    /**
     * The tab this screen belongs to: it is highlighted in the tab bar, and the bar is shown only for routes that have
     * one. Absent on immersive screens (training, exam, finished) and the landing.
     */
    tab?: TabId
  }
}

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
    meta: { tab: 'today' },
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
    meta: { tab: 'courses' },
  },
  {
    path: '/courses/:slug',
    name: 'course',
    component: () => import('@/views/CourseView.vue'),
    meta: { tab: 'courses' },
    props: (route) => ({ slug: String(route.params.slug) }),
  },
  {
    path: '/decks',
    name: 'decks',
    component: () => import('@/views/MyDecksView.vue'),
    meta: { tab: 'courses' },
  },
  {
    path: '/decks/:slug',
    name: 'deck',
    component: () => import('@/views/DeckEditView.vue'),
    meta: { tab: 'courses' },
    props: (route) => ({ slug: String(route.params.slug) }),
  },
  {
    path: '/profile',
    name: 'profile',
    component: () => import('@/views/ProfileView.vue'),
    meta: { tab: 'profile' },
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
    meta: { tab: 'profile' },
  },
  {
    path: '/billing',
    name: 'billing',
    component: () => import('@/views/BillingView.vue'),
    meta: { tab: 'profile' },
  },
  {
    path: '/referrals',
    name: 'referrals',
    component: () => import('@/views/ReferralsView.vue'),
    meta: { tab: 'profile' },
  },
  {
    path: '/league',
    name: 'league',
    component: () => import('@/views/LeagueView.vue'),
    meta: { tab: 'rating' },
  },
  {
    path: '/achievements',
    name: 'achievements',
    component: () => import('@/views/AchievementsView.vue'),
    meta: { tab: 'profile' },
  },
  {
    path: '/friends',
    name: 'friends',
    component: () => import('@/views/FriendsView.vue'),
    meta: { tab: 'rating' },
  },
  // Catch-all -> landing. Telegram sometimes appends junk query params.
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

/** How long a screen that is returned to may take to fill in before its saved scroll position is restored anyway. */
export const RESTORE_SCROLL_WAIT_MS = 1000
const RESTORE_SCROLL_POLL_MS = 50

/** Counts the navigations: a wait for the page to fill in gives up as soon as a newer navigation has begun. */
let navigationSeq = 0

/**
 * Where a navigation leaves the scroll (docs/14 §5.3). The whole page scrolls (`window`: the shell, `.page` and the tab
 * bar have no scroll container of their own), so one rule serves every screen:
 * - a new screen (a tab, a course, "Start", the finish screen) opens from the top, not on the offset of the screen before;
 * - going back to a screen (browser history, or `router.back()`, which the system "Back" button calls when the screen
 *   before this one is its target) returns to where the learner was. The screen fills in after the router is done (it
 *   fetches its data on mount), so the position is applied once the page is tall enough for it, for a second at most.
 */
export const scrollBehavior: RouterScrollBehavior = (_to, _from, savedPosition) => {
  const seq = ++navigationSeq
  if (!savedPosition) return { left: 0, top: 0 }
  if (savedPosition.top === 0) return savedPosition

  return new Promise((resolve) => {
    const startedAt = Date.now()
    const settle = (): void => {
      if (seq !== navigationSeq) return resolve(false)
      const room = document.documentElement.scrollHeight - window.innerHeight
      if (room >= savedPosition.top || Date.now() - startedAt >= RESTORE_SCROLL_WAIT_MS) return resolve(savedPosition)
      setTimeout(settle, RESTORE_SCROLL_POLL_MS)
    }
    // Not at once: a screen that is returned to first shows what it still has (a whole page), then starts loading
    // again (a skeleton), and only then fills in; the first look would find the page tall for a moment.
    setTimeout(settle, RESTORE_SCROLL_POLL_MS)
  })
}

const router = createRouter({
  history: createWebHistory('/twa/'),
  routes,
  scrollBehavior,
})

// Direct links from group chats (`t.me/bot?startapp=…`) carry the target screen in `start_param`.
installStartParamRedirect(router)

export default router
