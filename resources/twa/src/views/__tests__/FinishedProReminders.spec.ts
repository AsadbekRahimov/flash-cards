import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import FinishedView from '../FinishedView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { FINISHED_BEFORE_KEY, LAPSED_DISMISSED_KEY, PRO_TIP_KEY, PRO_TIP_SNOOZE_MS } from '@/composables/proReminders'
import { isSnoozed, markSeen, resetSeenSession } from '@/composables/seenFlag'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import type { SessionSummary, TodayResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({
  api: { achievements: { list: vi.fn() }, league: { monthMe: vi.fn() }, me: { get: vi.fn() }, promo: { redeem: vi.fn() }, today: { get: vi.fn() }, training: { open: vi.fn(), finish: vi.fn() } },
}))

vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ close: vi.fn(), notificationHaptic: vi.fn(), haptic: vi.fn() }) }))

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'landing', component: { template: '<div />' } },
    { path: '/today', name: 'today', component: { template: '<div />' } },
    { path: '/league/month', name: 'league-month', component: { template: '<div />' } },
    { path: '/referrals', name: 'referrals', component: { template: '<div />' } },
    { path: '/achievements', name: 'achievements', component: { template: '<div />' } },
    { path: '/courses', name: 'courses', component: { template: '<div />' } },
    { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
    { path: '/practice/:slug', name: 'practice-hub', component: { template: '<div />' } },
  ],
})

const DAY = 86_400_000
const NOON = Date.parse('2026-11-17T09:00:00Z')

type Plan = { plan: 'free' | 'pro'; source: string; until: string | null; renewal?: unknown; lapsed?: unknown }

/** The learner as `/today` and `/me` tell it. */
function learner(plan: Plan, opts: { mode?: 'coming_soon' | 'purchase'; practice?: string; game?: string } = {}): void {
  const entitlement = { ...plan, new_words_per_day: plan.plan === 'pro' ? null : 20 }
  useTodayStore().data = {
    entitlement: { plan: plan.plan, source: plan.source, until: plan.until, lapsed: plan.lapsed ?? null },
    timezone: 'Asia/Tashkent',
  } as unknown as TodayResponse
  vi.mocked(api.me.get).mockResolvedValue({
    learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
    groups: [],
    stats: { words_learned: 0, words_due_today: 0 },
    entitlement,
    paywall: { mode: opts.mode ?? 'coming_soon', contact: null },
    settings: settingsFixture(),
    practice: { available: true, pro_required: false, modes: ['pairs'], battle: opts.practice === 'both', pro_promise: opts.practice ?? 'none' },
    game: { available: true, pro_promise: opts.game ?? 'none', coins: 0, tickets: 0 },
  } as never)
}

const FREE: Plan = { plan: 'free', source: 'free', until: null }
const trial = (daysLeft: number): Plan => ({
  plan: 'pro',
  source: 'subscription',
  until: new Date(NOON + daysLeft * DAY - 3_600_000).toISOString(),
  renewal: { auto: false, provider: 'trial', ends_at: new Date(NOON + daysLeft * DAY - 3_600_000).toISOString(), reminder_sent: false },
})

const summary = (overrides: Partial<SessionSummary> = {}): SessionSummary => ({
  reviewed: 12,
  correct: 10,
  new_words: 4,
  goal: { target: 20, done: 12, met: false },
  streak: { current: 3, best: 7, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false },
  ...overrides,
})

async function finish(opts: { reason?: string | null; summary?: SessionSummary | null; mode?: 'daily_review' | 'group_lesson' } = {}) {
  const training = useTrainingStore()
  const given = opts.summary === undefined ? summary() : opts.summary
  training.summary = given
  training.mode = opts.mode ?? 'daily_review'
  const w = mount(FinishedView, { props: { reviewed: 12, nextInDays: 1, reason: opts.reason ?? null }, global: { plugins: [router] } })
  await flushPromises()
  return w
}

const tip = (w: Awaited<ReturnType<typeof finish>>) => w.find('[data-testid=pro-tip]')

describe('FinishedView — reminders about Pro', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
    resetSeenSession()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOON)
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('reduce'), media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() }))
    vi.mocked(api.achievements.list).mockRejectedValue(new Error('offline'))
    vi.mocked(api.league.monthMe).mockRejectedValue(new Error('offline'))
  })
  afterEach(() => {
    setLocale('ru')
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  describe('the tip (pro-tip)', () => {
    it('is a card for a Free learner who studied before: one benefit, two buttons, and the first the one that works', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      expect(tip(w).exists()).toBe(true)
      expect(w.get('[data-testid=pro-tip-text]').text()).toBe('В Pro новые слова без дневного лимита')
      expect(tip(w).text()).toContain('Совет о Pro')
      // payment is not open (`coming_soon`): the invitation is the main button, "More about Pro" the second
      const buttons = w.get('[data-testid=pro-tip-actions]').findAll('.btn')
      expect(buttons.map((b) => b.attributes('data-testid'))).toEqual(['pro-tip-invite', 'pro-tip-details'])
      expect(buttons[0]!.text()).toBe('Получить Pro за друзей')
      expect(buttons[0]!.classes()).toContain('btn-tinted')
      expect(buttons[1]!.text()).toBe('Подробнее о Pro')
      expect(buttons[1]!.classes()).toContain('btn-secondary')
      expect(w.get('[data-testid=pro-tip-invite]').attributes('href')).toBe('/referrals')
    })

    it('puts "More about Pro" first once payment is open', async () => {
      learner(FREE, { mode: 'purchase' })
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      const buttons = w.get('[data-testid=pro-tip-actions]').findAll('.btn')
      expect(buttons.map((b) => b.attributes('data-testid'))).toEqual(['pro-tip-details', 'pro-tip-invite'])
      expect(buttons[0]!.classes()).toContain('btn-tinted')
      expect(buttons[1]!.classes()).toContain('btn-secondary')
    })

    it('opens the sheet about Pro only when the learner presses the button', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()
      expect(w.find('[data-testid=paywall]').exists()).toBe(false)

      await w.get('[data-testid=pro-tip-details]').trigger('click')
      await flushPromises()

      expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    })

    it('stands under the buttons, so that "Done" and "One more round" stay on the first screen', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const training = useTrainingStore()
      training.canContinue = true
      const w = await finish({ reason: 'sprint_complete' })

      const html = w.html()
      expect(html.indexOf('data-testid="next-round-btn"')).toBeGreaterThan(-1)
      expect(html.indexOf('data-testid="pro-tip"')).toBeGreaterThan(html.indexOf('data-testid="next-round-btn"'))
      expect(html.indexOf('data-testid="pro-tip"')).toBeGreaterThan(html.indexOf('data-testid="close-btn"'))
      // not between the stats and the buttons
      expect(html.indexOf('data-testid="pro-tip"')).toBeGreaterThan(html.indexOf('data-testid="home-btn"'))
      expect(w.get('[data-testid=rows]').find('[data-testid=pro-tip]').exists()).toBe(false)
    })

    it('goes round the benefits, one on each day, and starts again', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const seen: string[] = []
      for (let day = 0; day < 4; day += 1) {
        vi.setSystemTime(NOON + day * DAY)
        setActivePinia(createPinia())
        learner(FREE)
        seen.push((await finish()).get('[data-testid=pro-tip-text]').text())
      }

      expect(seen).toEqual([
        'В Pro новые слова без дневного лимита',
        'В Pro пропущенный день не обрывает серию — есть заморозки',
        'В Pro есть упражнения на написание слова',
        'В Pro новые слова без дневного лимита',
      ])
    })

    it('names the practice and the battles only where the server says Pro lifts them, and the caravan only while the game is open', async () => {
      markSeen(FINISHED_BEFORE_KEY)
      const seen: string[] = []
      for (let day = 0; day < 5; day += 1) {
        vi.setSystemTime(NOON + day * DAY)
        setActivePinia(createPinia())
        learner(FREE, { practice: 'both', game: 'full' })
        seen.push((await finish()).get('[data-testid=pro-tip-text]').text())
      }
      expect(seen).toEqual([
        'В Pro новые слова без дневного лимита',
        'В Pro пропущенный день не обрывает серию — есть заморозки',
        'В Pro есть упражнения на написание слова',
        'В Pro практика и баттлы без дневного лимита',
        'В Pro караван без дневного лимита',
      ])

      resetSeenSession()
      localStorage.clear()
      markSeen(FINISHED_BEFORE_KEY)
      const solo: string[] = []
      for (let day = 0; day < 4; day += 1) {
        vi.setSystemTime(NOON + (10 + day) * DAY)
        setActivePinia(createPinia())
        learner(FREE, { practice: 'solo' })
        solo.push((await finish()).get('[data-testid=pro-tip-text]').text())
      }
      expect(solo[3]).toBe('В Pro практика без дневного лимита')
      expect(solo.join(' ')).not.toContain('баттл')
      expect(solo.join(' ')).not.toContain('караван')
    })

    it('comes once in a local day', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      expect(tip(await finish()).exists()).toBe(true)

      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(false)

      // 20:00 UTC is already the next day in Tashkent (UTC+5): the local day decides, not the UTC one
      vi.setSystemTime(Date.parse('2026-11-17T20:00:00Z'))
      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(true)
    })

    it('does not count a day for a tip that was not shown', async () => {
      learner(FREE, { mode: 'purchase' })
      const first = await finish({ summary: summary({ streak: { current: 1, best: 1, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false } }) })
      expect(tip(first).exists()).toBe(false)

      // the second session of the same day (the device has finished one) is not the first any more
      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(true)
    })

    it('is not in the first session of the device, nor of a learner with a short streak', async () => {
      learner(FREE)
      const w = await finish({ summary: summary({ streak: { current: 1, best: 1, met_today: false, at_risk: true, freezes: 0, frozen_yesterday: false } }) })
      expect(tip(w).exists()).toBe(false)
      expect(api.me.get).not.toHaveBeenCalled()
    })

    it('is shown to a learner on a new device whose streak says they studied before', async () => {
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(true)
    })

    it('is not next to the card of the end of the new words, which already speaks of Pro', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish({ reason: 'new_quota_exhausted' })

      expect(w.find('[data-testid=quota-card]').exists()).toBe(true)
      expect(tip(w).exists()).toBe(false)
    })

    it('is not on top of the banner "trial ended" of Today, until it is closed there', async () => {
      markSeen(FINISHED_BEFORE_KEY)
      const lapsed = { provider: 'trial', ended_at: '2026-11-15T10:00:00Z' }
      learner({ ...FREE, lapsed })
      expect(tip(await finish()).exists()).toBe(false)

      localStorage.setItem(LAPSED_DISMISSED_KEY, lapsed.ended_at)
      setActivePinia(createPinia())
      learner({ ...FREE, lapsed })
      expect(tip(await finish()).exists()).toBe(true)
    })

    it('hides for 3 days with the cross, and comes back after them', async () => {
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      await w.get('[data-testid=pro-tip-close]').trigger('click')
      expect(tip(w).exists()).toBe(false)
      expect(PRO_TIP_SNOOZE_MS).toBe(3 * DAY)
      expect(isSnoozed(PRO_TIP_KEY)).toBe(true)

      vi.setSystemTime(NOON + 2 * DAY)
      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(false)

      vi.setSystemTime(NOON + 3 * DAY + 60_000)
      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish()).exists()).toBe(true)
    })

    it.each([
      ['a paid Pro', { plan: 'pro', source: 'subscription', until: '2027-05-01T00:00:00Z', renewal: { auto: true, provider: 'telegram_stars', ends_at: '2027-05-01T00:00:00Z', reminder_sent: false } } as Plan],
      ['a Pro of a center', { plan: 'pro', source: 'organization', until: null } as Plan],
      ['a Pro without an end', { plan: 'pro', source: 'subscription', until: null } as Plan],
    ])('is not for %s', async (_who, plan) => {
      learner(plan)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      expect(tip(w).exists()).toBe(false)
      expect(w.find('[data-testid=trial-ending]').exists()).toBe(false)
    })

    it('is not in a group lesson, after an empty session, or without the server summary', async () => {
      markSeen(FINISHED_BEFORE_KEY)
      learner(FREE)
      expect(tip(await finish({ mode: 'group_lesson' })).exists()).toBe(false)

      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish({ summary: summary({ reviewed: 0, correct: 0, new_words: 0 }), reason: 'nothing_due' })).exists()).toBe(false)

      setActivePinia(createPinia())
      learner(FREE)
      expect(tip(await finish({ summary: null })).exists()).toBe(false)
    })

    it('is not there while the plan is unknown (a failed /me), and the screen stays', async () => {
      learner(FREE)
      vi.mocked(api.me.get).mockRejectedValue(new Error('offline'))
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      expect(tip(w).exists()).toBe(false)
      expect(w.find('[data-testid=finished-view]').exists()).toBe(true)
    })

    it('speaks Uzbek', async () => {
      setLocale('uz')
      learner(FREE)
      markSeen(FINISHED_BEFORE_KEY)
      const w = await finish()

      expect(w.get('[data-testid=pro-tip-text]').text()).toBe("Pro'da yangi so'zlar kunlik cheklovsiz")
      expect(w.get('[data-testid=pro-tip-invite]').text()).toBe("Do'stlar orqali Pro olish")
      expect(w.get('[data-testid=pro-tip-details]').text()).toBe('Pro haqida batafsil')
    })
  })

  describe('the end of the trial (trial-ending)', () => {
    it('says how many days are left and offers the two ways to keep Pro; the invitation first while payment is not open', async () => {
      learner(trial(2))
      const w = await finish()

      const card = w.get('[data-testid=trial-ending]')
      expect(card.text()).toContain('Пробный Pro закончится через 2 дн.')
      expect(card.text()).toContain('Чтобы сохранить Pro, пригласите друзей.')
      expect(card.text()).not.toContain('подписку')
      expect(card.findAll('.btn').map((b) => b.attributes('data-testid'))).toEqual(['trial-ending-invite', 'trial-ending-pro'])
      expect(w.get('[data-testid=trial-ending-invite]').text()).toBe('Пригласить друзей')
      expect(w.get('[data-testid=trial-ending-pro]').text()).toBe('Подробнее о Pro')
      expect(tip(w).exists()).toBe(false)
    })

    it('with payment open: "Get Pro" first, and the text names the subscription', async () => {
      learner(trial(2), { mode: 'purchase' })
      const w = await finish()

      const card = w.get('[data-testid=trial-ending]')
      expect(card.text()).toContain('Чтобы сохранить Pro, оформите подписку или пригласите друзей.')
      expect(card.findAll('.btn').map((b) => b.attributes('data-testid'))).toEqual(['trial-ending-pro', 'trial-ending-invite'])
      expect(w.get('[data-testid=trial-ending-pro]').text()).toBe('Оформить Pro')
      expect(w.get('[data-testid=trial-ending-pro]').classes()).toContain('btn-tinted')

      await w.get('[data-testid=trial-ending-pro]').trigger('click')
      await flushPromises()
      expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    })

    it('says "today" on the last day', async () => {
      learner(trial(1))
      const w = await finish()

      expect(w.get('[data-testid=trial-ending]').text()).toContain('Пробный Pro заканчивается сегодня.')
    })

    it('is not there with more than 2 days left', async () => {
      learner(trial(3))
      const w = await finish()

      expect(w.find('[data-testid=trial-ending]').exists()).toBe(false)
      expect(tip(w).exists()).toBe(false)
    })

    it('comes once in a local day', async () => {
      learner(trial(2))
      expect((await finish()).find('[data-testid=trial-ending]').exists()).toBe(true)

      setActivePinia(createPinia())
      learner(trial(2))
      expect((await finish()).find('[data-testid=trial-ending]').exists()).toBe(false)

      vi.setSystemTime(Date.parse('2026-11-17T20:00:00Z'))
      setActivePinia(createPinia())
      learner(trial(1))
      expect((await finish()).find('[data-testid=trial-ending]').exists()).toBe(true)
    })

    it('stands under the buttons too, and is not in a group lesson or after an empty session', async () => {
      learner(trial(2))
      const w = await finish()
      expect(w.html().indexOf('data-testid="trial-ending"')).toBeGreaterThan(w.html().indexOf('data-testid="close-btn"'))

      setActivePinia(createPinia())
      learner(trial(2))
      expect((await finish({ mode: 'group_lesson' })).find('[data-testid=trial-ending]').exists()).toBe(false)
      setActivePinia(createPinia())
      learner(trial(2))
      expect((await finish({ summary: summary({ reviewed: 0, correct: 0, new_words: 0 }), reason: 'nothing_due' })).find('[data-testid=trial-ending]').exists()).toBe(false)
    })

    it('speaks Uzbek', async () => {
      setLocale('uz')
      learner(trial(2))
      const w = await finish()

      expect(w.get('[data-testid=trial-ending]').text()).toContain('Pro sinov muddati 2 kundan keyin tugaydi.')
      expect(w.get('[data-testid=trial-ending-invite]').text()).toBe("Do'stlarni taklif qilish")
    })
  })

  it('keeps the buttons of the screen and the other cards where they were', async () => {
    learner(FREE)
    markSeen(FINISHED_BEFORE_KEY)
    useTrainingStore().canContinue = true
    const w = await finish({ reason: 'sprint_complete' })

    for (const id of ['next-round-btn', 'home-btn', 'close-btn', 'stats', 'goal', 'next']) {
      expect(w.find(`[data-testid=${id}]`).exists(), id).toBe(true)
    }
  })
})
