import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import App from '../App.vue'
import { useToast } from '@/composables/useToast'
import { resetSeenSession } from '@/composables/seenFlag'
import { useTodayStore } from '@/stores/today'
import type { TodayResponse } from '@/api/types'

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ selectionHaptic: vi.fn() }) }))

const screen = (testid: string) => ({ template: `<section data-testid="${testid}">screen</section>` })

async function mountApp(start: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'landing', component: screen('landing') },
      { path: '/today', name: 'today', component: screen('today'), meta: { tab: 'today' } },
      { path: '/course', name: 'my-course', component: screen('my-course'), meta: { tab: 'courses' } },
      { path: '/courses', name: 'courses', component: screen('courses'), meta: { tab: 'courses' } },
      { path: '/league', name: 'league', component: screen('league'), meta: { tab: 'rating' } },
      { path: '/profile', name: 'profile', component: screen('profile'), meta: { tab: 'profile' } },
      { path: '/training/:id', name: 'training', component: screen('training') },
      { path: '/streak/saved', name: 'streak-saved', component: screen('streak') },
    ],
  })
  await router.push(start)
  const w = mount(App, { global: { plugins: [router, createPinia()] } })
  await flushPromises()
  return { w, router }
}

describe('App shell', () => {
  afterEach(() => useToast().hide())

  it('shows the screen of the route inside <main>', async () => {
    const { w } = await mountApp('/today')

    expect(w.find('main [data-testid=today]').exists()).toBe(true)
  })

  it('shows the tab bar on a screen that belongs to a tab and pads the content under it', async () => {
    const { w } = await mountApp('/today')

    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)
    expect(w.get('[data-testid=app-shell]').classes()).toContain('with-tabbar')
  })

  it.each(['/training/5', '/'])('hides the tab bar on %s (immersive screen / landing)', async (path) => {
    const { w } = await mountApp(path)

    expect(w.find('[data-testid=tab-bar]').exists()).toBe(false)
    expect(w.get('[data-testid=app-shell]').classes()).not.toContain('with-tabbar')
  })

  it('shows and hides the tab bar as the route changes', async () => {
    const { w, router } = await mountApp('/today')
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)

    await router.push('/training/5')
    await flushPromises()
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(false)
    expect(w.find('[data-testid=training]').exists()).toBe(true)

    await router.push('/league')
    await flushPromises()
    expect(w.find('[data-testid=tab-bar]').exists()).toBe(true)
    expect(w.get('[data-testid=tab-rating]').attributes('aria-current')).toBe('page')
  })

  it('renders the toast on every screen', async () => {
    const { w } = await mountApp('/training/5')

    useToast().show('Сохранено', 60_000)
    await nextTick()

    expect(w.get('[data-testid=toast]').text()).toBe('Сохранено')
  })

  describe('the move between screens (ANIMATIONS.md §7)', () => {
    it('does not fade in the first screen of the open', async () => {
      const { w } = await mountApp('/today')

      expect(w.get('main').attributes('data-enter')).toBeUndefined()
    })

    it('crossfades a tab root and fades in an inner screen, only the opacity, and drops the class when it has run', async () => {
      const { w, router } = await mountApp('/today')

      await router.push('/league')
      await flushPromises()
      expect(w.get('main').classes()).toContain('screen-enter-tab')

      await w.get('main').trigger('animationend')
      expect(w.get('main').attributes('data-enter')).toBeUndefined()

      await router.push('/training/5')
      await flushPromises()
      expect(w.get('main').classes()).toContain('screen-enter-inner')
    })

    it('ignores the end of an animation of a child, and a change of the params of the same screen', async () => {
      const { w, router } = await mountApp('/training/4')

      await router.push('/training/5')
      await flushPromises()
      expect(w.get('main').attributes('data-enter')).toBeUndefined()

      await router.push('/today')
      await flushPromises()
      await w.get('[data-testid=today]').trigger('animationend')
      expect(w.get('main').classes()).toContain('screen-enter-tab')
    })

    it('puts no class under prefers-reduced-motion: no animation runs, so none would ever end and take it off', async () => {
      const matchMedia = vi.fn((query: string) => ({ matches: query.includes('reduce'), media: query }))
      vi.stubGlobal('matchMedia', matchMedia)
      try {
        const { w, router } = await mountApp('/today')

        await router.push('/league')
        await flushPromises()
        await router.push('/training/5')
        await flushPromises()

        expect(w.get('main').attributes('data-enter')).toBeUndefined()
        expect(w.get('main').classes().some((name) => name.startsWith('screen-enter'))).toBe(false)
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })

  describe('the screen of the streak (F4, docs/40 §2.5)', () => {
    afterEach(() => {
      window.localStorage.clear()
      resetSeenSession()
    })

    it('opens it by itself from the /today "Today" has loaded, once', async () => {
      const { router } = await mountApp('/today')
      const data = {
        due_count: 0,
        new_words_remaining: null,
        goal: { target: 20, done: 0, met: false },
        streak: {
          current: 12, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true, held_by: null, lost: null,
          week: ['goal', 'goal', 'goal', 'goal', 'freeze', null, null].map((heldBy, index) => ({ date: `2026-11-${23 + index}`, held_by: heldBy, is_today: index === 5 })),
        },
        active_course: null,
        open_session_id: null,
        entitlement: { plan: 'pro', source: 'subscription', until: null, lapsed: null },
        onboarding_completed: true,
        league: null,
        timezone: 'Asia/Tashkent',
      } as TodayResponse

      useTodayStore().data = data
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('streak-saved')

      await router.push('/today')
      useTodayStore().data = { ...data }
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('today')
    })
  })
})
