import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import TodayView from '../TodayView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { ICONS } from '@/components/ui/icons'
import { setLocale } from '@/i18n'
import { resetSeenSession } from '@/composables/seenFlag'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { ASSIGNMENTS_POSSIBLE_KEY } from '@/stores/assignments'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { AssignmentItem, MeResponse, TodayResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  learner: null as { first_name: string | null } | null,
  useBackButton: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn(), updateSettings: vi.fn() } } }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: state.ensure,
    get learner() {
      return state.learner
    },
  }),
}))

vi.mock('@/composables/useBackButton', () => ({ useBackButton: state.useBackButton }))

const telegram = vi.hoisted(() => ({ close: vi.fn() }))
vi.mock('@/composables/useTelegram', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/composables/useTelegram')>()
  return { ...actual, useTelegram: () => ({ ...actual.useTelegram(), close: telegram.close }) }
})

const today = (overrides: Partial<TodayResponse> = {}): TodayResponse => ({
  due_count: 23,
  new_words_remaining: null,
  goal: { target: 20, done: 8, met: false },
  streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  active_course: {
    id: 3,
    slug: 'english-a1',
    title: 'English A1',
    progress: { learned: 120, total: 900 },
    next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' },
  },
  open_session_id: null,
  entitlement: { plan: 'free', source: 'free', until: null },
  onboarding_completed: true,
  league: null,
  timezone: 'Asia/Tashkent',
  ...overrides,
})

const PRO = { plan: 'pro', source: 'subscription', until: null } as const

/** `practice` of `/today` (docs/04 A.2a): what the "Word games" card reads, so that Home asks no `/me` for it. */
const practiceBlock = (overrides: Record<string, unknown> = {}) =>
  ({ practice: { available: true, pro_required: false, modes: ['pairs', 'collocation', 'sentence', 'dictation'], battle: false, pro_promise: 'none', ...overrides } }) as Pick<TodayResponse, 'practice'>

const assignment = (overrides: Partial<AssignmentItem> = {}): AssignmentItem => ({
  id: 7,
  title: 'Food',
  group: 'Group A',
  lesson_id: 41,
  stage: 2,
  lesson: 4,
  due_at: '2026-10-25T13:00:00+00:00',
  overdue: false,
  progress_pct: 40,
  completed: false,
  completed_at: null,
  late: false,
  ...overrides,
})

const makeRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/courses', name: 'courses', component: { template: '<div />' } },
      { path: '/billing', name: 'billing', component: { template: '<div />' } },
      { path: '/league', name: 'league', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/game', name: 'game', component: { template: '<div />' } },
      { path: '/game/daily', name: 'game-daily', component: { template: '<div />' } },
      { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    ],
  })

async function render(response: TodayResponse = today()) {
  vi.mocked(api.today.get).mockResolvedValue(response)
  const router = makeRouter()
  const wrapper = mount(TodayView, { global: { plugins: [router] } })
  await flushPromises()
  return Object.assign(wrapper, { router })
}

const text = (w: Awaited<ReturnType<typeof render>>, id: string): string => w.get(`[data-testid=${id}]`).text()

const rateLimited = () => new ApiClientError('rate_limited', 429, 'unknown', 'Too Many Attempts.')

describe('TodayView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.localStorage.clear()
    resetSeenSession()
    state.ensure.mockResolvedValue(undefined)
    state.learner = null
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
    // The "Pro ended" banner asks /me for a Free learner; by default nothing lapsed.
    vi.mocked(api.me.get).mockResolvedValue({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
    } as never)
  })
  afterEach(() => {
    setLocale('ru')
    vi.useRealTimers()
  })

  describe('language button', () => {
    const meWithSettings = (locale: 'ru' | 'uz' = 'ru'): MeResponse => ({
      learner: { id: 1, first_name: 'A', username: null, locale, onboarding_completed: true },
      groups: [],
      stats: { words_learned: 0, words_due_today: 0 },
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
      paywall: { mode: 'coming_soon', contact: null },
      settings: settingsFixture({ interface_locale: locale }),
    })

    it('is the pill of the brand row of the header (mock-up 2026-11-25), the only language button of the screen', async () => {
      const w = await render()

      expect(w.findAll('[data-testid=lang-btn]')).toHaveLength(1)
      const btn = w.get('[data-testid=lang-btn]')
      expect(btn.text()).toBe('RU')
      expect(btn.attributes('aria-label')).toBe('Til · Язык')
      expect(btn.classes()).toContain('tap-target')
      expect(w.get('[data-testid=brand-row]').element.contains(btn.element)).toBe(true)
      // the plan badge stays next to the greeting
      expect(w.get('[data-testid=brand-row]').element.contains(w.get('[data-testid=plan-badge]').element)).toBe(false)
    })

    it('shows UZ in Uzbek', async () => {
      setLocale('uz')
      const w = await render()

      expect(w.get('[data-testid=lang-btn]').text()).toBe('UZ')
    })

    it('opens the language sheet in one tap, also for a newcomer without a course, and closes it again', async () => {
      vi.mocked(api.me.get).mockResolvedValue(meWithSettings())
      const w = await render(today({ active_course: null, due_count: 0 }))
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)

      await w.get('[data-testid=lang-btn]').trigger('click')
      await flushPromises()

      expect(w.get('[data-testid=language-title]').text()).toBe('Til · Язык')
      expect(w.get('[data-testid=language-ru]').attributes('aria-checked')).toBe('true')
      await w.get('[data-testid=sheet-x]').trigger('click')
      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
    })

    it('switches the whole screen when another language is chosen', async () => {
      vi.mocked(api.me.get).mockResolvedValue(meWithSettings())
      vi.mocked(api.me.updateSettings).mockResolvedValue(meWithSettings('uz'))
      const w = await render(today({ active_course: null, due_count: 0 }))

      await w.get('[data-testid=lang-btn]').trigger('click')
      await flushPromises()
      await w.get('[data-testid=language-uz]').trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=language-sheet]').exists()).toBe(false)
      expect(w.get('[data-testid=lang-btn]').text()).toBe('UZ')
      expect(w.get('[data-testid=start-card] h2').text()).toBe('Keling, boshlaymiz!')
    })
  })

  describe('header', () => {
    it('greets the learner by name with the date and the course over it', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-28T07:00:00Z')) // noon in Tashkent, the zone of the fixture
      state.learner = { first_name: ' Асадбек ' }

      const w = await render()

      expect(w.get('h1').text()).toBe('Привет, Асадбек!')
      expect(w.get('[data-testid=eyebrow]').text()).toBe('Среда, 28 октября · English A1')
      // the line is over the greeting (mock-up Main), not under it
      expect(w.find('header [data-testid=subtitle]').exists()).toBe(false)
    })

    it('has only the date over the greeting without a course', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-28T07:00:00Z'))

      const w = await render(today({ active_course: null, due_count: 3 }))

      expect(w.get('[data-testid=eyebrow]').text()).toBe('Среда, 28 октября')
    })

    describe('the date over the greeting', () => {
      it('is the date in the time zone of the learner that /today names: the day of the goal below is counted there', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        // 01:30 on the 29th in Tashkent, 09:30 on the 28th in Pago Pago: whatever the device zone is, one of them differs from it.
        vi.setSystemTime(new Date('2026-10-28T20:30:00Z'))

        const tashkent = await render(today({ timezone: 'Asia/Tashkent' }))
        expect(tashkent.get('[data-testid=eyebrow]').text()).toBe('Четверг, 29 октября · English A1')

        const samoa = await render(today({ timezone: 'Pacific/Pago_Pago' }))
        expect(samoa.get('[data-testid=eyebrow]').text()).toBe('Среда, 28 октября · English A1')
      })

      it('is already the next day for a learner in Tashkent at 20:30 UTC, while a device in UTC is still on the 28th', async () => {
        const deviceZone = process.env.TZ
        process.env.TZ = 'UTC' // the device
        try {
          vi.useFakeTimers({ toFake: ['Date'] })
          vi.setSystemTime(new Date('2026-10-28T20:30:00Z'))
          expect(new Date().getDate()).toBe(28) // the device's own day: the 29th below can only come from the learner's zone

          const w = await render(today({ timezone: 'Asia/Tashkent' }))

          expect(w.get('[data-testid=eyebrow]').text()).toBe('Четверг, 29 октября · English A1')
        } finally {
          if (deviceZone === undefined) delete process.env.TZ
          else process.env.TZ = deviceZone
        }
      })

      it('keeps the date of the device when the browser does not know the zone, or an older server sent none', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date(2026, 9, 28, 12, 0, 0))

        expect((await render(today({ timezone: 'Mars/Olympus' }))).get('[data-testid=eyebrow]').text()).toBe('Среда, 28 октября · English A1')
        expect((await render(today({ timezone: undefined as unknown as string }))).get('[data-testid=eyebrow]').text()).toBe('Среда, 28 октября · English A1')
      })
    })

    it('says "Today" when the learner has no name', async () => {
      state.learner = { first_name: null }
      expect((await render()).get('h1').text()).toBe('Сегодня')

      state.learner = { first_name: '   ' }
      expect((await render()).get('h1').text()).toBe('Сегодня')
    })

    it('badges the plan with a chip that leads to the subscription', async () => {
      const free = await render()
      expect(text(free, 'plan-badge')).toBe('Free')
      expect(free.get('[data-testid=plan-badge]').attributes('href')).toBe('/billing')
      expect(free.get('[data-testid=plan-badge] .chip').classes()).toContain('chip-neutral')

      const pro = await render(today({ entitlement: PRO }))
      expect(text(pro, 'plan-badge')).toBe('Pro')
      expect(pro.get('[data-testid=plan-badge] .chip').classes()).toContain('chip-accent')
    })

    it('takes the plan only from the entitlement: a Free learner without a quota number is still Free', async () => {
      const w = await render(today({ new_words_remaining: null, entitlement: { plan: 'free', source: 'free', until: null } }))

      expect(text(w, 'plan-badge')).toBe('Free')
      expect(w.get('[data-testid=plan-badge] .chip').classes()).toContain('chip-neutral')
    })

    it('is a tab root: no system "Back" button', async () => {
      await render()

      expect(state.useBackButton).toHaveBeenCalledWith(null)
    })
  })

  describe('session card', () => {
    it('shows the goal as a big number, "of N words · M left" and a thin line (mock-up Main), not a ring', async () => {
      const w = await render()

      expect(w.find('[data-testid=goal-card]').exists()).toBe(true)
      expect(w.find('[data-testid=goal-ring]').exists()).toBe(false)
      expect(text(w, 'goal-done')).toBe('8')
      expect(w.get('[data-testid=goal-done]').classes()).toContain('font-mono')
      expect(text(w, 'goal-text')).toBe('из 20 слов')
      expect(text(w, 'goal-left')).toBe('осталось 12')
      // 8/20 of the line is drawn, and it is named for assistive tech.
      const line = w.get('[data-testid=goal-line]')
      expect(line.attributes('role')).toBe('progressbar')
      expect(line.attributes('aria-valuenow')).toBe('40')
      expect(line.attributes('aria-label')).toBe('8 из 20 слов')
      expect(line.get('.progress-fill').attributes('style')).toContain('width: 40%')
      expect(w.find('[data-testid=goal-met]').exists()).toBe(false)
    })

    it('says "of N words" in the right Russian form', async () => {
      expect(text(await render(today({ goal: { target: 1, done: 0, met: false } })), 'goal-text')).toBe('из 1 слова')
      expect(text(await render(today({ goal: { target: 21, done: 0, met: false } })), 'goal-text')).toBe('из 21 слова')
      expect(text(await render(today({ goal: { target: 10, done: 0, met: false } })), 'goal-text')).toBe('из 10 слов')
    })

    it('opens goal settings from the daily goal heading in either language', async () => {
      const link = (await render()).get('[data-testid=goal-setting]')
      expect(link.text()).toContain('Цель дня')
      expect(link.get('.sr-only').text()).toBe('Изменить цель')
      expect(link.attributes('href')).toBe('/settings')
      setLocale('uz')
      expect((await render()).get('[data-testid=goal-setting] .sr-only').text()).toBe("Maqsadni o'zgartirish")
    })

    it('shows a met goal with a full green line even past the target', async () => {
      const w = await render(today({ goal: { target: 20, done: 25, met: true } }))

      expect(text(w, 'goal-met')).toBe('Цель выполнена')
      expect(w.get('[data-testid=goal-met]').classes()).toContain('text-success-ink')
      expect(w.find('[data-testid=goal-left]').exists()).toBe(false)
      const line = w.get('[data-testid=goal-line]')
      expect(line.attributes('aria-valuenow')).toBe('100')
      expect(line.get('.progress-fill').classes()).toContain('progress-success')
    })

    describe('the "Word games" card (docs/16 §11.1)', () => {
      const course = (learned: number) => ({
        active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned, total: 900 }, next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' } },
      })

      it('sits under the session card with three modes and one button that leads to the hub of the active course', async () => {
        const w = await render(today(practiceBlock()))

        const card = w.get('[data-testid=games-card]')
        expect(card.text()).toContain('Игры со словами')
        // the modes by their names (no coloured tiles): three of them
        expect(text(w, 'games-modes').split(' · ')).toHaveLength(3)
        expect(card.get('.icon-tile svg').attributes('aria-hidden')).toBe('true')
        // a row of the list "For today", the whole row is the button
        expect(w.find('[data-testid=today-list] [data-testid=games-card]').exists()).toBe(true)
        expect(w.get('[data-testid=games-play-btn]').text()).toBe('Играть')
        // under the session card, not above it
        const order = w.html().indexOf('data-testid="review-card"') < w.html().indexOf('data-testid="games-card"')
        expect(order).toBe(true)
        // the one primary button of Home is still the session one
        expect(w.findAll('.btn-primary')).toHaveLength(1)

        await w.get('[data-testid=games-play-btn]').trigger('click')
        await flushPromises()
        expect(w.router.currentRoute.value.name).toBe('practice-hub')
        expect(w.router.currentRoute.value.params.slug).toBe('english-a1')
      })

      it('takes the access from /today and never reads /me for it', async () => {
        vi.mocked(api.me.get).mockClear()
        // `lapsed: null` is what the server always sends: without it the banner of "Pro ended" asks `/me` for it, for its own reason.
        const free = { plan: 'free', source: 'free', until: null, lapsed: null } as const
        await render(today({ ...practiceBlock(), entitlement: free }))
        await render(today({ ...practiceBlock({ available: false, modes: [] }), entitlement: PRO }))

        expect(api.me.get).not.toHaveBeenCalled()
      })

      it('shows from the third learned word and not before', async () => {
        expect((await render(today({ ...course(3), ...practiceBlock() }))).find('[data-testid=games-card]').exists()).toBe(true)
        expect((await render(today({ ...course(2), ...practiceBlock() }))).find('[data-testid=games-card]').exists()).toBe(false)
        expect((await render(today({ ...course(0), ...practiceBlock() }))).find('[data-testid=games-card]').exists()).toBe(false)
      })

      it('is not on the start card, and not for a learner with no course', async () => {
        const start = await render(today({ active_course: null, due_count: 0, open_session_id: null, ...practiceBlock() }))
        expect(start.find('[data-testid=start-card]').exists()).toBe(true)
        expect(start.find('[data-testid=games-card]').exists()).toBe(false)

        const noCourse = await render(today({ active_course: null, due_count: 5, ...practiceBlock() }))
        expect(noCourse.find('[data-testid=games-card]').exists()).toBe(false)
      })

      it('has no card without the practice access (/today says so, or an older server sends no block)', async () => {
        expect((await render(today(practiceBlock({ available: false, modes: [] })))).find('[data-testid=games-card]').exists()).toBe(false)
        expect((await render(today())).find('[data-testid=games-card]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        const w = await render(today(practiceBlock()))

        expect(w.get('[data-testid=games-card]').text()).toContain("So'z o'yinlari")
        expect(w.get('[data-testid=games-play-btn]').text()).toBe("O'ynash")
      })
    })

    describe('nothing to do today (no review due, the quota of new words is used up)', () => {
      const allDone = (overrides: Partial<TodayResponse> = {}) => today({ due_count: 0, new_words_remaining: 0, ...overrides })
      it('says so calmly instead of the active "Start session", and drops the hint about the order of the session', async () => {
        const w = await render(allDone())

        expect(text(w, 'all-done')).toContain('На сегодня всё. Новые слова — завтра')
        expect(w.find('[data-testid=review-btn]').exists()).toBe(false)
        expect(w.find('[data-testid=session-hint]').exists()).toBe(false)
        expect(w.findAll('.btn-primary')).toHaveLength(0)
        // the lesson would open the same empty session: no button for it either
        expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(false)
        expect(text(w, 'next-lesson')).toBe('Урок 4 · Food')
      })

      it('has one entry to the practice, the "Word games" card, and no second button of its own in the "all done" state', async () => {
        const w = await render(allDone(practiceBlock()))

        expect(w.find('[data-testid=games-card]').exists()).toBe(true)
        expect(w.find('[data-testid=practice-btn]').exists()).toBe(false)
        expect(w.get('[data-testid=all-done]').find('button.btn-tinted').exists()).toBe(false)
        await w.get('[data-testid=games-play-btn]').trigger('click')
        await flushPromises()

        expect(w.router.currentRoute.value.name).toBe('practice-hub')
        expect(w.router.currentRoute.value.params.slug).toBe('english-a1')
      })

      it('opens the paywall of the practice instead while the practice is behind Pro', async () => {
        const w = await render(allDone(practiceBlock({ pro_required: true })))

        await w.get('[data-testid=games-play-btn]').trigger('click')
        await flushPromises()

        expect(w.router.currentRoute.value.name).not.toBe('practice-hub')
        expect(w.find('[data-testid=paywall]').exists() || w.find('[data-testid=paywall-title]').exists()).toBe(true)
      })

      it('has no games card for a learner without the practice', async () => {
        const w = await render(allDone(practiceBlock({ available: false, modes: [] })))

        expect(w.find('[data-testid=all-done]').exists()).toBe(true)
        expect(w.find('[data-testid=games-card]').exists()).toBe(false)
      })

      it('is not the state of a learner with a review due, an open session, or no limit of new words', async () => {
        for (const response of [today({ due_count: 4, new_words_remaining: 0 }), allDone({ open_session_id: 9 }), today({ due_count: 0, new_words_remaining: null }), today({ due_count: 0, new_words_remaining: 3 }), allDone({ active_course: null })]) {
          const w = await render(response)
          expect(w.find('[data-testid=all-done]').exists()).toBe(false)
        }
        expect((await render(today({ due_count: 4, new_words_remaining: 0 }))).find('[data-testid=review-btn]').exists()).toBe(true)
      })

      it('does not read /me for a learner who has something to do (the "Word games" card comes with /today)', async () => {
        vi.mocked(api.me.get).mockClear()
        const w = await render(today({ entitlement: PRO, ...practiceBlock() }))

        expect(w.find('[data-testid=all-done]').exists()).toBe(false)
        expect(w.find('[data-testid=games-card]').exists()).toBe(true)
        expect(api.me.get).not.toHaveBeenCalled()
      })

      it('offers a quiet way to Pro under the text when the free quota ended the day, and opens the paywall for quota', async () => {
        const w = await render(allDone())

        expect(text(w, 'all-done-pro')).toBe('Больше новых слов — в Pro')
        await w.get('[data-testid=all-done-pro]').trigger('click')
        await flushPromises()

        expect(w.find('[data-testid=paywall]').exists() || w.find('[data-testid=paywall-title]').exists()).toBe(true)
        expect(w.findComponent({ name: 'PaywallSheet' }).props('reason')).toBe('quota')
      })

      it('has no link to Pro for a Pro learner or for a newcomer without progress', async () => {
        const pro = await render(allDone({ entitlement: PRO }))
        expect(pro.find('[data-testid=all-done-pro]').exists()).toBe(false)

        const newcomer = await render(allDone({
          goal: { target: 20, done: 0, met: false },
          streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
          active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 0, total: 900 }, next_lesson: { id: 41, stage: 2, lesson: 4, title: 'Food' } },
        }))
        expect(newcomer.find('[data-testid=all-done]').exists()).toBe(true)
        expect(newcomer.find('[data-testid=all-done-pro]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')

        expect(text(await render(allDone()), 'all-done')).toContain("Bugungi mashg'ulotlar tugadi. Ertaga yangi so'zlar bilan davom etasiz")
        expect(text(await render(allDone()), 'all-done-pro')).toBe("Ko'proq yangi so'zlar — Pro'da")
      })
    })

    it('shows the due count with the right Russian form and an empty state', async () => {
      expect(text(await render(today({ due_count: 1 })), 'due-count')).toBe('1 слово к повторению')
      expect(text(await render(today({ due_count: 3 })), 'due-count')).toBe('3 слова к повторению')
      expect(text(await render(today({ due_count: 0 })), 'due-count')).toBe('Повторять пока нечего')
    })

    it('says how many new words are left today, that the quota is over, or that there is no limit', async () => {
      expect(text(await render(today({ new_words_remaining: 12 })), 'new-remaining')).toBe('Новых слов на сегодня: 12')
      expect(text(await render(today({ new_words_remaining: 0 })), 'new-remaining')).toBe('Новые слова на сегодня закончились')
      expect(text(await render(today({ new_words_remaining: null, entitlement: PRO })), 'new-remaining')).toBe(
        'Новые слова — без ограничений',
      )
    })

    it('has one primary button that opens the daily review, with a hint what the session is made of', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 20 })
      const w = await render()

      const button = w.get('[data-testid=review-btn]')
      expect(button.text()).toBe('Начать занятие')
      expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
      // the mock-up has no icon in the button
      expect(button.find('svg').exists()).toBe(false)
      expect(text(w, 'session-hint')).toBe('Короткий раунд на несколько минут: повторение и новые слова из вашего курса')
      expect(w.findAll('.btn-primary')).toHaveLength(1)
      expect(w.find('[data-testid=review-card]').exists()).toBe(true)

      await button.trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(w.router.currentRoute.value.name).toBe('training')
      expect(w.router.currentRoute.value.params.sessionId).toBe('918')
    })

    it('offers to continue an open daily review', async () => {
      const w = await render(today({ open_session_id: 918 }))

      expect(text(w, 'review-btn')).toBe('Продолжить занятие')
    })

    it('disables the button while a session is being opened', async () => {
      vi.mocked(api.training.open).mockReturnValue(new Promise(() => {}))
      const w = await render()

      await w.get('[data-testid=review-btn]').trigger('click')

      expect(w.get('[data-testid=review-btn]').attributes('disabled')).toBeDefined()
    })

    it('without a course sends the learner to the catalog instead of promising new words', async () => {
      const w = await render(today({ active_course: null, new_words_remaining: 12 }))

      const link = w.get('[data-testid=new-no-course]')
      expect(link.text()).toBe('Выберите курс, чтобы учить новые слова')
      expect(link.attributes('href')).toBe('/courses')
      expect(w.find('[data-testid=new-remaining]').exists()).toBe(false)
      // The review itself does not depend on a course.
      expect(text(w, 'due-count')).toBe('23 слова к повторению')
      expect(text(w, 'review-btn')).toBe('Начать занятие')
    })

    it('makes choosing a course the primary action when there is nothing to review or resume', async () => {
      const w = await render(today({ active_course: null, due_count: 0 }))

      const choose = w.get('[data-testid=start-choose-course]')
      expect(choose.text()).toBe('Выбрать курс')
      expect(choose.attributes('href')).toBe('/courses')
      expect(w.find('[data-testid=review-btn]').exists()).toBe(false)
      expect(api.training.open).not.toHaveBeenCalled()
    })

    it('shows one start card instead of the empty goal, the counters and the course block for a newcomer without a course', async () => {
      const w = await render(today({ active_course: null, due_count: 0, new_words_remaining: 12, streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false }, goal: { target: 20, done: 0, met: false } }))

      const card = w.get('[data-testid=start-card]')
      expect(card.get('h2').text()).toBe('Начнём!')
      expect(card.text()).toContain('Выберите курс — и каждый день будете учить новые слова')
      expect(card.findAll('a,button')).toHaveLength(1)
      expect(w.findAll('[data-testid=start-choose-course]')).toHaveLength(1)
      for (const id of ['review-card', 'goal-card', 'goal-setting', 'due-count', 'new-no-course', 'session-hint', 'streak-card', 'course-card', 'no-course', 'choose-course']) {
        expect(w.find(`[data-testid=${id}]`).exists(), id).toBe(false)
      }
      expect(w.text()).not.toContain('Пока нечего повторять')
    })

    it('keeps the "finish the setup in the bot" banner under the start card, compact', async () => {
      const w = await render(today({ active_course: null, due_count: 0, onboarding_completed: false }))

      const html = w.html()
      expect(html.indexOf('data-testid="start-card"')).toBeLessThan(html.indexOf('data-testid="onboarding-hint"'))
      expect(w.get('[data-testid=onboarding-hint]').classes()).not.toContain('card')
      await w.get('[data-testid=onboarding-btn]').trigger('click')
      expect(telegram.close).toHaveBeenCalledTimes(1)
    })

    it('has no start card when there is something to review, a session is open or the group gave an assignment', async () => {
      const due = await render(today({ active_course: null, due_count: 4 }))
      expect(due.find('[data-testid=start-card]').exists()).toBe(false)
      expect(due.find('[data-testid=review-card]').exists()).toBe(true)

      const open = await render(today({ active_course: null, due_count: 0, open_session_id: 9 }))
      expect(open.find('[data-testid=start-card]').exists()).toBe(false)
      expect(text(open, 'review-btn')).toBe('Продолжить занятие')

      vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })
      const group = await render(today({ active_course: null, due_count: 0 }))
      expect(group.find('[data-testid=start-card]').exists()).toBe(false)
      expect(group.find('[data-testid=assignments-card]').exists()).toBe(true)
    })

    it('shows the loading state, not the start card or the usual screen, until the assignments of a courseless learner are in', async () => {
      let release: (v: { assignments: never[] }) => void = () => {}
      vi.mocked(api.assignments.list).mockReturnValue(new Promise((resolve) => { release = resolve }))

      const w = await render(today({ active_course: null, due_count: 0 }))
      expect(w.find('[data-testid=start-card]').exists()).toBe(false)
      expect(w.find('[data-testid=review-card]').exists()).toBe(false)
      expect(w.find('[data-testid=plan-badge]').exists()).toBe(false)

      release({ assignments: [] })
      await flushPromises()
      expect(w.find('[data-testid=start-card]').exists()).toBe(true)
    })

    it('does not hang on the loading state when the assignments fail: the start card is shown', async () => {
      vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

      const w = await render(today({ active_course: null, due_count: 0 }))

      expect(w.find('[data-testid=start-card]').exists()).toBe(true)
    })

    it('has no start card for a learner with a course', async () => {
      const w = await render(today({ due_count: 0 }))

      expect(w.find('[data-testid=start-card]').exists()).toBe(false)
      expect(w.find('[data-testid=review-card]').exists()).toBe(true)
    })

    it('says it in Uzbek', async () => {
      setLocale('uz')
      const w = await render(today({ active_course: null, due_count: 0 }))

      const card = w.get('[data-testid=start-card]')
      expect(card.get('h2').text()).toBe('Keling, boshlaymiz!')
      expect(card.text()).toContain("Kursni tanlang — har kuni yangi so'zlar o'rganasiz")
      expect(card.get('[data-testid=start-choose-course]').text()).toBe('Kursni tanlash')
    })

    it('has no "Learn about Pro" link in the card: the plan chip in the header is the way to Pro', async () => {
      const w = await render(today({ new_words_remaining: 12 }))

      expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
      expect(w.get('[data-testid=review-card]').text()).not.toContain('Узнать о Pro')
      expect(w.get('[data-testid=plan-badge]').attributes('href')).toBe('/billing')

      const quota = await render(today({ new_words_remaining: 0 }))
      expect(quota.find('[data-testid=paywall-open]').exists()).toBe(false)
    })

    it('puts a failed open under the button and scrolls it into view, wherever the learner pressed', async () => {
      const original = Element.prototype.scrollIntoView
      const scroll = vi.fn()
      Element.prototype.scrollIntoView = scroll
      try {
        vi.mocked(api.training.open).mockRejectedValue(rateLimited())
        const w = await render()

        await w.get('[data-testid=next-lesson-btn]').trigger('click')
        await flushPromises()

        const alert = w.get('[data-testid=review-card] [data-testid=open-error]')
        expect(alert.text()).toBe('Слишком много запросов. Подождите немного и повторите.')
        expect(alert.attributes('role')).toBe('alert')
        expect(alert.classes()).toContain('text-danger-ink')
        expect(alert.find('svg').exists()).toBe(true)
        expect(scroll).toHaveBeenCalledWith({ block: 'nearest', behavior: 'smooth' })
      } finally {
        Element.prototype.scrollIntoView = original
      }
    })

    it('stays on the screen and explains a failed open', async () => {
      vi.mocked(api.training.open).mockRejectedValue(rateLimited())
      const w = await render()

      await w.get('[data-testid=review-btn]').trigger('click')
      await flushPromises()

      expect(w.router.currentRoute.value.name).not.toBe('training')
      expect(text(w, 'open-error')).toBe('Слишком много запросов. Подождите немного и повторите.')
    })

    it('forgets the open error when the learner leaves the screen', async () => {
      vi.mocked(api.training.open).mockRejectedValue(rateLimited())
      const w = await render()
      await w.get('[data-testid=review-btn]').trigger('click')
      await flushPromises()
      expect(useTrainingStore().openError).not.toBe('')

      w.unmount()

      expect(useTrainingStore().openError).toBe('')
    })
  })

  describe('streak', () => {
    it('shows the streak in the goal card, the warning when it is at risk and the record in the streak card', async () => {
      const w = await render()

      expect(text(w, 'streak-current')).toBe('серия 5 дней')
      expect(w.find('[data-testid=goal-card] [data-testid=streak-current]').exists()).toBe(true)
      expect(text(w, 'streak-at-risk')).toBe('Выполните цель до полуночи, чтобы не потерять серию')
      expect(w.get('[data-testid=streak-at-risk]').classes()).toContain('text-warning-ink')
      expect(text(w, 'streak-best')).toBe('рекорд серии — 12 дней')
      expect(w.get('[data-testid=streak-dot]').classes()).toContain('bg-warning')
      expect(w.find('[data-testid=streak-card] .icon-tile').exists()).toBe(false)
    })

    it('counts days in the right Russian form', async () => {
      const streak = (current: number) => ({ current, best: current, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false })

      expect(text(await render(today({ streak: streak(1) })), 'streak-current')).toBe('серия 1 день')
      expect(text(await render(today({ streak: streak(3) })), 'streak-current')).toBe('серия 3 дня')
      expect(text(await render(today({ streak: streak(21) })), 'streak-current')).toBe('серия 21 день')
      const record = (best: number) => today({ streak: { current: 0, best, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } })
      expect(text(await render(record(1)), 'streak-best')).toBe('рекорд серии — 1 день')
      expect(text(await render(record(3)), 'streak-best')).toBe('рекорд серии — 3 дня')
    })

    it('shows a safe streak after the goal, and an invitation when there is none, with a quiet dot', async () => {
      const safe = await render(today({ streak: { current: 6, best: 6, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(safe, 'streak-safe')).toBe('На сегодня серия продлена')
      expect(safe.find('[data-testid=streak-at-risk]').exists()).toBe(false)
      expect(safe.find('[data-testid=streak-best]').exists()).toBe(false)
      expect(safe.get('[data-testid=streak-dot]').classes()).toContain('bg-accent')

      const none = await render(today({ streak: { current: 0, best: 3, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      // no "series 0 days" in the goal card
      expect(none.find('[data-testid=streak-current]').exists()).toBe(false)
      expect(text(none, 'streak-none')).toBe('Выполните цель дня, чтобы начать серию')
      expect(none.find('[data-testid=streak-at-risk]').exists()).toBe(false)
      expect(none.get('[data-testid=streak-dot]').classes()).toContain('bg-track')
      expect(text(none, 'streak-best')).toBe('рекорд серии — 3 дня')
    })

    it('shows a Pro learner the streak freezes in stock and that yesterday was frozen', async () => {
      const two = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 2, frozen_yesterday: false } }))
      expect(text(two, 'streak-freezes')).toBe('2 заморозки')
      expect(two.find('[data-testid=streak-frozen]').exists()).toBe(false)
      expect(two.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)

      const frozen = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true } }))
      expect(text(frozen, 'streak-freezes')).toBe('1 заморозка')
      expect(text(frozen, 'streak-frozen')).toBe('Вчерашний день заморожен — серия сохранена')
      expect(text(frozen, 'streak-at-risk')).toBe('Выполните цель до полуночи, чтобы не потерять серию')

      const none = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(none, 'streak-freezes')).toBe('Заморозок нет — новая в понедельник')
    })

    it('offers a Free learner the streak-freeze teaser that opens the paywall with its own lead', async () => {
      const w = await render()
      expect(w.find('[data-testid=streak-freezes]').exists()).toBe(false)
      const teaser = w.get('[data-testid=streak-freeze-teaser]')
      expect(teaser.text()).toBe('Заморозки серии — в Pro')
      expect(teaser.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain', 'btn-sm']))

      await teaser.trigger('click')
      await flushPromises()
      expect(w.get('[data-testid=paywall-lead]').text()).toContain('Заморозка серии — в Pro')

    })

    it('shows the teaser only to a learner who has a streak', async () => {
      const none = await render(today({ streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))

      expect(none.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)
      expect(none.find('[data-testid=streak-card]').exists()).toBe(true)
    })

    describe('the line about the freeze after a streak that broke (Free)', () => {
      const broken = (overrides: Partial<TodayResponse> = {}) =>
        today({ streak: { current: 0, best: 9, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false }, ...overrides })

      it('is one quiet line that opens the sheet of the freezes', async () => {
        const w = await render(broken())

        const line = w.get('[data-testid=streak-freeze-line]')
        expect(line.text()).toBe('В Pro заморозка сохранила бы серию')
        expect(line.classes()).toEqual(expect.arrayContaining(['btn', 'btn-plain', 'btn-sm']))
        // the teaser of a live streak is not there at the same time
        expect(w.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)

        await line.trigger('click')
        await flushPromises()
        expect(w.get('[data-testid=paywall-lead]').text()).toContain('Заморозка серии — в Pro')
      })

      it('comes once in 7 days', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(Date.parse('2026-11-17T09:00:00Z'))
        expect((await render(broken())).find('[data-testid=streak-freeze-line]').exists()).toBe(true)

        // the next visit of the tab, and the day after, and the sixth day: not again
        for (const days of [0, 1, 6]) {
          vi.setSystemTime(Date.parse('2026-11-17T09:00:00Z') + days * 86_400_000 + 60_000)
          expect((await render(broken())).find('[data-testid=streak-freeze-line]').exists(), `day ${days}`).toBe(false)
        }

        vi.setSystemTime(Date.parse('2026-11-17T09:00:00Z') + 7 * 86_400_000 + 60_000)
        expect((await render(broken())).find('[data-testid=streak-freeze-line]').exists()).toBe(true)
      })

      it('is not for Pro, nor for a live streak, nor for a learner who never had one, nor on the start card', async () => {
        expect((await render(broken({ entitlement: PRO }))).find('[data-testid=streak-freeze-line]').exists()).toBe(false)
        expect((await render(today())).find('[data-testid=streak-freeze-line]').exists()).toBe(false)
        const never = await render(today({ streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))
        expect(never.find('[data-testid=streak-freeze-line]').exists()).toBe(false)
        const start = await render(broken({ active_course: null, due_count: 0, open_session_id: null }))
        expect(start.find('[data-testid=streak-freeze-line]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        const w = await render(broken())

        expect(w.get('[data-testid=streak-freeze-line]').text()).toBe("Pro'da zaxira kun ketma-ketlikni saqlab qolgan bo'lardi")
      })
    })
  })

  describe('a newcomer (no progress yet)', () => {
    const newcomer = (overrides: Partial<TodayResponse> = {}): TodayResponse =>
      today({
        due_count: 0,
        goal: { target: 20, done: 0, met: false },
        streak: { current: 0, best: 0, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false },
        active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 0, total: 900 }, next_lesson: { id: 41, stage: 1, lesson: 1, title: null } },
        league: { tier: 0, tier_key: 'bronze', rank: 1, points: 0, members_count: 30 },
        game: { daily_available: true, active_run: null },
        ...overrides,
      })

    it('keeps the greeting, the goal, the counters and the button; no caravan of the day, no league, no Pro teasers', async () => {
      const w = await render(newcomer())

      expect(w.find('h1').exists()).toBe(true)
      expect(w.find('[data-testid=goal-card]').exists()).toBe(true)
      expect(w.find('[data-testid=due-count]').exists()).toBe(true)
      expect(w.find('[data-testid=review-btn]').exists()).toBe(true)
      expect(w.find('[data-testid=game-entry]').exists()).toBe(false)
      expect(w.find('[data-testid=league-line]').exists()).toBe(false)
      expect(w.find('[data-testid=paywall-open]').exists()).toBe(false)
      expect(w.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)
    })

    it.each<[string, Partial<TodayResponse>]>([
      ['a streak that was', { streak: { current: 0, best: 2, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }],
      ['words done today', { goal: { target: 20, done: 3, met: false } }],
      ['words waiting for review', { due_count: 4 }],
      ['an unfinished session', { open_session_id: 77 }],
      ['learned words in the course', { active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 5, total: 900 }, next_lesson: null } }],
    ])('brings the caravan and the league back with %s, and no intro', async (_name, overrides) => {
      const w = await render(newcomer(overrides))

      expect(w.find('[data-testid=game-entry]').exists()).toBe(true)
      expect(w.find('[data-testid=league-line]').exists()).toBe(true)
      expect(w.find('[data-testid=intro-sheet]').exists()).toBe(false)
    })

    describe('the standing entry of the Karvon map', () => {
      const withProgress = (overrides: Partial<TodayResponse> = {}): TodayResponse =>
        newcomer({ due_count: 4, game: { available: true, daily_available: false, active_run: null }, ...overrides })

      it('shows the row that leads to the map while /today says the game is open and the learner has a course', async () => {
        const w = await render(withProgress())

        expect(w.get('[data-testid=game-map-entry]').text()).toContain('Карта каравана')
        await w.get('[data-testid=game-map-entry]').trigger('click')
        await flushPromises()
        expect(w.router.currentRoute.value.name).toBe('game')
      })

      it('is hidden while the game is closed, without the field (an older server), without a course, and for a newcomer', async () => {
        expect((await render(withProgress({ game: { available: false, daily_available: false, active_run: null } }))).find('[data-testid=game-map-entry]').exists()).toBe(false)
        expect((await render(withProgress({ game: { daily_available: false, active_run: null } }))).find('[data-testid=game-map-entry]').exists()).toBe(false)
        expect((await render(withProgress({ active_course: null }))).find('[data-testid=game-map-entry]').exists()).toBe(false)
        expect((await render(newcomer())).find('[data-testid=game-map-entry]').exists()).toBe(false)
      })

      it('does not ask /me for the row (a Pro learner reads no /me on Today)', async () => {
        const w = await render(withProgress({ entitlement: PRO }))

        expect(w.find('[data-testid=game-map-entry]').exists()).toBe(true)
        expect(api.me.get).not.toHaveBeenCalled()
      })

      it('does not repeat the game when the caravan of the day or a run is already shown', async () => {
        const daily = await render(withProgress({ game: { available: true, daily_available: true, active_run: null } }))
        expect(daily.find('[data-testid=game-entry]').exists()).toBe(true)
        expect(daily.find('[data-testid=game-map-entry]').exists()).toBe(false)

        const run = await render(withProgress({ game: { available: true, daily_available: false, active_run: { id: 5, progress: { answered: 2, total: 8 } } } as never }))
        expect(run.find('[data-testid=game-entry]').exists()).toBe(true)
        expect(run.find('[data-testid=game-map-entry]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')
        expect((await render(withProgress())).get('[data-testid=game-map-entry]').text()).toContain('Karvon xaritasi')
      })
    })

    describe('the sheet "How it works"', () => {
      it('opens once for a newcomer: four steps, "Next" / "Start"', async () => {
        const w = await render(newcomer())

        expect(w.find('[data-testid=intro-sheet]').exists()).toBe(true)
        expect(text(w, 'intro-title')).toBe('Занятие — короткий раунд')
        expect(text(w, 'intro-text')).toBe('Раунд занимает несколько минут: немного повторения и несколько новых слов. Закончили — можно остановиться.')
        expect(text(w, 'intro-next')).toBe('Дальше')

        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Слова возвращаются сами')
        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Задания разные с первого дня')
        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Хочется ещё — сыграйте ещё раунд')
        expect(text(w, 'intro-text')).toBe('После раунда можно сыграть следующий или закрепить слова в играх. Хватит и 10 минут в день.')
        expect(text(w, 'intro-next')).toBe('Начать')

        await w.get('[data-testid=intro-next]').trigger('click')
        expect(w.find('[data-testid=intro-sheet]').exists()).toBe(false)
      })

      it('does not come back on the next visit', async () => {
        const first = await render(newcomer())
        await first.get('[data-testid=sheet-x]').trigger('click')
        expect(first.find('[data-testid=intro-sheet]').exists()).toBe(false)

        const second = await render(newcomer())
        expect(second.find('[data-testid=intro-sheet]').exists()).toBe(false)
      })

      it('does not open for a learner with progress', async () => {
        expect((await render()).find('[data-testid=intro-sheet]').exists()).toBe(false)
      })

      it('speaks Uzbek too', async () => {
        setLocale('uz')
        const w = await render(newcomer())

        expect(text(w, 'intro-title')).toBe("Bir mashg'ulot bir necha daqiqa davom etadi")
        expect(text(w, 'intro-next')).toBe('Keyingi')
      })

      it('does not break and does not come back within the session when the storage throws', async () => {
        const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
          throw new Error('denied')
        })
        const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
          throw new Error('denied')
        })
        try {
          const first = await render(newcomer())
          expect(first.find('[data-testid=intro-sheet]').exists()).toBe(true)
          await first.get('[data-testid=sheet-x]').trigger('click')

          const second = await render(newcomer())
          expect(second.find('[data-testid=review-btn]').exists()).toBe(true)
          expect(second.find('[data-testid=intro-sheet]').exists()).toBe(false)
        } finally {
          get.mockRestore()
          set.mockRestore()
        }
      })
    })
  })

  describe('banners', () => {
    const lapsedMe = (lapsed: boolean): MeResponse => ({
      learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
      groups: [],
      stats: { words_learned: 0, words_due_today: 0 },
      entitlement: {
        plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null,
        lapsed: lapsed ? { provider: 'payme', ended_at: '2026-10-18T09:30:00+00:00' } : null,
      },
      paywall: { mode: 'purchase', contact: null },
      settings: settingsFixture(),
    })

    describe('"Pro ended" (2A.6)', () => {
      beforeEach(() => window.localStorage.clear())

      it('decides the lapse banner by the Today payload; /me is asked once in the background only to report the offer', async () => {
        const initial = today()
        let answer: (value: MeResponse) => void = () => {}
        vi.mocked(api.me.get).mockReturnValue(new Promise<MeResponse>((resolve) => (answer = resolve)))
        const w = await render(today({ entitlement: { ...initial.entitlement, lapsed: lapsedMe(true).entitlement.lapsed } }))

        // Shown before /me has answered: the banner does not wait for it.
        expect(text(w, 'lapsed-title')).toBe('Pro закончился')
        expect(api.me.get).toHaveBeenCalledTimes(1)
        answer(lapsedMe(true))
        await flushPromises()
        w.unmount()

        setActivePinia(createPinia())
        vi.mocked(api.me.get).mockClear()
        await render(today({ entitlement: { ...initial.entitlement, lapsed: null } }))
        expect(api.me.get).not.toHaveBeenCalled()
      })

      it('offers renewal to a Free learner whose Pro lapsed and leads to the subscription screen', async () => {
        vi.mocked(api.me.get).mockResolvedValue(lapsedMe(true))
        const w = await render()

        expect(text(w, 'lapsed-title')).toBe('Pro закончился')

        await w.router.push('/today')
        await w.get('[data-testid=lapsed-renew]').trigger('click')
        await flushPromises()
        expect(w.router.currentRoute.value.name).toBe('billing')
      })

      it('shows nothing when nothing lapsed, and does not even ask /me of a Pro learner', async () => {
        vi.mocked(api.me.get).mockResolvedValue(lapsedMe(false))
        expect((await render()).find('[data-testid=lapsed-banner]').exists()).toBe(false)

        vi.mocked(api.me.get).mockClear()
        setActivePinia(createPinia())
        await render(today({ entitlement: PRO }))
        expect(api.me.get).not.toHaveBeenCalled()
      })
    })

    it('hints at finishing the onboarding in the bot', async () => {
      expect((await render()).find('[data-testid=onboarding-hint]').exists()).toBe(false)

      const w = await render(today({ onboarding_completed: false }))
      expect(text(w, 'onboarding-hint')).toContain('Чтобы завершить настройку, отправьте боту /start')
      expect(w.get('[data-testid=onboarding-hint] path').attributes('d')).toBe(ICONS['information-circle'].outline[0]?.d)
    })

    it('offers a button that closes the Mini App to finish the onboarding in the bot', async () => {
      expect((await render()).find('[data-testid=onboarding-btn]').exists()).toBe(false)

      const w = await render(today({ onboarding_completed: false }))
      const button = w.get('[data-testid=onboarding-btn]')
      expect(button.text()).toBe('Закончить настройку в боте')
      expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))

      await button.trigger('click')

      expect(telegram.close).toHaveBeenCalledTimes(1)
    })

    it('keeps the screen and announces a failed reload as an alert with an icon', async () => {
      // The store keeps the earlier answer when the learner comes back, so only the failure is announced.
      ;(await render()).unmount()
      vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))

      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      const alert = w.get('[data-testid=reload-error]')
      expect(alert.text()).toBe('Сервер временно недоступен. Попробуйте позже.')
      expect(alert.attributes('role')).toBe('alert')
      expect(alert.classes()).toContain('text-danger-ink')
      expect(alert.find('svg').exists()).toBe(true)
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.get('[data-testid=goal-text]').text()).toBe('из 20 слов')
    })
  })

  describe('opened from a reminder (?from=reminder)', () => {
    async function renderAt(path: string, response: TodayResponse = today()) {
      vi.mocked(api.today.get).mockResolvedValue(response)
      const router = makeRouter()
      await router.push(path)
      await router.isReady()
      const wrapper = mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()
      return Object.assign(wrapper, { router })
    }

    it('opens the daily review as a reminder session right away and drops the marker from the URL', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 20 })

      const w = await renderAt('/today?from=reminder')

      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'reminder' })
      expect(w.router.currentRoute.value.name).toBe('training')
      expect(w.router.currentRoute.value.params.sessionId).toBe('918')
      // The `today` entry behind us no longer carries the marker: going back does not reopen a session.
      w.router.back()
      await flushPromises()
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
    })

    it('opens the daily review with its own source from the button left after the answer in the chat (?from=reminder_quiz)', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 919, mode: 'daily_review', lesson: null, total_words: 20 })

      const w = await renderAt('/today?from=reminder_quiz')

      expect(api.training.open).toHaveBeenCalledTimes(1)
      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'reminder_quiz' })
      expect(w.router.currentRoute.value.name).toBe('training')
      expect(w.router.currentRoute.value.params.sessionId).toBe('919')
      w.router.back()
      await flushPromises()
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
    })

    it('stays on the screen with the error when the session cannot be opened', async () => {
      vi.mocked(api.training.open).mockRejectedValue(rateLimited())

      const w = await renderAt('/today?from=reminder')

      expect(w.router.currentRoute.value.name).toBe('today')
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
      expect(text(w, 'open-error')).toBe('Слишком много запросов. Подождите немного и повторите.')
      expect(text(w, 'goal-text')).toBe('из 20 слов')
    })

    it('does not open anything when the Today data failed to load, nor for other sources', async () => {
      vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
      const failed = await renderAt('/today?from=reminder')
      expect(api.training.open).not.toHaveBeenCalled()
      expect(failed.find('[data-testid=error]').exists()).toBe(true)

      const other = await renderAt('/today?from=today')
      expect(api.training.open).not.toHaveBeenCalled()
      expect(other.router.currentRoute.value.name).toBe('today')
      expect(other.router.currentRoute.value.query.from).toBe('today')
    })
  })

  describe('the list "For today" (mock-up Main)', () => {
    const everything = () => today({
      ...practiceBlock(),
      hard_round: { available: true, reason: null, words: 10, open_session_id: null, resets_at: null },
      game: {
        available: true,
        daily_available: true,
        active_run: null,
        class_caravan: { id: 5, stars_total: 12, target_stars: 40, ends_at: '2026-10-30T13:00:00+00:00' } as never,
      },
    })

    it('has no card when nothing else waits today', async () => {
      const w = await render()

      expect(w.find('[data-testid=today-list]').exists()).toBe(false)
    })

    it('puts the assignments first, then the hard words, the caravans and the games, as rows with a status dot, under the goal and over the streak', async () => {
      vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })
      const w = await render(everything())

      const list = w.get('[data-testid=today-list]')
      expect(list.classes()).toContain('list')
      expect(list.get('h2').text()).toBe('На сегодня')
      const html = w.html()
      const at = (id: string): number => html.indexOf(`data-testid="${id}"`)
      const order = ['review-card', 'today-list', 'assignments-card', 'hard-round-card', 'class-caravan-card', 'game-entry', 'games-card', 'streak-card']
      for (const id of order) expect(at(id), id).toBeGreaterThan(-1)
      expect(order.map(at)).toEqual([...order.map(at)].sort((x, y) => x - y))
      // Timed activities keep their status dot. Word games have their own pictogram.
      for (const id of ['assignments-card', 'hard-round-card', 'class-caravan-card', 'game-entry']) {
        expect(w.find(`[data-testid=${id}] span.rounded-full[aria-hidden=true]`).exists(), id).toBe(true)
      }
      expect(list.get('[data-testid=games-card] .icon-tile svg').attributes('aria-hidden')).toBe('true')
      expect(w.findAll('.btn-primary')).toHaveLength(1)
    })

    it('makes the whole row of the hard words a 44 px button, disabled while a session is being opened', async () => {
      let release: (v: never) => void = () => {}
      vi.mocked(api.training.open).mockReturnValue(new Promise((resolve) => { release = resolve }))
      const w = await render(everything())

      const row = w.get('[data-testid=hard-round-card] button')
      expect(row.classes()).toContain('min-h-11')
      expect(row.text()).toContain('Раунд трудных слов')
      await row.trigger('click')
      expect(w.get('[data-testid=hard-round-card] button').attributes('disabled')).toBeDefined()
      release(null as never)
      await flushPromises()
    })
  })

  describe('assignments of the center', () => {
    it('shows no block without assignments', async () => {
      const w = await render()

      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
    })

    it('lists the assignments with the learner\'s own progress and starts the lesson as an assignment session', async () => {
      vi.mocked(api.assignments.list).mockResolvedValue({
        assignments: [assignment(), assignment({ id: 8, title: 'Done', lesson_id: 42, progress_pct: 100, completed: true, late: true })],
      })
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 55, mode: 'lesson', lesson: null, total_words: 10 })

      const w = await render()

      expect(w.findAll('[data-testid=assignment]')).toHaveLength(2)
      expect(w.findAll('[data-testid=assignment-title]')[0]?.text()).toBe('Food')
      expect(w.findAll('[data-testid=assignment-progress]')[0]?.text()).toBe('выполнено 40%')
      // rows of the list "For today", the first thing in it
      expect(w.find('[data-testid=today-list] [data-testid=assignments-card]').exists()).toBe(true)
      expect(w.findAll('[data-testid=assignment-done]')[0]?.text()).toBe('Выполнено с опозданием')
      // A completed assignment has no button.
      expect(w.findAll('[data-testid=assignment-btn]')).toHaveLength(1)

      await w.get('[data-testid=assignment-btn]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
      expect(w.router.currentRoute.value.name).toBe('training')
    })

    it('marks an overdue open assignment', async () => {
      vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment({ overdue: true })] })

      const w = await render()

      expect(text(w, 'assignment-due')).toContain('Срок прошёл')
      expect(w.get('[data-testid=assignment-due]').classes()).toContain('text-danger-ink')
    })

    it('keeps Today working when the assignments cannot be loaded', async () => {
      vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

      const w = await render()

      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
      expect(text(w, 'goal-text')).toBe('из 20 слов')
    })

    it('does not wait for slow assignments to show Today', async () => {
      vi.mocked(api.assignments.list).mockReturnValue(new Promise(() => {}))

      const w = await render()

      expect(text(w, 'goal-text')).toBe('из 20 слов')
      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
    })

    it('starts loading the assignments right after sign-in, in parallel with Today, when the device knows its learner may have some', async () => {
      window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '1') // the last /today said `assignments_possible: true`
      let answer!: (response: TodayResponse) => void
      vi.mocked(api.today.get).mockReturnValue(new Promise<TodayResponse>((resolve) => (answer = resolve)))

      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      // Today has not answered yet, and the assignments are already on their way.
      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(api.assignments.list).toHaveBeenCalledTimes(1)
      expect(w.find('[data-testid=spinner]').exists()).toBe(true)

      answer(today({ assignments_possible: true }))
      await flushPromises()
      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(api.assignments.list).toHaveBeenCalledTimes(1)
    })

    describe('the first open (`/today.assignments_possible`, the audit of 2026-11-18)', () => {
      const newcomer = (overrides: Partial<TodayResponse> = {}) => today({ active_course: null, due_count: 0, ...overrides })

      it('asks no assignments of a self-study learner and shows the start card as soon as Today is in', async () => {
        const w = await render(newcomer({ assignments_possible: false }))

        expect(api.assignments.list).not.toHaveBeenCalled()
        expect(w.find('[data-testid=spinner]').exists()).toBe(false)
        expect(w.find('[data-testid=start-choose-course]').exists()).toBe(true)
        expect(w.find('[data-testid=assignments-error]').exists()).toBe(false)
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('0')
      })

      it('does not ask the list before Today has answered when nothing is known yet, then asks it for a "maybe" and holds the start card for it', async () => {
        let answer!: (response: TodayResponse) => void
        vi.mocked(api.today.get).mockReturnValue(new Promise<TodayResponse>((resolve) => (answer = resolve)))
        let release: (v: { assignments: AssignmentItem[] }) => void = () => {}
        vi.mocked(api.assignments.list).mockReturnValue(new Promise((resolve) => { release = resolve }))

        const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
        await flushPromises()
        expect(api.assignments.list).not.toHaveBeenCalled()

        answer(newcomer({ assignments_possible: true }))
        await flushPromises()
        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        // A courseless member: the assignment may replace the start card, so the screen waits for it.
        expect(w.find('[data-testid=spinner]').exists()).toBe(true)
        expect(w.find('[data-testid=start-card]').exists()).toBe(false)

        release({ assignments: [assignment()] })
        await flushPromises()
        expect(w.find('[data-testid=start-card]').exists()).toBe(false)
        expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('1')
      })

      it('keeps the old path for an older server (no field): the list is asked, and the next open asks it in parallel', async () => {
        vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })

        const w = await render(newcomer())

        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('1')
      })

      it('lets a learner who left the group go on at once: a list asked in parallel on the old knowledge does not hold the screen', async () => {
        window.localStorage.setItem(ASSIGNMENTS_POSSIBLE_KEY, '1')
        vi.mocked(api.assignments.list).mockReturnValue(new Promise(() => {}))

        const w = await render(newcomer({ assignments_possible: false }))

        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        expect(w.find('[data-testid=spinner]').exists()).toBe(false)
        expect(w.find('[data-testid=start-card]').exists()).toBe(true)
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('0')
      })

      it('shows the assignment to a learner who joined a group after the first open, on the return to Home', async () => {
        const first = await render(newcomer({ assignments_possible: false }))
        expect(api.assignments.list).not.toHaveBeenCalled()
        first.unmount()

        let release: (v: { assignments: AssignmentItem[] }) => void = () => {}
        vi.mocked(api.assignments.list).mockReturnValue(new Promise((resolve) => { release = resolve }))
        const back = await render(newcomer({ assignments_possible: true }))

        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        // The list that was "surely empty" is not known any more: the start card waits for it instead of flashing.
        expect(back.find('[data-testid=start-card]').exists()).toBe(false)
        release({ assignments: [assignment()] })
        await flushPromises()
        expect(back.get('[data-testid=assignment-title]').text()).toBe('Food')
        expect(back.find('[data-testid=start-card]').exists()).toBe(false)
      })

      it('tells a group member the list failed, keeps personal training and retries on demand', async () => {
        vi.mocked(api.assignments.list).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'offline'))
        vi.mocked(api.training.open).mockResolvedValue({ session_id: 57, mode: 'daily_review', lesson: null, total_words: 10 })

        const w = await render(today({ assignments_possible: true }))

        expect(text(w, 'assignments-error')).toContain('Не удалось загрузить задания центра.')
        expect(text(w, 'assignments-retry')).toBe('Повторить')
        expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
        expect(w.get('[data-testid=review-btn]').attributes('disabled')).toBeUndefined()

        vi.mocked(api.assignments.list).mockResolvedValueOnce({ assignments: [assignment()] })
        await w.get('[data-testid=assignments-retry]').trigger('click')
        await flushPromises()

        expect(api.assignments.list).toHaveBeenCalledTimes(2)
        expect(w.find('[data-testid=assignments-error]').exists()).toBe(false)
        expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')

        await w.get('[data-testid=review-btn]').trigger('click')
        await flushPromises()
        expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      })

      it('shows the start card, not a wait, to a courseless member whose list failed, with the line under it', async () => {
        vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

        const w = await render(newcomer({ assignments_possible: true }))

        expect(w.find('[data-testid=start-card]').exists()).toBe(true)
        expect(w.find('[data-testid=assignments-error]').exists()).toBe(true)
      })

      it('tells about a failed list with an older server too (no field): the list was asked and failed', async () => {
        vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

        const w = await render()

        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        expect(text(w, 'assignments-error')).toContain('Не удалось загрузить задания центра.')
      })

      it('says nothing about a list that was never asked (self-study learner), nor about one that loaded', async () => {
        vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))
        const self = await render(today({ assignments_possible: false }))
        expect(api.assignments.list).not.toHaveBeenCalled()
        expect(self.find('[data-testid=assignments-error]').exists()).toBe(false)
        self.unmount()

        vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [] })
        const member = await render(today({ assignments_possible: true }))
        expect(member.find('[data-testid=assignments-error]').exists()).toBe(false)
      })

      it('follows a /today asked elsewhere while Home stays open: joined a group - the list once; the same word again - nothing', async () => {
        vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })
        const w = await render(today({ assignments_possible: false }))
        expect(api.assignments.list).not.toHaveBeenCalled()

        // e.g. the paywall refreshes Today after a payment; the learner has joined a group meanwhile.
        vi.mocked(api.today.get).mockResolvedValue(today({ assignments_possible: true }))
        await useTodayStore().load()
        await flushPromises()

        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        expect(w.get('[data-testid=assignment-title]').text()).toBe('Food')
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('1')

        await useTodayStore().load()
        await flushPromises()
        expect(api.assignments.list).toHaveBeenCalledTimes(1)

        // Left the group: the list is settled as empty without a request.
        vi.mocked(api.today.get).mockResolvedValue(today({ assignments_possible: false }))
        await useTodayStore().load()
        await flushPromises()
        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
        expect(window.localStorage.getItem(ASSIGNMENTS_POSSIBLE_KEY)).toBe('0')
      })

      it('asks the list once on its own open, the watch adding nothing', async () => {
        const w = await render(today({ assignments_possible: true }))

        expect(api.today.get).toHaveBeenCalledTimes(1)
        expect(api.assignments.list).toHaveBeenCalledTimes(1)
        w.unmount()

        // Back to Home: the store still holds the old Today with the same word; this open follows it once more, as before.
        await render(today({ assignments_possible: true }))
        expect(api.assignments.list).toHaveBeenCalledTimes(2)
      })

      it('says the failure in Uzbek', async () => {
        setLocale('uz')
        vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

        const w = await render(today({ assignments_possible: true }))

        expect(text(w, 'assignments-error')).toContain("Markaz vazifalarini yuklab bo'lmadi.")
        expect(text(w, 'assignments-retry')).toBe("Qayta urinib ko'rish")
      })

      it('opens the lesson of the announcement for a learner whose Today says no assignment, asking no list (state 3)', async () => {
        vi.mocked(api.today.get).mockResolvedValue(newcomer({ assignments_possible: false }))
        vi.mocked(api.training.open).mockResolvedValue({ session_id: 58, mode: 'lesson', lesson: null, total_words: 10 })
        const router = makeRouter()
        await router.push('/today?from=assignment&lesson=41')
        await router.isReady()
        mount(TodayView, { global: { plugins: [router] } })
        await flushPromises()

        expect(api.training.open).toHaveBeenCalledTimes(1)
        expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
        expect(router.currentRoute.value.name).toBe('training')
        expect(api.assignments.list).not.toHaveBeenCalled()
      })

      it('lets a learner with an open personal session continue it and, apart from it, open the pending assignment (state 6)', async () => {
        vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })
        vi.mocked(api.training.open).mockResolvedValue({ session_id: 918, mode: 'daily_review', lesson: null, total_words: 10 })

        const w = await render(today({ open_session_id: 918, assignments_possible: true }))

        expect(text(w, 'review-btn')).toBe('Продолжить занятие')
        expect(w.find('[data-testid=assignments-card]').exists()).toBe(true)

        await w.get('[data-testid=review-btn]').trigger('click')
        await flushPromises()
        // The usual "continue": the server hands back the open session (docs/04 A.3), the assignment is not mixed into it.
        expect(api.training.open).toHaveBeenLastCalledWith({ mode: 'daily_review', source: 'self' })
        expect(w.router.currentRoute.value.params.sessionId).toBe('918')

        await w.router.push('/today')
        await w.get('[data-testid=assignment-btn]').trigger('click')
        await flushPromises()
        expect(api.training.open).toHaveBeenLastCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
      })
    })

    it('opens the lesson right away from the group announcement (?from=assignment&lesson=41) and drops the marker', async () => {
      vi.mocked(api.today.get).mockResolvedValue(today())
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 56, mode: 'lesson', lesson: null, total_words: 10 })
      const router = makeRouter()
      await router.push('/today?from=assignment&lesson=41')
      await router.isReady()
      mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'assignment' })
      expect(router.currentRoute.value.name).toBe('training')
      router.back()
      await flushPromises()
      expect(router.currentRoute.value.query.from).toBeUndefined()
    })

    it('ignores a broken lesson id in the announcement link', async () => {
      vi.mocked(api.today.get).mockResolvedValue(today())
      const router = makeRouter()
      await router.push('/today?from=assignment&lesson=abc')
      await router.isReady()
      mount(TodayView, { global: { plugins: [router] } })
      await flushPromises()

      expect(api.training.open).not.toHaveBeenCalled()
      expect(router.currentRoute.value.name).toBe('today')
    })
  })

  describe('"My course"', () => {
    it('shows the active course with its progress and opens the next lesson as a lesson session', async () => {
      vi.mocked(api.training.open).mockResolvedValue({
        session_id: 55,
        mode: 'lesson',
        lesson: { stage: 2, lesson: 4, title: 'Food' },
        total_words: 10,
      })
      const w = await render()

      expect(w.get('[data-testid=course-card] h2').text()).toBe('Мой курс')
      expect(w.get('[data-testid=course-card]').classes()).toContain('card')
      expect(text(w, 'course-title')).toBe('English A1')
      expect(text(w, 'course-progress')).toBe('Изучено 120 из 900')
      const bar = w.get('[data-testid=course-card] [role=progressbar]')
      expect(bar.attributes('aria-valuenow')).toBe('13')
      expect(bar.get('.progress-fill').attributes('style')).toContain('width: 13%')
      expect(text(w, 'next-lesson')).toBe('Урок 4 · Food')
      expect(w.get('[data-testid=course-card]').text()).toContain('Следующий урок')

      expect(text(w, 'next-lesson-hint')).toBe('Только слова этого урока, без повторений')
      const button = w.get('[data-testid=next-lesson-btn]')
      expect(button.text()).toBe('Учить следующий урок')
      expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      await button.trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'self' })
      expect(w.router.currentRoute.value.params.sessionId).toBe('55')
    })

    it('says so when every word of the course has been shown, and when the next lesson has no title', async () => {
      const course = today().active_course!
      const done = await render(today({ active_course: { ...course, next_lesson: null } }))
      expect(text(done, 'course-completed')).toBe('Все слова курса уже показаны')
      expect(done.find('[data-testid=next-lesson-btn]').exists()).toBe(false)

      const untitled = await render(today({ active_course: { ...course, next_lesson: { id: 1, stage: null, lesson: 2, title: null } } }))
      expect(text(untitled, 'next-lesson')).toBe('Урок 2')
    })

    it('shows an empty state with a way to choose a course when there is none', async () => {
      const w = await render(today({ active_course: null }))

      const empty = w.get('[data-testid=no-course]')
      expect(empty.text()).toContain('Курс не выбран')
      expect(empty.get('path').attributes('d')).toBe(ICONS['academic-cap'].outline[0]?.d)
      expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=course-title]').exists()).toBe(false)

      const choose = w.get('[data-testid=choose-course]')
      expect(choose.text()).toBe('Выбрать курс')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(choose.attributes('href')).toBe('/courses')
    })
  })

  describe('an active course without new words (next_lesson is null)', () => {
    const course = today().active_course!
    const empty = (overrides: Partial<TodayResponse> = {}) =>
      today({ due_count: 0, new_words_remaining: 20, active_course: { ...course, progress: { learned: 0, total: 0 }, next_lesson: null }, ...overrides })
    const finished = (overrides: Partial<TodayResponse> = {}) =>
      today({ due_count: 0, new_words_remaining: 20, active_course: { ...course, progress: { learned: 900, total: 900 }, next_lesson: null }, ...overrides })

    it('an empty course with nothing to review: no "Start session", no quota promise, a way to another course', async () => {
      const w = await render(empty())

      expect(w.find('[data-testid=review-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=new-remaining]').exists()).toBe(false)
      expect(w.find('[data-testid=all-done]').exists()).toBe(false)
      expect(w.find('[data-testid=start-choose-course]').exists()).toBe(false)
      expect(text(w, 'new-course-empty')).toBe('В этом курсе пока нет слов')
      const next = w.get('[data-testid=start-next-course]')
      expect(next.text()).toBe('Выбрать другой курс')
      expect(next.attributes('href')).toBe('/courses')
      expect(text(w, 'session-hint')).toBe('Выберите курс, чтобы учить новые слова')

      // "My course": no meaningless "0 of 0", an honest text and a quiet link
      expect(text(w, 'course-completed')).toBe('В этом курсе пока нет слов')
      expect(w.find('[data-testid=course-progress]').exists()).toBe(false)
      expect(w.find('[data-testid=course-card] [role=progressbar]').exists()).toBe(false)
      const other = w.get('[data-testid=course-choose-other]')
      expect(other.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(other.attributes('href')).toBe('/courses')
    })

    it('a finished course with nothing to review says the words are over, not that the quota is left', async () => {
      const w = await render(finished())

      expect(w.find('[data-testid=review-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=new-remaining]').exists()).toBe(false)
      expect(w.find('[data-testid=all-done]').exists()).toBe(false)
      expect(text(w, 'new-course-empty')).toBe('Новых слов в этом курсе не осталось')
      expect(w.get('[data-testid=start-next-course]').attributes('href')).toBe('/courses')
      expect(text(w, 'course-completed')).toBe('Все слова курса уже показаны')
      expect(text(w, 'course-progress')).toBe('Изучено 900 из 900')
      expect(w.get('[data-testid=course-choose-other]').attributes('href')).toBe('/courses')
    })

    it('with reviews due, keeps "Start session" and turns the new-words line into a link to the catalog', async () => {
      const w = await render(finished({ due_count: 4 }))

      expect(w.get('[data-testid=review-btn]').text()).toBe('Начать занятие')
      expect(w.find('[data-testid=start-next-course]').exists()).toBe(false)
      expect(w.find('[data-testid=new-remaining]').exists()).toBe(false)
      const line = w.get('[data-testid=new-course-empty]')
      expect(line.text()).toBe('Новых слов в этом курсе не осталось')
      expect(line.attributes('href')).toBe('/courses')
    })

    it('with a session open, keeps "Continue session"', async () => {
      const w = await render(finished({ open_session_id: 9 }))

      expect(w.get('[data-testid=review-btn]').text()).toBe('Продолжить занятие')
      expect(w.find('[data-testid=start-next-course]').exists()).toBe(false)
      expect(w.find('[data-testid=new-course-empty]').exists()).toBe(true)
    })

    it('does not say "new words tomorrow" when the quota is used up: tomorrow there will be none either', async () => {
      const w = await render(finished({ new_words_remaining: 0 }))

      expect(w.find('[data-testid=all-done]').exists()).toBe(false)
      expect(w.find('[data-testid=start-next-course]').exists()).toBe(true)
    })

    it('says it in Uzbek', async () => {
      setLocale('uz')
      const w = await render(empty())

      expect(text(w, 'new-course-empty')).toBe("Bu kursda hozircha so'z yo'q")
      expect(text(w, 'start-next-course')).toBe('Boshqa kursni tanlash')
      expect(text(await render(finished()), 'new-course-empty')).toBe("Bu kursda yangi so'z qolmadi")
    })

    it('a course with a next lesson stays as before', async () => {
      const w = await render(today({ due_count: 0, new_words_remaining: 20 }))

      expect(w.find('[data-testid=review-btn]').exists()).toBe(true)
      expect(text(w, 'new-remaining')).toBe('Новых слов на сегодня: 20')
      expect(w.find('[data-testid=start-next-course]').exists()).toBe(false)
      expect(w.find('[data-testid=new-course-empty]').exists()).toBe(false)
      expect(w.find('[data-testid=course-choose-other]').exists()).toBe(false)
    })
  })

  describe('league', () => {
    it('shows the place of the week as a row that leads to the league screen', async () => {
      const w = await render(today({ league: { tier: 1, tier_key: 'silver', rank: 3, points: 41, members_count: 28 } }))

      const row = w.get('[data-testid=league-line]')
      expect(row.text()).toContain('Лига · Серебро')
      expect(row.text()).toContain('3-е место из 28 · 41 балл')
      expect(row.attributes('href')).toBe('/league')
      // a quiet row like the rest of Home: no coloured tile
      expect(row.find('.icon-tile').exists()).toBe(false)
    })

    it('falls back to the lowest tier for a tier this app does not know', async () => {
      const w = await render(today({ league: { tier: 9, tier_key: 'mythic' as never, rank: 1, points: 5, members_count: 30 } }))

      expect(text(w, 'league-line')).toContain('Лига · Бронза')
    })

    it.each(['constructor', '__proto__', 'toString'])('takes the name of a tier from the shared table only: «%s» is the lowest tier', async (key) => {
      const w = await render(today({ league: { tier: 9, tier_key: key as never, rank: 1, points: 5, members_count: 30 } }))

      expect(text(w, 'league-line')).toContain('Лига · Бронза')
    })

    it('has no league row while the server sends no league (off, opted out, not placed yet)', async () => {
      const w = await render(today({ league: null }))

      expect(w.find('[data-testid=league-line]').exists()).toBe(false)
      expect(w.text()).not.toContain('Лига недели')
    })
  })

  it.each(['catalog-link', 'decks-link', 'referrals-link', 'achievements-link', 'settings-link'])(
    'has no %s: the sections are reached from the tab bar and the profile',
    async (id) => {
      expect((await render()).find(`[data-testid=${id}]`).exists()).toBe(false)
    },
  )

  describe('loading and failures', () => {
    it('shows skeletons while Today loads', async () => {
      vi.mocked(api.today.get).mockReturnValue(new Promise(() => {}))
      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      expect(w.find('[data-testid=spinner]').exists()).toBe(true)
      expect(w.find('[data-testid=goal-card]').exists()).toBe(false)
    })

    it('shows an error with a retry that reloads', async () => {
      vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      expect(w.get('[data-testid=error]').text()).toContain('Сервер временно недоступен. Попробуйте позже.')

      vi.mocked(api.today.get).mockResolvedValue(today())
      await w.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid=error]').exists()).toBe(false)
      expect(w.get('[data-testid=goal-text]').text()).toBe('из 20 слов')
    })

    it('explains a failed authentication without calling the API', async () => {
      state.ensure.mockRejectedValue(new Error('opened_outside_telegram'))
      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      expect(api.today.get).not.toHaveBeenCalled()
      expect(api.assignments.list).not.toHaveBeenCalled()
      expect(w.get('[data-testid=error]').text()).toContain('Откройте приложение через Telegram.')
    })
  })

  it('renders in Uzbek', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-28T07:00:00Z')) // noon in Tashkent
    setLocale('uz')
    state.learner = { first_name: 'Asadbek' }
    vi.mocked(api.assignments.list).mockResolvedValue({ assignments: [assignment()] })
    const w = await render(today({ new_words_remaining: 12, league: { tier: 1, tier_key: 'silver', rank: 4, points: 312, members_count: 30 } }))

    expect(w.get('h1').text()).toBe('Salom, Asadbek!')
    expect(w.get('[data-testid=eyebrow]').text()).toBe('Chorshanba, 28-oktabr · English A1')
    expect(text(w, 'goal-text')).toBe("20 ta so'zdan")
    expect(text(w, 'review-btn')).toBe("Mashg'ulotni boshlash")
    expect(text(w, 'session-hint')).toBe("Avval o'rgangan so'zlaringizni takrorlang va kursingizdagi yangi so'zlar bilan tanishing")
    expect(text(w, 'streak-current')).toBe('5 kun ketma-ket')
    expect(text(w, 'goal-left')).toBe('12 ta qoldi')
    expect(text(w, 'streak-best')).toBe('rekord — 12 kun')
    expect(text(w, 'due-count')).toBe("Takrorlash uchun 23 ta so'z")
    expect(text(w, 'new-remaining')).toBe("Bugun yana 12 ta yangi so'z o'rganishingiz mumkin")
    expect(w.get('[data-testid=assignment]').text()).toContain('2-modul, 4-dars')
    expect(text(w, 'next-lesson-btn')).toBe("Keyingi darsni o'rganish")
    expect(text(w, 'league-line')).toContain('Liga · Kumush')
    expect(text(w, 'league-line')).toContain("30 ishtirokchi orasida 4-o'rin · 312 ball")
  })
})
