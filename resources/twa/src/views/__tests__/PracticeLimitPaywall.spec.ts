import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeHubView from '../PracticeHubView.vue'
import BattleSetupView from '../BattleSetupView.vue'
import FriendsView from '../FriendsView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { courseDetail, courseRow, battleList, options } from '@/api/__tests__/battleFixtures'
import { counter, freeLimits, hub } from '@/api/__tests__/practiceFixtures'
import { settingsFixture } from '@/api/__tests__/settingsFixture'
import type { MeResponse, PracticeLimits } from '@/api/types'

/**
 * The screens with the REAL `PaywallSheet` (the specs of each screen stub it): what the learner reads when a free limit is spent, from the
 * press to the words - the wiring of the numbers of the limit into the sheet, in the learner's zone.
 */

vi.mock('@/api', () => ({
  api: {
    practice: { hub: vi.fn(), open: vi.fn() },
    battles: { list: vi.fn(), options: vi.fn(), invite: vi.fn() },
    friends: { get: vi.fn() },
    challenges: { list: vi.fn() },
    courses: { list: vi.fn(), get: vi.fn() },
    decks: { list: vi.fn() },
    training: { open: vi.fn() },
    me: { get: vi.fn() },
    today: { get: vi.fn() },
    billing: { catalog: vi.fn() },
    promo: { redeem: vi.fn() },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: () => () => {},
    haptic: () => {},
    notificationHaptic: () => {},
    selectionHaptic: () => {},
    shareUrl: () => {},
    webApp: { BackButton: { hide: () => {} } },
  }),
}))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))

/** 2026-11-02, 15:00 in Tashkent (UTC+5): the learner's "today" ends at 00:00 of the 3rd. */
const NOW = new Date('2026-11-02T10:00:00Z')
const MIDNIGHT = '2026-11-02T19:00:00Z'

const me = (mode: 'coming_soon' | 'purchase' = 'coming_soon', limits: PracticeLimits = freeLimits(2, 1, MIDNIGHT)): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
  paywall: { mode, contact: null },
  settings: settingsFixture(),
  practice: { available: true, pro_required: false, modes: ['pairs', 'collocation', 'sentence', 'dictation'], battle: true, pro_promise: 'both', limits },
})

const mounted: VueWrapper[] = []

function routerOf() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
      { path: '/battles/new', name: 'battle-new', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
      { path: '/friends', name: 'friends', component: stub },
      { path: '/league', name: 'league', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
}

async function mountWith(component: typeof PracticeHubView | typeof BattleSetupView | typeof FriendsView, props: Record<string, unknown> = {}) {
  const router = routerOf()
  await router.push('/friends')
  const wrapper = mount(component, { props, global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const lead = (wrapper: VueWrapper) => wrapper.get('[data-testid=paywall-lead]').text()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  // Only the clock of the date: `flushPromises` waits on timers, which must keep running.
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
  vi.mocked(api.me.get).mockResolvedValue(me())
  vi.mocked(api.today.get).mockResolvedValue({ new_words_remaining: null, due_count: 0 } as never)
  vi.mocked(api.billing.catalog).mockResolvedValue({ items: [], providers: [], offer: null, subscription: null })
  vi.mocked(api.battles.list).mockResolvedValue(battleList())
  vi.mocked(api.challenges.list).mockResolvedValue({ days: 7, open: [], finished: [] })
  vi.mocked(api.friends.get).mockResolvedValue({
    invite_link: null,
    limit: 100,
    friends: [{ id: 11, first_name: 'Dilnoza', streak: 5, words_learned: 120, since: null }],
    incoming: [],
    outgoing: [],
  })
  useTodayStore().data = { timezone: 'Asia/Tashkent', due_count: 0 } as never
})
afterEach(() => {
  while (mounted.length > 0) mounted.pop()?.unmount()
  vi.useRealTimers()
  setLocale('ru')
  document.body.innerHTML = ''
})

/** A catalog with something for sale: with it (and the mode `purchase` of `/me`) the sheet sells Pro. */
const SALE = {
  items: [{ plan: 'pro' as const, tier: null, period: 'month' as const, prices: { XTR: 150 }, list_prices: { XTR: 150 } }],
  providers: ['telegram_stars' as const],
  offer: null,
  subscription: null,
}

describe('the real paywall behind the free limits (Pro is only coming)', () => {
  it('the practice hub: the end of the free practice, in words, with the time the new one comes - and no promise of Pro right away', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(2, 1, MIDNIGHT) }))
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00. Оплата откроется позже — мы сообщим в боте.')
    expect(api.practice.open).not.toHaveBeenCalled()
  })

  it('the practice hub, "Play with a friend": the same sheet about the battles', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(0, 1, MIDNIGHT) }))
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Бесплатные баттлы на сегодня закончились (1 из 1). Новые — завтра в 00:00. Оплата откроется позже — мы сообщим в боте.')
  })

  it('the hub in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(2, 1, MIDNIGHT) }))
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe("Bugungi bepul mashqlar tugadi (2/2). Yana ertaga soat 00:00 da mashq qilishingiz mumkin. To'lov keyinroq ochiladi — botda xabar beramiz.")
  })

  it('the setup of a battle: "Invite" meets the sheet about the battles', async () => {
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [courseRow({ slug: 'english-a1', title: 'Английский A1', is_active: true })] })
    vi.mocked(api.courses.get).mockImplementation((slug) => Promise.resolve(courseDetail({ slug, title: slug })))
    vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 3, deck_words: 50, used_decks: 0 } } as never)
    vi.mocked(api.battles.options).mockResolvedValue(options())
    // The press goes to the server, which refuses with the numbers the sheet quotes.
    vi.mocked(api.battles.invite).mockRejectedValue(
      new ApiClientError('forbidden', 403, 'practice_limit_reached', 'English message', undefined, { kind: 'battles', limit: 1, used: 1, resets_at: MIDNIGHT }),
    )
    const wrapper = await mountWith(BattleSetupView, { friendshipId: 11, course: null, lessonId: null })
    expect(wrapper.get('[data-testid=battle-limit]').text()).toBe('Баттлов сегодня: осталось 0 из 1')

    await wrapper.get('[data-testid=invite-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Бесплатные баттлы на сегодня закончились (1 из 1). Новые — завтра в 00:00. Оплата откроется позже — мы сообщим в боте.')
    expect(api.battles.invite).toHaveBeenCalledTimes(1)
  })

  it('the Friends screen: "Battle" meets the same sheet', async () => {
    useBillingStore().apply(me())
    const wrapper = await mountWith(FriendsView)
    expect(wrapper.get('[data-testid=battle-limit]').text()).toBe('Баттлов сегодня: осталось 0 из 1')

    await wrapper.get('[data-testid=battle-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Бесплатные баттлы на сегодня закончились (1 из 1). Новые — завтра в 00:00. Оплата откроется позже — мы сообщим в боте.')
  })

  it('says the time in the zone of the learner: the same moment is 19:00 for one in UTC', async () => {
    useTodayStore().data = { timezone: 'UTC', due_count: 0 } as never
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(2, 1, MIDNIGHT) }))
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toContain('Новая — в 19:00.')
  })
})

describe('the real paywall behind the free limits (Pro is for sale)', () => {
  beforeEach(() => {
    vi.mocked(api.me.get).mockResolvedValue(me('purchase'))
    vi.mocked(api.billing.catalog).mockResolvedValue(SALE)
  })

  it('the practice hub: "or right away with Pro", then what Pro gives', async () => {
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: freeLimits(2, 1, MIDNIGHT) }))
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe(
      'Бесплатная практика на сегодня закончилась (2 из 2). Новая — завтра в 00:00 или сразу с Pro. ' +
        'Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.',
    )
    expect(wrapper.findAll('[data-testid=benefit]').map((item) => item.text())).toContain('Практика и баттлы без дневного лимита')
  })

  it('the Friends screen: the same about the battles, in Uzbek', async () => {
    setLocale('uz')
    useBillingStore().apply(me('purchase'))
    const wrapper = await mountWith(FriendsView)

    await wrapper.get('[data-testid=battle-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toContain('Bugungi bepul bellashuvlar tugadi (1 tadan 1 tasi). Yangilari — ertaga soat 00:00 da yoki darhol Pro bilan.')
    expect(wrapper.findAll('[data-testid=benefit]').map((item) => item.text())).toContain('Mustahkamlash mashqlari va bellashuvlar cheklovsiz')
  })
})

// A limit of 0 is "only Pro": the sheet says what Pro has, with no "over", no "0 of 0" and no time of a reset. For the sessions of practice
// the sentence names the battles only when they are for Pro only too; the screens and the sheet read the same counters (/me brings them).
describe('the real paywall behind a kind that is for Pro only (limit 0)', () => {
  const NO_SESSIONS: PracticeLimits = { sessions: counter(0, 0, MIDNIGHT), battles: null }
  const NO_BATTLES: PracticeLimits = { sessions: null, battles: counter(0, 0, MIDNIGHT) }
  const NO_BOTH: PracticeLimits = { sessions: counter(0, 0, MIDNIGHT), battles: counter(0, 0, MIDNIGHT) }
  const SALE_RU =
    'Новые слова без дневного лимита, упражнения на написание, свои колоды, заморозки серии и пробные тесты.'
  const SALE_UZ = "Istagancha yangi so'z, yozma mashqlar, shaxsiy to'plamlar, zaxira kunlar va sinov imtihonlari."
  const COMBINED_RU = 'Практика — игры на закрепление слов и баттлы с друзьями — доступна в Pro.'
  const SOLO_RU = 'Практика — игры на закрепление слов — доступна в Pro.'
  const COMBINED_UZ = "So'zlarni mashqlar bilan mustahkamlash va do'stlar bilan bellashish Pro'da bor."
  const SOLO_UZ = "So'zlarni mustahkamlash mashqlari Pro'da bor."

  /** The server tells the same counters on the hub and on /me. */
  const serve = (limits: PracticeLimits, mode: 'coming_soon' | 'purchase' = 'coming_soon') => {
    vi.mocked(api.me.get).mockResolvedValue(me(mode, limits))
    vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits }))
  }
  const pressPairs = async (wrapper: VueWrapper) => {
    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    await flushPromises()
  }

  it('the practice hub, sessions, while Free gets the battles: the practice alone is in Pro - no line before it, no battles and no "over" in it', async () => {
    serve(NO_SESSIONS)
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })
    expect(wrapper.find('[data-testid=practice-limit]').exists()).toBe(false)

    await pressPairs(wrapper)

    expect(lead(wrapper)).toBe(`${SOLO_RU} Оплата откроется позже — мы сообщим в боте.`)
    expect(lead(wrapper)).not.toMatch(/баттл/)
    expect(api.practice.open).not.toHaveBeenCalled()
  })

  it('the practice hub, sessions, while the battles are for Pro only too: practice and battles with friends are in Pro', async () => {
    serve(NO_BOTH)
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await pressPairs(wrapper)

    expect(lead(wrapper)).toBe(`${COMBINED_RU} Оплата откроется позже — мы сообщим в боте.`)
  })

  it('the practice hub, "Play with a friend": calling a friend is in Pro, accepting is always possible', async () => {
    serve(NO_BATTLES)
    const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Звать друзей на баттл можно в Pro. Принять приглашение друга можно всегда. Оплата откроется позже — мы сообщим в боте.')
    expect(lead(wrapper)).not.toMatch(/закончил|завтра|из 0/)
  })

  it('the setup of a battle: no line, and "Invite" meets the same sheet', async () => {
    vi.mocked(api.me.get).mockResolvedValue(me('coming_soon', NO_BATTLES))
    vi.mocked(api.courses.list).mockResolvedValue({ courses: [courseRow({ slug: 'english-a1', title: 'Английский A1', is_active: true })] })
    vi.mocked(api.courses.get).mockImplementation((slug) => Promise.resolve(courseDetail({ slug, title: slug })))
    vi.mocked(api.decks.list).mockResolvedValue({ decks: [], limits: { decks: 3, deck_words: 50, used_decks: 0 } } as never)
    vi.mocked(api.battles.options).mockResolvedValue(options())
    vi.mocked(api.battles.invite).mockRejectedValue(
      new ApiClientError('forbidden', 403, 'practice_limit_reached', 'English message', undefined, { kind: 'battles', limit: 0, used: 0, resets_at: MIDNIGHT }),
    )
    const wrapper = await mountWith(BattleSetupView, { friendshipId: 11, course: null, lessonId: null })
    expect(wrapper.find('[data-testid=battle-limit]').exists()).toBe(false)

    await wrapper.get('[data-testid=invite-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe('Звать друзей на баттл можно в Pro. Принять приглашение друга можно всегда. Оплата откроется позже — мы сообщим в боте.')
    expect(api.battles.invite).toHaveBeenCalledTimes(1)
  })

  it('the Friends screen, in Uzbek: the same words', async () => {
    setLocale('uz')
    vi.mocked(api.me.get).mockResolvedValue(me('coming_soon', NO_BATTLES))
    useBillingStore().apply(me('coming_soon', NO_BATTLES))
    const wrapper = await mountWith(FriendsView)
    expect(wrapper.find('[data-testid=battle-limit]').exists()).toBe(false)

    await wrapper.get('[data-testid=battle-btn]').trigger('click')
    await flushPromises()

    expect(lead(wrapper)).toBe("Do'stni bellashuvga chaqirish Pro'da mavjud. Do'stning taklifini qabul qilish har doim mumkin. To'lov keyinroq ochiladi — botda xabar beramiz.")
    expect(lead(wrapper)).not.toMatch(/tugadi|ertaga|0 tadan/)
  })

  it('the hub in Uzbek, sessions: the practice alone, or with the battles when they are for Pro only too', async () => {
    setLocale('uz')
    serve(NO_SESSIONS)
    const alone = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })
    await pressPairs(alone)
    expect(lead(alone)).toBe(`${SOLO_UZ} To'lov keyinroq ochiladi — botda xabar beramiz.`)
    expect(lead(alone)).not.toMatch(/bellashuv/)

    setActivePinia(createPinia())
    useTodayStore().data = { timezone: 'Asia/Tashkent', due_count: 0 } as never
    serve(NO_BOTH)
    const both = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })
    await pressPairs(both)
    expect(lead(both)).toBe(`${COMBINED_UZ} To'lov keyinroq ochiladi — botda xabar beramiz.`)
  })

  describe('while Pro is for sale', () => {
    beforeEach(() => {
      vi.mocked(api.billing.catalog).mockResolvedValue(SALE)
    })

    it('the Friends screen: what Pro has, then what Pro gives - and not "or right away"', async () => {
      vi.mocked(api.me.get).mockResolvedValue(me('purchase', NO_BATTLES))
      useBillingStore().apply(me('purchase', NO_BATTLES))
      const wrapper = await mountWith(FriendsView)

      await wrapper.get('[data-testid=battle-btn]').trigger('click')
      await flushPromises()

      expect(lead(wrapper)).toBe(`Звать друзей на баттл можно в Pro. Принять приглашение друга можно всегда. ${SALE_RU}`)
      expect(lead(wrapper)).not.toContain('сразу с Pro')
    })

    it('the hub, in Uzbek, sessions: the practice alone, then what Pro gives', async () => {
      setLocale('uz')
      serve(NO_SESSIONS, 'purchase')
      const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

      await pressPairs(wrapper)

      expect(lead(wrapper)).toBe(`${SOLO_UZ} ${SALE_UZ}`)
    })

    it('the hub, sessions and battles both for Pro only: both in the sentence, then what Pro gives', async () => {
      serve(NO_BOTH, 'purchase')
      const wrapper = await mountWith(PracticeHubView, { slug: 'english-a1', lessonId: 41 })

      await pressPairs(wrapper)

      expect(lead(wrapper)).toBe(`${COMBINED_RU} ${SALE_RU}`)
    })
  })
})
