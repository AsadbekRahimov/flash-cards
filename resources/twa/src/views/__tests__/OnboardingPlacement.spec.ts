import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import OnboardingView from '../OnboardingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { onboardingFixture } from '@/api/__tests__/onboardingFixture'
import { setLocale } from '@/i18n'
import type { OnboardingState, PlacementResult, PlacementTest } from '@/api/onboardingTypes'
import type { Learner } from '@/api/types'

/*
 * The placement test of O5 (docs/40 §2.1a, T15): the button of O5 when the server offers it, 10 questions on the segments, the answers to
 * the server all at once (the client never knows the right option), the result into the choice of O5, resume / expiry / errors. The API
 * is counted in every scenario.
 */

const tg = vi.hoisted(() => ({ back: null as null | (() => void), hide: vi.fn() }))
const motion = vi.hoisted(() => ({ reduced: true }))
const auth = vi.hoisted(() => ({ ensure: vi.fn(), learner: null as Learner | null }))

vi.mock('@/api', () => ({
  api: { onboarding: { get: vi.fn(), saveStep: vi.fn(), complete: vi.fn(), placement: vi.fn(), placementAnswers: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: auth.ensure,
    get learner() {
      return auth.learner
    },
  }),
}))

vi.mock('@/composables/motion', () => ({ prefersReducedMotion: () => motion.reduced }))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    webApp: { BackButton: { hide: tg.hide } },
    selectionHaptic: () => {},
    haptic: () => {},
    showBackButton: (handler: () => void) => {
      tg.back = handler
      return () => {
        if (tg.back === handler) tg.back = null
      }
    },
  }),
}))

const LEVELS = ['A1', 'A1', 'A2', 'A2', 'B1', 'B1', 'B2', 'B2', 'C1', 'C1']

function placementTest(prefix = 'w', expiresInMs = 20 * 60_000): PlacementTest {
  return {
    questions: LEVELS.map((_, i) => ({ id: i + 1, word: `${prefix}${i + 1}`, options: ['один', 'два', 'три', 'четыре'] })),
    expires_at: new Date(Date.now() + expiresInMs).toISOString(),
  }
}

const RESULT: PlacementResult = { level: 'B1', course_slug: 'english-b1', correct_by_level: { A1: 2, A2: 1, B1: 1, B2: 0, C1: 0 } }

const withTest = (): OnboardingState => {
  const state = onboardingFixture({ step: 'level' })
  return { ...state, options: { ...state.options, placement_available: true } }
}

function apiCalls(): Record<string, number> {
  const calls: Record<string, number> = {
    get: vi.mocked(api.onboarding.get).mock.calls.length,
    saveStep: vi.mocked(api.onboarding.saveStep).mock.calls.length,
    placement: vi.mocked(api.onboarding.placement).mock.calls.length,
    answers: vi.mocked(api.onboarding.placementAnswers).mock.calls.length,
  }
  return Object.fromEntries(Object.entries(calls).filter(([, count]) => count > 0))
}

async function render(path: string, state: OnboardingState = withTest()) {
  vi.mocked(api.onboarding.get).mockResolvedValue(state)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div data-testid="today" />' } },
      {
        path: '/onboarding/:step?',
        name: 'onboarding',
        component: OnboardingView,
        props: (route) => ({ step: typeof route.params.step === 'string' && route.params.step !== '' ? route.params.step : null }),
      },
    ],
  })
  await router.push(path)
  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>

async function tap(r: Rendered, selector: string): Promise<void> {
  await r.wrapper.get(selector).trigger('click')
  await flushPromises()
}

/** Answers `count` questions with the option `option`. */
async function answer(r: Rendered, count: number, option = 0): Promise<void> {
  for (let i = 0; i < count; i++) await tap(r, `[data-testid=placement-options] [data-testid=option-${option}]`)
}

const word = (r: Rendered): string => r.wrapper.get('[data-testid=placement-word]').text()

describe('the placement test of O5', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
    tg.back = null
    motion.reduced = true
    auth.ensure.mockResolvedValue(undefined)
    auth.learner = { id: 1, first_name: 'Aziz', locale: 'ru', onboarding_completed: false, onboarding: { completed: false, step: 'level' } }
    vi.mocked(api.onboarding.placement).mockImplementation(async () => placementTest())
    vi.mocked(api.onboarding.placementAnswers).mockResolvedValue(RESULT)
  })
  afterEach(() => {
    vi.useRealTimers()
    setLocale('ru')
  })

  it('O5 offers "Пройти тест за 2 минуты" only when the server does', async () => {
    const without = await render('/onboarding/level', onboardingFixture({ step: 'level' }))
    expect(without.wrapper.find('[data-testid=onboarding-secondary]').exists()).toBe(false)
    expect(without.wrapper.get('[data-testid=onboarding-lead]').text()).toBe('Выберите курс — сменить его можно в любой момент.')
    without.wrapper.unmount()

    setActivePinia(createPinia())
    const r = await render('/onboarding/level')
    expect(r.wrapper.get('[data-testid=onboarding-lead]').text()).toBe('Не уверены — пройдите тест за 2 минуты.')
    expect(r.wrapper.get('[data-testid=onboarding-secondary]').text()).toBe('Пройти тест за 2 минуты')
    expect(r.wrapper.get('[data-testid=onboarding-secondary]').classes()).toContain('btn-ghost')
    expect(apiCalls()).toEqual({ get: 2 })
  })

  it('asks a set of 10 questions once and shows the first: its segments, "1 / 10", the word, 4 options', async () => {
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')

    expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/placement')
    expect(r.wrapper.find('[data-testid=onboarding-progress]').exists()).toBe(false)
    expect(r.wrapper.findAll('[data-testid=placement-progress] .segment')).toHaveLength(10)
    expect(r.wrapper.get('[data-testid=placement-counter]').text()).toBe('1 / 10')
    expect(r.wrapper.get('[data-testid=placement-progress] [role=progressbar]').attributes('aria-label')).toBe('Вопрос 1 из 10')
    expect(word(r)).toBe('w1')
    expect(r.wrapper.get('[data-testid=placement-word]').attributes('lang')).toBe('en')
    expect(r.wrapper.findAll('[data-testid=placement-options] [role=radio]').map((o) => o.get('[data-testid=option-label]').text())).toEqual([
      'один',
      'два',
      'три',
      'четыре',
    ])
    expect(tg.back).not.toBeNull()
    expect(apiCalls()).toEqual({ get: 1, placement: 1 })
  })

  it('marks the pick as chosen - never right or wrong - and then shows the next question', async () => {
    motion.reduced = false
    vi.useFakeTimers()
    const r = await render('/onboarding/placement')

    await r.wrapper.get('[data-testid=placement-options] [data-testid=option-2]').trigger('click')
    expect(r.wrapper.get('[data-testid=option-2]').attributes('data-state')).toBe('selected')
    expect(r.wrapper.find('[data-state=correct]').exists()).toBe(false)
    expect(r.wrapper.find('[data-state=incorrect]').exists()).toBe(false)
    // A second tap meanwhile changes nothing.
    await r.wrapper.get('[data-testid=placement-options] [data-testid=option-1]').trigger('click')

    await vi.advanceTimersByTimeAsync(300)
    expect(word(r)).toBe('w2')
    expect(r.wrapper.get('[data-testid=placement-counter]').text()).toBe('2 / 10')
    expect(r.wrapper.find('[data-state=selected]').exists()).toBe(false)
    expect(apiCalls()).toEqual({ get: 1, placement: 1 })
  })

  it('sends the 10 answers at once, shows the level and its course and puts it into O5, where the learner can change it', async () => {
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')

    await answer(r, 9, 1)
    expect(api.onboarding.placementAnswers).not.toHaveBeenCalled()
    await answer(r, 1, 3)

    expect(api.onboarding.placementAnswers).toHaveBeenCalledWith([1, 1, 1, 1, 1, 1, 1, 1, 1, 3])
    expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Ваш уровень: B1')
    expect(r.wrapper.get('[data-testid=placement-course]').text()).toContain('English B1')
    expect(r.wrapper.get('[data-testid=placement-correct]').text()).toBe('Верных ответов: 4 из 10')
    expect(r.wrapper.get('[data-testid=onboarding-continue]').text()).toBe('Продолжить с B1')

    await tap(r, '[data-testid=onboarding-continue]')
    expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    expect(r.wrapper.get('[data-testid=level-B1]').attributes('aria-checked')).toBe('true')
    expect(r.wrapper.get('[data-testid=level-by-test]').text()).toBe('Примерный уровень по тесту — можно сменить.')
    expect(r.wrapper.get('[data-testid=onboarding-continue]').text()).toBe('Начать с B1')

    await tap(r, '[data-testid=level-C1]')
    expect(r.wrapper.find('[data-testid=level-by-test]').exists()).toBe(false)
    // Nothing is saved by the test: the level is saved by "Start with …" of O5, as always.
    expect(apiCalls()).toEqual({ get: 1, placement: 1, answers: 1 })
  })

  it('Telegram\'s Back leaves the test for O5; the test resumes at the next question without a new set', async () => {
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')
    await answer(r, 3)

    tg.back?.()
    await flushPromises()
    expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    expect(r.wrapper.find('[data-testid=onboarding-progress]').exists()).toBe(true)

    await tap(r, '[data-testid=onboarding-secondary]')
    expect(word(r)).toBe('w4')
    expect(r.wrapper.get('[data-testid=placement-counter]').text()).toBe('4 / 10')
    expect(apiCalls()).toEqual({ get: 1, placement: 1 })
  })

  it('a set that has expired meanwhile is not resumed: a new one is asked', async () => {
    vi.mocked(api.onboarding.placement).mockImplementationOnce(async () => placementTest('old', -1000))
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')
    await answer(r, 2)
    tg.back?.()
    await flushPromises()

    await tap(r, '[data-testid=onboarding-secondary]')
    expect(word(r)).toBe('w1')
    expect(apiCalls()).toEqual({ get: 1, placement: 2 })
  })

  it('410 placement_expired: «Время теста вышло» and "Начать заново" asks a new set', async () => {
    vi.mocked(api.onboarding.placementAnswers).mockRejectedValueOnce(new ApiClientError('gone', 410, 'placement_expired', 'expired'))
    const r = await render('/onboarding/placement')
    await answer(r, 10)

    expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Время теста вышло')
    expect(r.wrapper.get('[data-testid=onboarding-continue]').text()).toBe('Начать заново')
    expect(r.wrapper.get('[data-testid=onboarding-secondary]').text()).toBe('Выбрать уровень')
    await tap(r, '[data-testid=onboarding-continue]')

    expect(word(r)).toBe('w1')
    expect(r.wrapper.get('[data-testid=placement-counter]').text()).toBe('1 / 10')
    expect(apiCalls()).toEqual({ get: 1, placement: 2, answers: 1 })
  })

  it('«Не знаю» skips a question (null), and a shorter set with 2-3 options is answered the same way: one request with the answers', async () => {
    vi.mocked(api.onboarding.placement).mockResolvedValueOnce({
      questions: [
        { id: 1, word: 'apple', options: ['яблоко', 'груша'] },
        { id: 2, word: 'water', options: ['вода', 'огонь', 'земля'] },
        { id: 3, word: 'borrow', options: ['занять', 'дать', 'взять', 'купить'] },
        { id: 4, word: 'thorough', options: ['тщательный', 'быстрый', 'новый', 'старый'] },
      ],
      expires_at: new Date(Date.now() + 60_000).toISOString(),
    })
    const r = await render('/onboarding/placement')

    expect(r.wrapper.findAll('[data-testid=placement-options] [role=radio]')).toHaveLength(2)
    expect(r.wrapper.get('[data-testid=placement-skip]').text()).toBe('Не знаю')
    await answer(r, 1, 0)
    expect(r.wrapper.findAll('[data-testid=placement-options] [role=radio]')).toHaveLength(3)
    await tap(r, '[data-testid=placement-skip]')
    expect(r.wrapper.get('[data-testid=placement-counter]').text()).toBe('3 / 4')
    await answer(r, 1, 2)
    await tap(r, '[data-testid=placement-skip]')

    expect(vi.mocked(api.onboarding.placementAnswers).mock.calls).toEqual([[[0, null, 2, null]]])
    expect(r.wrapper.get('[data-testid=placement-correct]').text()).toBe('Верных ответов: 4 из 4')
    expect(apiCalls()).toEqual({ get: 1, placement: 1, answers: 1 })
  })

  it('410: "Выбрать уровень" leads back to O5 without a new set', async () => {
    vi.mocked(api.onboarding.placementAnswers).mockRejectedValueOnce(new ApiClientError('gone', 410, 'placement_expired', 'expired'))
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')
    await answer(r, 10)
    await tap(r, '[data-testid=onboarding-secondary]')

    expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    expect(apiCalls()).toEqual({ get: 1, placement: 1, answers: 1 })
  })

  it('a failed send keeps the answers: the error and "Повторить" sends them again', async () => {
    vi.mocked(api.onboarding.placementAnswers).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'offline'))
    const r = await render('/onboarding/placement')
    await answer(r, 10, 2)

    expect(r.wrapper.get('[data-testid=placement-send-error]').text()).toContain('Нет связи с сервером')
    expect(r.wrapper.get('[data-testid=option-2]').attributes('data-state')).toBe('selected')
    await tap(r, '[data-testid=placement-retry]')

    expect(vi.mocked(api.onboarding.placementAnswers).mock.calls).toEqual([[Array(10).fill(2)], [Array(10).fill(2)]])
    expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Ваш уровень: B1')
    expect(apiCalls()).toEqual({ get: 1, placement: 1, answers: 2 })
  })

  it('a failed start shows the error with "Повторить", which asks again', async () => {
    vi.mocked(api.onboarding.placement).mockRejectedValueOnce(new ApiClientError('rate_limited', 429, 'too_many_requests', 'slow'))
    const r = await render('/onboarding/placement')

    expect(r.wrapper.get('[data-testid=placement-error]').text()).toContain('Слишком много запросов')
    await tap(r, '[data-testid=placement-error] [data-testid=retry-btn]')

    expect(word(r)).toBe('w1')
    expect(apiCalls()).toEqual({ get: 1, placement: 2 })
  })

  it('422 placement_unavailable: says the test is not there now and leads back to O5 to choose by hand', async () => {
    vi.mocked(api.onboarding.placement).mockRejectedValueOnce(new ApiClientError('validation', 422, 'placement_unavailable', 'no words'))
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')

    expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Тест сейчас недоступен')
    await tap(r, '[data-testid=onboarding-continue]')
    expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    expect(apiCalls()).toEqual({ get: 1, placement: 1 })

    // Asked again only from the button of O5.
    await tap(r, '[data-testid=onboarding-secondary]')
    expect(word(r)).toBe('w1')
    expect(apiCalls()).toEqual({ get: 1, placement: 2 })
  })

  it('409 onboarding_completed: the onboarding is over elsewhere - "Today"', async () => {
    vi.mocked(api.onboarding.placement).mockRejectedValueOnce(new ApiClientError('conflict', 409, 'onboarding_completed', 'done'))
    const r = await render('/onboarding/placement')

    expect(r.router.currentRoute.value.fullPath).toBe('/today')
    expect(auth.learner?.onboarding_completed).toBe(true)
  })

  it('a second test after a result is a new set', async () => {
    const r = await render('/onboarding/level')
    await tap(r, '[data-testid=onboarding-secondary]')
    await answer(r, 10)
    await tap(r, '[data-testid=onboarding-continue]')

    await tap(r, '[data-testid=onboarding-secondary]')
    expect(word(r)).toBe('w1')
    expect(apiCalls()).toEqual({ get: 1, placement: 2, answers: 1 })
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render('/onboarding/placement')
    expect(r.wrapper.text()).toContain('Tarjimasini tanlang')
    await answer(r, 10)
    expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Darajangiz: B1')
  })
})
