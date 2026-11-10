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
import { useTrainingStore } from '@/stores/training'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { AssignmentItem, MeResponse, TodayResponse } from '@/api/types'

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  learner: null as { first_name: string | null } | null,
  useBackButton: vi.fn(),
}))

vi.mock('@/api', () => ({ api: { today: { get: vi.fn() }, assignments: { list: vi.fn() }, training: { open: vi.fn() }, me: { get: vi.fn() } } }))

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

  describe('header', () => {
    it('greets the learner by name with the date under it', async () => {
      vi.useFakeTimers({ toFake: ['Date'] })
      vi.setSystemTime(new Date('2026-10-28T07:00:00Z')) // noon in Tashkent, the zone of the fixture
      state.learner = { first_name: ' Асадбек ' }

      const w = await render()

      expect(w.get('h1').text()).toBe('Привет, Асадбек!')
      expect(w.get('header p').text()).toBe('среда, 28 октября')
    })

    describe('the date under the greeting', () => {
      it('is the date in the time zone of the learner that /today names: the day of the goal below is counted there', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        // 01:30 on the 29th in Tashkent, 09:30 on the 28th in Pago Pago: whatever the device zone is, one of them differs from it.
        vi.setSystemTime(new Date('2026-10-28T20:30:00Z'))

        const tashkent = await render(today({ timezone: 'Asia/Tashkent' }))
        expect(tashkent.get('header p').text()).toBe('четверг, 29 октября')

        const samoa = await render(today({ timezone: 'Pacific/Pago_Pago' }))
        expect(samoa.get('header p').text()).toBe('среда, 28 октября')
      })

      it('is already the next day for a learner in Tashkent at 20:30 UTC, while a device in UTC is still on the 28th', async () => {
        const deviceZone = process.env.TZ
        process.env.TZ = 'UTC' // the device
        try {
          vi.useFakeTimers({ toFake: ['Date'] })
          vi.setSystemTime(new Date('2026-10-28T20:30:00Z'))
          expect(new Date().getDate()).toBe(28) // the device's own day: the 29th below can only come from the learner's zone

          const w = await render(today({ timezone: 'Asia/Tashkent' }))

          expect(w.get('header p').text()).toBe('четверг, 29 октября')
        } finally {
          if (deviceZone === undefined) delete process.env.TZ
          else process.env.TZ = deviceZone
        }
      })

      it('keeps the date of the device when the browser does not know the zone, or an older server sent none', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date(2026, 9, 28, 12, 0, 0))

        expect((await render(today({ timezone: 'Mars/Olympus' }))).get('header p').text()).toBe('среда, 28 октября')
        expect((await render(today({ timezone: undefined as unknown as string }))).get('header p').text()).toBe('среда, 28 октября')
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
    it('shows the goal as a ring with done / target and what is left', async () => {
      const w = await render()

      expect(w.find('[data-testid=goal-card]').exists()).toBe(true)
      expect(text(w, 'goal-text')).toBe('8 из 20 слов')
      expect(text(w, 'goal-left')).toBe('Осталось 12 слов')
      expect(text(w, 'goal-done')).toBe('8')
      // 8/20 of the circumference is drawn: the dash offset is 60% of it.
      const arc = w.get('[data-testid=goal-ring-arc]')
      const circumference = Number(arc.attributes('stroke-dasharray'))
      expect(Number(arc.attributes('stroke-dashoffset'))).toBeCloseTo(circumference * 0.6, 3)
      expect(w.find('[data-testid=goal-met]').exists()).toBe(false)
    })

    it('says the unit of the goal under the ring ("words" - the same as the ring counts) and leads to the settings', async () => {
      const w = await render()

      const link = w.get('[data-testid=goal-setting]')
      expect(link.text()).toBe('Цель: 20 слов в день')
      expect(link.attributes('href')).toBe('/settings')
      expect(text(await render(today({ goal: { target: 1, done: 0, met: false } })), 'goal-setting')).toBe('Цель: 1 слово в день')
      expect(text(await render(today({ goal: { target: 5, done: 0, met: false } })), 'goal-setting')).toBe('Цель: 5 слов в день')
      setLocale('uz')
      expect(text(await render(), 'goal-setting')).toBe("Maqsad: kuniga 20 ta so'z")
    })

    it('shows a met goal with a full ring even past the target', async () => {
      const w = await render(today({ goal: { target: 20, done: 25, met: true } }))

      expect(text(w, 'goal-met')).toBe('Цель выполнена')
      expect(w.get('[data-testid=goal-met]').classes()).toContain('text-success-ink')
      expect(w.find('[data-testid=goal-left]').exists()).toBe(false)
      expect(Number(w.get('[data-testid=goal-ring-arc]').attributes('stroke-dashoffset'))).toBeCloseTo(0, 3)
      expect(w.get('[data-testid=goal-ring]').attributes('data-met')).toBe('true')
    })

    describe('nothing to do today (no review due, the quota of new words is used up)', () => {
      const allDone = (overrides: Partial<TodayResponse> = {}) => today({ due_count: 0, new_words_remaining: 0, ...overrides })
      const practiceMe = (overrides: Record<string, unknown> = {}) =>
        ({
          entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20, renewal: null, lapsed: null },
          paywall: { mode: 'coming_soon', contact: null },
          practice: { available: true, pro_required: false, modes: ['pairs'], battle: false, pro_promise: 'none', ...overrides },
        }) as never

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

      it('offers the practice as a quiet button when /me says the learner has it, and leads to the hub of the course', async () => {
        vi.mocked(api.me.get).mockResolvedValue(practiceMe())
        const w = await render(allDone())

        const button = w.get('[data-testid=practice-btn]')
        expect(button.text()).toBe('Практика')
        expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
        await button.trigger('click')
        await flushPromises()

        expect(w.router.currentRoute.value.name).toBe('practice-hub')
        expect(w.router.currentRoute.value.params.slug).toBe('english-a1')
      })

      it('opens the paywall of the practice instead while the practice is behind Pro', async () => {
        vi.mocked(api.me.get).mockResolvedValue(practiceMe({ pro_required: true }))
        const w = await render(allDone())

        await w.get('[data-testid=practice-btn]').trigger('click')
        await flushPromises()

        expect(w.router.currentRoute.value.name).not.toBe('practice-hub')
        expect(w.find('[data-testid=paywall]').exists() || w.find('[data-testid=paywall-title]').exists()).toBe(true)
      })

      it('has no practice button for a learner without the practice', async () => {
        vi.mocked(api.me.get).mockResolvedValue(practiceMe({ available: false, modes: [] }))
        const w = await render(allDone())

        expect(w.find('[data-testid=all-done]').exists()).toBe(true)
        expect(w.find('[data-testid=practice-btn]').exists()).toBe(false)
      })

      it('is not the state of a learner with a review due, an open session, or no limit of new words', async () => {
        for (const response of [today({ due_count: 4, new_words_remaining: 0 }), allDone({ open_session_id: 9 }), today({ due_count: 0, new_words_remaining: null }), today({ due_count: 0, new_words_remaining: 3 }), allDone({ active_course: null })]) {
          const w = await render(response)
          expect(w.find('[data-testid=all-done]').exists()).toBe(false)
        }
        expect((await render(today({ due_count: 4, new_words_remaining: 0 }))).find('[data-testid=review-btn]').exists()).toBe(true)
      })

      it('does not read /me for a learner who has something to do (Home asks it only for this state)', async () => {
        vi.mocked(api.me.get).mockClear()
        const w = await render(today({ entitlement: PRO }))

        expect(w.find('[data-testid=all-done]').exists()).toBe(false)
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
          active_course: { id: 3, slug: 'english-a1', title: 'English A1', progress: { learned: 0, total: 900 }, next_lesson: null },
        }))
        expect(newcomer.find('[data-testid=all-done]').exists()).toBe(true)
        expect(newcomer.find('[data-testid=all-done-pro]').exists()).toBe(false)
      })

      it('speaks Uzbek', async () => {
        setLocale('uz')

        expect(text(await render(allDone()), 'all-done')).toContain("Bugun uchun hammasi tayyor. Yangi so'zlar — ertaga")
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
      expect(button.get('path').attributes('d')).toBe(ICONS.play.solid[0]?.d)
      expect(text(w, 'session-hint')).toBe('Сначала повторение, потом новые слова из вашего курса')
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
    it('shows the streak, the warning when it is at risk and the record', async () => {
      const w = await render()

      expect(text(w, 'streak-current')).toBe('5 дней подряд')
      expect(text(w, 'streak-at-risk')).toBe('Выполните цель до полуночи, чтобы не потерять серию')
      expect(w.get('[data-testid=streak-at-risk]').classes()).toContain('text-warning-ink')
      expect(text(w, 'streak-best')).toBe('Рекорд: 12')
      expect(w.get('[data-testid=streak-card] .icon-tile').classes()).toContain('tile-orange')
    })

    it('counts days in the right Russian form', async () => {
      const streak = (current: number) => ({ current, best: current, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false })

      expect(text(await render(today({ streak: streak(1) })), 'streak-current')).toBe('1 день подряд')
      expect(text(await render(today({ streak: streak(3) })), 'streak-current')).toBe('3 дня подряд')
      expect(text(await render(today({ streak: streak(21) })), 'streak-current')).toBe('21 день подряд')
    })

    it('shows a safe streak after the goal, and an invitation when there is none, on a grey tile', async () => {
      const safe = await render(today({ streak: { current: 6, best: 6, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(safe, 'streak-safe')).toBe('На сегодня серия продлена')
      expect(safe.find('[data-testid=streak-at-risk]').exists()).toBe(false)
      expect(safe.find('[data-testid=streak-best]').exists()).toBe(false)
      expect(safe.get('[data-testid=streak-card] .icon-tile').classes()).toContain('tile-orange')

      const none = await render(today({ streak: { current: 0, best: 3, met_today: false, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(none, 'streak-current')).toBe('Серия')
      expect(text(none, 'streak-none')).toBe('Выполните цель дня, чтобы начать серию')
      expect(none.find('[data-testid=streak-at-risk]').exists()).toBe(false)
      expect(none.get('[data-testid=streak-card] .icon-tile').classes()).toContain('tile-gray')
      expect(none.get('[data-testid=streak-card] .icon-tile').classes()).not.toContain('tile-orange')
      expect(text(none, 'streak-best')).toBe('Рекорд: 3')
    })

    it('shows a Pro learner the streak freezes in stock and that yesterday was frozen', async () => {
      const two = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 2, frozen_yesterday: false } }))
      expect(text(two, 'streak-freezes')).toBe('❄️ 2 заморозки')
      expect(two.find('[data-testid=streak-frozen]').exists()).toBe(false)
      expect(two.find('[data-testid=streak-freeze-teaser]').exists()).toBe(false)

      const frozen = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: false, at_risk: true, freezes: 1, frozen_yesterday: true } }))
      expect(text(frozen, 'streak-freezes')).toBe('❄️ 1 заморозка')
      expect(text(frozen, 'streak-frozen')).toBe('Вчерашний день заморожен — серия сохранена')
      expect(text(frozen, 'streak-at-risk')).toBe('Выполните цель до полуночи, чтобы не потерять серию')

      const none = await render(today({ entitlement: PRO, streak: { current: 5, best: 12, met_today: true, at_risk: false, freezes: 0, frozen_yesterday: false } }))
      expect(text(none, 'streak-freezes')).toBe('❄️ Заморозок нет — новая в понедельник')
    })

    it('offers a Free learner the streak-freeze teaser that opens the paywall with its own lead', async () => {
      const w = await render()
      expect(w.find('[data-testid=streak-freezes]').exists()).toBe(false)
      const teaser = w.get('[data-testid=streak-freeze-teaser]')
      expect(teaser.text()).toBe('❄️ Заморозки серии — в Pro')
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

    describe('the sheet "How it works"', () => {
      it('opens once for a newcomer: four steps, "Next" / "Start"', async () => {
        const w = await render(newcomer())

        expect(w.find('[data-testid=intro-sheet]').exists()).toBe(true)
        expect(text(w, 'intro-title')).toBe('Вы видите слово и вспоминаете перевод')
        expect(text(w, 'intro-text')).toBe('Потом открываете ответ и честно отмечаете, помните ли его.')
        expect(text(w, 'intro-next')).toBe('Дальше')

        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Слова возвращаются сами')
        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Задания становятся сложнее')
        await w.get('[data-testid=intro-next]').trigger('click')
        expect(text(w, 'intro-title')).toBe('Хватит 10 минут в день')
        expect(text(w, 'intro-text')).toBe('Нажмите «Начать занятие» — остальное приложение сделает само.')
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

        expect(text(w, 'intro-title')).toBe("Siz so'zni ko'rasiz va tarjimasini eslaysiz")
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

      it('uses the Today payload for the lapse banner without a profile request', async () => {
        const initial = today()
        const w = await render(today({ entitlement: { ...initial.entitlement, lapsed: lapsedMe(true).entitlement.lapsed } }))

        expect(text(w, 'lapsed-title')).toBe('Pro закончился')
        expect(api.me.get).not.toHaveBeenCalled()

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
      expect(w.get('[data-testid=goal-text]').text()).toBe('8 из 20 слов')
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

    it('stays on the screen with the error when the session cannot be opened', async () => {
      vi.mocked(api.training.open).mockRejectedValue(rateLimited())

      const w = await renderAt('/today?from=reminder')

      expect(w.router.currentRoute.value.name).toBe('today')
      expect(w.router.currentRoute.value.query.from).toBeUndefined()
      expect(text(w, 'open-error')).toBe('Слишком много запросов. Подождите немного и повторите.')
      expect(text(w, 'goal-text')).toBe('8 из 20 слов')
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
      expect(w.findAll('[data-testid=assignment-progress]')[0]?.text()).toBe('40%')
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
      expect(w.get('[data-testid=assignment-due]').classes()).toContain('chip-danger')
    })

    it('keeps Today working when the assignments cannot be loaded', async () => {
      vi.mocked(api.assignments.list).mockRejectedValue(new ApiClientError('server', 500, 'server_error', 'boom'))

      const w = await render()

      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
      expect(text(w, 'goal-text')).toBe('8 из 20 слов')
    })

    it('does not wait for slow assignments to show Today', async () => {
      vi.mocked(api.assignments.list).mockReturnValue(new Promise(() => {}))

      const w = await render()

      expect(text(w, 'goal-text')).toBe('8 из 20 слов')
      expect(w.find('[data-testid=assignments-card]').exists()).toBe(false)
    })

    it('starts loading the assignments right after sign-in, in parallel with Today', async () => {
      let answer!: (response: TodayResponse) => void
      vi.mocked(api.today.get).mockReturnValue(new Promise<TodayResponse>((resolve) => (answer = resolve)))

      const w = mount(TodayView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      // Today has not answered yet, and the assignments are already on their way.
      expect(api.today.get).toHaveBeenCalledTimes(1)
      expect(api.assignments.list).toHaveBeenCalledTimes(1)
      expect(w.find('[data-testid=spinner]').exists()).toBe(true)

      answer(today())
      await flushPromises()
      expect(w.find('[data-testid=spinner]').exists()).toBe(false)
      expect(api.assignments.list).toHaveBeenCalledTimes(1)
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

      expect(w.get('[data-testid=course-card] .section-label').text()).toBe('Мой курс')
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
      expect(empty.text()).toContain('Основной курс не выбран')
      expect(empty.get('path').attributes('d')).toBe(ICONS['academic-cap'].outline[0]?.d)
      expect(w.find('[data-testid=next-lesson-btn]').exists()).toBe(false)
      expect(w.find('[data-testid=course-title]').exists()).toBe(false)

      const choose = w.get('[data-testid=choose-course]')
      expect(choose.text()).toBe('Выбрать курс')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted']))
      expect(choose.attributes('href')).toBe('/courses')
    })
  })

  describe('league', () => {
    it('shows the place of the week as a row that leads to the league screen', async () => {
      const w = await render(today({ league: { tier: 1, tier_key: 'silver', rank: 3, points: 41, members_count: 28 } }))

      const row = w.get('[data-testid=league-line]')
      expect(row.text()).toContain('Лига · Серебро')
      expect(row.text()).toContain('3-е место из 28 · 41 очко')
      expect(row.attributes('href')).toBe('/league')
      expect(row.get('.icon-tile').classes()).toContain('tile-yellow')
      expect(row.get('.icon-tile path').attributes('d')).toBe(ICONS.trophy.outline[0]?.d)
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
      expect(w.get('[data-testid=goal-text]').text()).toBe('8 из 20 слов')
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
    expect(w.get('header p').text()).toBe('chorshanba, 28-oktabr')
    expect(text(w, 'goal-text')).toBe("20 ta so'zdan 8 tasi")
    expect(text(w, 'review-btn')).toBe("Mashg'ulotni boshlash")
    expect(text(w, 'session-hint')).toBe("Avval oldingi so'zlarni takrorlaysiz, keyin yangilarini o'rganasiz")
    expect(text(w, 'streak-current')).toBe('5 kun ketma-ket')
    expect(text(w, 'streak-best')).toBe('Rekord: 12')
    expect(text(w, 'due-count')).toBe("Takrorlash uchun 23 ta so'z")
    expect(text(w, 'new-remaining')).toBe("Bugun yana 12 ta yangi so'z o'rganishingiz mumkin")
    expect(w.get('[data-testid=assignment]').text()).toContain('2-modul, 4-dars')
    expect(text(w, 'next-lesson-btn')).toBe("Keyingi darsni o'rganish")
    expect(text(w, 'league-line')).toContain('Liga · Kumush')
    expect(text(w, 'league-line')).toContain("30 ishtirokchi orasida 4-o'rin · 312 ochko")
  })
})
