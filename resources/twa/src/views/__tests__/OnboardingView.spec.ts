import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import OnboardingView from '../OnboardingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { onboardingFixture } from '@/api/__tests__/onboardingFixture'
import type { OnboardingState } from '@/api/onboardingTypes'
import type { Learner } from '@/api/types'
import { useBillingStore } from '@/stores/billing'

/*
 * The onboarding of the Mini App, O1-O8 (docs/40 §2.1, kit 02-onboarding): the screens by the mock-ups, "Continue" waits for an answer,
 * every step is saved with the body of the contract, a failed save keeps the answer and offers "Retry", 409 leads to "Today", the
 * system "Back" goes a step back, O6 locks the goals behind Pro, O7 asks Telegram for the permission to write, O8 finishes.
 */

const tg = vi.hoisted(() => ({
  back: null as null | (() => void),
  firstName: 'Aziz' as string | null,
  canAsk: true,
  answer: 'granted' as 'granted' | 'denied' | 'unsupported',
  requestWriteAccess: vi.fn(),
  hide: vi.fn(),
}))

const auth = vi.hoisted(() => ({ ensure: vi.fn(), learner: null as Learner | null }))

vi.mock('@/api', () => ({
  api: { onboarding: { get: vi.fn(), saveStep: vi.fn(), complete: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: auth.ensure,
    get learner() {
      return auth.learner
    },
  }),
}))

vi.mock('@/composables/telegramOnboarding', () => ({
  telegramFirstName: () => tg.firstName,
  canRequestWriteAccess: () => tg.canAsk,
  requestWriteAccess: () => tg.requestWriteAccess(),
}))

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

/** The paywall is its own component with its own tests: here only whether it opens. */
const PaywallStub = defineComponent({
  props: { reason: String, goalMax: { type: Number, default: undefined } },
  emits: ['redeemed', 'close'],
  setup: (props, { emit }) => () =>
    h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-goal-max': props.goalMax }, [
      // A promo code accepted on the sheet (`PromoRedeem` -> `redeemed` with the new `/me`).
      h('button', { 'data-testid': 'paywall-redeem', onClick: () => emit('redeemed', {}) }),
    ]),
})

const network = () => new ApiClientError('network', 0, 'network', 'Failed to fetch')

async function render(path: string, state: OnboardingState = onboardingFixture()) {
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
  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), {
    global: { plugins: [router], stubs: { PaywallSheet: PaywallStub } },
  })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>

const continueBtn = (r: Rendered) => r.wrapper.get('[data-testid=onboarding-continue]')

async function tap(r: Rendered, selector: string): Promise<void> {
  await r.wrapper.get(selector).trigger('click')
  await flushPromises()
}

describe('OnboardingView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
    tg.back = null
    tg.firstName = 'Aziz'
    tg.canAsk = true
    tg.answer = 'granted'
    tg.requestWriteAccess.mockImplementation(async () => tg.answer)
    auth.ensure.mockResolvedValue(undefined)
    auth.learner = { id: 1, first_name: 'Aziz', locale: 'ru', onboarding_completed: false, onboarding: { completed: false, step: null } }
    vi.mocked(api.onboarding.saveStep).mockImplementation(async () => onboardingFixture())
  })

  describe('O1 welcome', () => {
    it('shows the brand, the line and "Start" without progress and without "Back"; "Start" leads to the name', async () => {
      const r = await render('/onboarding')

      expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Muhrla')
      expect(r.wrapper.text()).toContain('Английские слова каждый день')
      expect(r.wrapper.get('[data-testid=welcome-note]').text()).toBe('Вход через Telegram — без паролей и регистрации')
      expect(r.wrapper.findAll('[data-testid=welcome-art] [data-testid=topic-art]')).toHaveLength(6)
      expect(r.wrapper.find('[data-testid=onboarding-progress]').exists()).toBe(false)
      expect(tg.back).toBeNull()
      expect(tg.hide).toHaveBeenCalled()

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/name')
      expect(r.wrapper.get('[data-testid=onboarding-counter]').text()).toBe('1 / 7')
      expect(r.wrapper.findAll('.segment')).toHaveLength(7)
      expect(tg.back).not.toBeNull()
    })
  })

  describe('O2 name', () => {
    it('prefills the name from Telegram and says so; saves it trimmed and goes to the age', async () => {
      const r = await render('/onboarding/name')
      const input = r.wrapper.get<HTMLInputElement>('[data-testid=name-input]')

      expect(input.element.value).toBe('Aziz')
      expect(r.wrapper.get('[data-testid=name-from-telegram]').text()).toBe('Взяли из Telegram — можно изменить')
      expect(continueBtn(r).attributes('aria-disabled')).toBeUndefined()

      await input.setValue('  Азиз ')
      expect(r.wrapper.find('[data-testid=name-from-telegram]').exists()).toBe(false)
      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([['name', { display_name: 'Азиз' }]])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/age')
    })

    it('leaves the field empty outside Telegram; "Continue" waits for a name', async () => {
      tg.firstName = null
      const r = await render('/onboarding/name')

      expect(r.wrapper.get<HTMLInputElement>('[data-testid=name-input]').element.value).toBe('')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')
      await tap(r, '[data-testid=onboarding-continue]')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()
      expect(r.wrapper.find('[data-testid=name-error]').exists()).toBe(false)
    })

    it.each([
      ['A'.repeat(33), 'Имя слишком длинное: не больше 32 символов.'],
      ['Aziz t.me/x', 'Имя не должно быть похоже на ссылку или адрес сайта.'],
      ['@aziz', 'Уберите @, / и # в начале слов.'],
    ])('marks a name the server would refuse (%s) and does not send it', async (name, message) => {
      tg.firstName = name
      const r = await render('/onboarding/name')

      expect(r.wrapper.get('[data-testid=name-error]').text()).toBe(message)
      expect(r.wrapper.get('[data-testid=name-input]').attributes('aria-invalid')).toBe('true')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')
      await tap(r, '[data-testid=onboarding-continue]')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()
    })

    it('marks the field on 422 display_name_invalid and stays', async () => {
      vi.mocked(api.onboarding.saveStep).mockRejectedValue(
        new ApiClientError('validation', 422, 'display_name_invalid', 'bad', undefined, { reason: 'marks' }),
      )
      const r = await render('/onboarding/name')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.wrapper.get('[data-testid=name-error]').text()).toBe('В имени слишком много значков над буквами.')
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/name')
      await r.wrapper.get('[data-testid=name-input]').setValue('Aziz B')
      expect(r.wrapper.find('[data-testid=name-error]').exists()).toBe(false)
    })
  })

  describe('O3 age', () => {
    it('waits for a choice, saves it and goes on; the age is not shown to anybody', async () => {
      const r = await render('/onboarding/age')

      expect(r.wrapper.get('[data-testid=onboarding-counter]').text()).toBe('2 / 7')
      expect(r.wrapper.findAll('[role=radio]')).toHaveLength(6)
      expect(r.wrapper.get('[data-testid=age-lt14]').text()).toBe('до 14')
      expect(r.wrapper.get('[data-testid=age-note]').text()).toBe('Возраст никто не увидит — он нужен только для подбора тем.')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')
      await tap(r, '[data-testid=onboarding-continue]')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()

      await tap(r, '[data-testid=age-18_24]')
      expect(r.wrapper.get('[data-testid=age-18_24]').attributes('aria-checked')).toBe('true')
      expect(continueBtn(r).attributes('aria-disabled')).toBeUndefined()
      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([['age', { age_range: '18_24' }]])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/goals')
    })

    it('shows the dots while saving and, on a lost connection, the error with "Retry" - the choice stays', async () => {
      let fail: (err: unknown) => void = () => {}
      vi.mocked(api.onboarding.saveStep)
        .mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject }))
        .mockResolvedValueOnce(onboardingFixture({ step: 'goals' }))
      const r = await render('/onboarding/age')
      await tap(r, '[data-testid=age-25_34]')

      await r.wrapper.get('[data-testid=onboarding-continue]').trigger('click')
      expect(continueBtn(r).attributes('aria-busy')).toBe('true')
      expect(continueBtn(r).find('.btn-dots').exists()).toBe(true)
      fail(network())
      await flushPromises()

      expect(r.wrapper.get('[data-testid=onboarding-error]').text()).toContain('Нет связи с сервером')
      expect(r.wrapper.get('[data-testid=age-25_34]').attributes('aria-checked')).toBe('true')
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/age')

      await tap(r, '[data-testid=onboarding-retry]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['age', { age_range: '25_34' }],
        ['age', { age_range: '25_34' }],
      ])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/goals')
    })

    it('leads to "Today" on 409 onboarding_completed (finished in the bot meanwhile)', async () => {
      vi.mocked(api.onboarding.saveStep).mockRejectedValue(new ApiClientError('conflict', 409, 'onboarding_completed', 'done'))
      const r = await render('/onboarding/age')
      await tap(r, '[data-testid=age-45p]')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.router.currentRoute.value.fullPath).toBe('/today')
      expect(auth.learner?.onboarding).toEqual({ completed: true, step: null })
    })
  })

  describe('O4 goals', () => {
    it('chooses several, counts them on the button and saves them in the order of the tiles', async () => {
      const r = await render('/onboarding/goals')

      expect(r.wrapper.findAll('[role=checkbox]')).toHaveLength(6)
      expect(r.wrapper.findAll('[data-testid=topic-art]')).toHaveLength(6)
      expect(continueBtn(r).text()).toBe('Продолжить')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')

      await tap(r, '[data-testid=goal-travel]')
      await tap(r, '[data-testid=goal-career]')
      expect(continueBtn(r).text()).toBe('Продолжить · выбрано 2')
      expect(r.wrapper.findAll('[data-testid=tile-check]')).toHaveLength(2)
      await tap(r, '[data-testid=goal-media]')
      await tap(r, '[data-testid=goal-media]')
      expect(continueBtn(r).text()).toBe('Продолжить · выбрано 2')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([['goals', { goals: ['career', 'travel'] }]])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    })
  })

  describe('O5 level', () => {
    it('lists the courses of the server (no "Phrases", no test button) and starts with the chosen one', async () => {
      const r = await render('/onboarding/level')

      const rows = r.wrapper.findAll('[role=radio]')
      expect(rows.map((row) => row.get('[data-testid=level-chip]').text())).toEqual(['A1–A2', 'B1', 'B2', 'C1', 'C2'])
      expect(r.wrapper.get('[data-testid=level-A1]').text()).toContain('Начинаю с нуля')
      expect(r.wrapper.text()).not.toContain('Фразы')
      expect(r.wrapper.text()).not.toContain('тест')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')

      await tap(r, '[data-testid=level-B1]')
      expect(continueBtn(r).text()).toBe('Начать с B1')
      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([['level', { level: 'B1', course_slug: 'english-b1' }]])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/daily_goal')
    })

    it('resumes at the saved level', async () => {
      const r = await render('/onboarding/level', onboardingFixture({ step: 'level', values: { level: 'B2' } }))

      expect(r.wrapper.get('[data-testid=level-B2]').attributes('aria-checked')).toBe('true')
      expect(continueBtn(r).text()).toBe('Начать с B2')
    })
  })

  describe('O6 goal of the day', () => {
    it('offers 10 / 20 / 40 cards with the words of the kit, 20 recommended and chosen', async () => {
      const r = await render('/onboarding/daily_goal')

      expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Сколько карточек в день?')
      expect(r.wrapper.get('[data-testid=daily-goal-10]').text()).toContain('Спокойно')
      expect(r.wrapper.get('[data-testid=daily-goal-10]').text()).toContain('карточек в день · ~6 мин')
      expect(r.wrapper.get('[data-testid=daily-goal-40]').text()).toContain('Интенсив')
      expect(r.wrapper.get('[data-testid=daily-goal-20] [data-testid=daily-goal-recommended]').text()).toBe('рекомендуем')
      expect(r.wrapper.get('[data-testid=daily-goal-20]').attributes('aria-checked')).toBe('true')
      expect(r.wrapper.find('[data-testid=daily-goal-lock]').exists()).toBe(false)

      await tap(r, '[data-testid=daily-goal-40]')
      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([['daily_goal', { daily_goal: 40 }]])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/reminder')
    })

    it('locks the goals above the plan limit: a tap opens the paywall and chooses nothing', async () => {
      const r = await render('/onboarding/daily_goal', onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: 10 } }))

      for (const goal of [20, 40]) {
        expect(r.wrapper.get(`[data-testid=daily-goal-${goal}]`).attributes('aria-disabled')).toBe('true')
        expect(r.wrapper.find(`[data-testid=daily-goal-${goal}] [data-testid=daily-goal-lock]`).exists()).toBe(true)
      }
      expect(r.wrapper.get('[data-testid=daily-goal-10]').attributes('aria-checked')).toBe('true')

      await tap(r, '[data-testid=daily-goal-40]')

      expect(r.wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('daily_goal')
      // the limit of GET /onboarding, so the title says «Цель больше 10 — в Pro» as in the settings (the settings store is empty here)
      expect(r.wrapper.get('[data-testid=paywall]').attributes('data-goal-max')).toBe('10')
      expect(r.wrapper.get('[data-testid=daily-goal-10]').attributes('aria-checked')).toBe('true')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()
    })

    it('takes the locks off once Pro is got by a promo code on the paywall: the state is read again, once', async () => {
      const limited = onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: 10 } })
      const r = await render('/onboarding/daily_goal', limited)
      await tap(r, '[data-testid=daily-goal-40]')
      expect(api.onboarding.get).toHaveBeenCalledTimes(1)

      vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: null } }))
      await tap(r, '[data-testid=paywall-redeem]')

      expect(api.onboarding.get).toHaveBeenCalledTimes(2)
      for (const goal of [20, 40]) {
        expect(r.wrapper.get(`[data-testid=daily-goal-${goal}]`).attributes('aria-disabled')).toBeUndefined()
        expect(r.wrapper.find(`[data-testid=daily-goal-${goal}] [data-testid=daily-goal-lock]`).exists()).toBe(false)
      }
      // The choice made before stays; the learner now picks 40 and saves it.
      expect(r.wrapper.get('[data-testid=daily-goal-10]').attributes('aria-checked')).toBe('true')
      await tap(r, '[data-testid=daily-goal-40]')
      expect(r.wrapper.get('[data-testid=daily-goal-40]').attributes('aria-checked')).toBe('true')
    })

    it('takes the locks off after a payment of the paywall too (the sheet says `paid`, no event)', async () => {
      const r = await render('/onboarding/daily_goal', onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: 10 } }))
      await tap(r, '[data-testid=daily-goal-40]')

      vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: null } }))
      useBillingStore().purchase.status = 'paid'
      await flushPromises()

      expect(api.onboarding.get).toHaveBeenCalledTimes(2)
      expect(r.wrapper.find('[data-testid=daily-goal-lock]').exists()).toBe(false)
    })

    it('reads nothing again while the paywall is closed or nothing was bought', async () => {
      const r = await render('/onboarding/daily_goal', onboardingFixture({ options: { ...onboardingFixture().options, daily_goal_max: 10 } }))
      useBillingStore().purchase.status = 'paid'
      await flushPromises()

      expect(api.onboarding.get).toHaveBeenCalledTimes(1)
      expect(r.wrapper.findAll('[data-testid=daily-goal-lock]')).toHaveLength(2)
    })

    it('goes back to the limit on 403 daily_goal_pro_required, says so and opens the paywall', async () => {
      vi.mocked(api.onboarding.saveStep).mockRejectedValue(
        new ApiClientError('forbidden', 403, 'daily_goal_pro_required', 'pro', undefined, { max: 10 }),
      )
      const r = await render('/onboarding/daily_goal')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.wrapper.get('[data-testid=daily-goal-pro-required]').text()).toBe('Цель больше 10 — в Pro. Выбрали 10 в день.')
      expect(r.wrapper.get('[data-testid=daily-goal-10]').attributes('aria-checked')).toBe('true')
      expect(r.wrapper.get('[data-testid=daily-goal-40]').attributes('aria-disabled')).toBe('true')
      expect(r.wrapper.find('[data-testid=paywall]').exists()).toBe(true)
      expect(r.wrapper.find('[data-testid=onboarding-error]').exists()).toBe(false)
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/daily_goal')
    })
  })

  describe('O7 reminder', () => {
    it('asks Telegram for the permission on "Continue" and saves the time with the answer', async () => {
      const r = await render('/onboarding/reminder')

      expect(r.wrapper.get('[data-testid=reminder-2000]').attributes('aria-checked')).toBe('true')
      expect(r.wrapper.get('[data-testid=reminder-bot-switch]').attributes('aria-checked')).toBe('true')
      await tap(r, '[data-testid=reminder-2130]')
      await tap(r, '[data-testid=onboarding-continue]')

      expect(tg.requestWriteAccess).toHaveBeenCalledTimes(1)
      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: true, time: '21:30', write_access: 'granted' }],
      ])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/plan')
    })

    it('turns the switch off and explains a refusal; the next "Continue" saves with it', async () => {
      tg.answer = 'denied'
      const r = await render('/onboarding/reminder')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.wrapper.get('[data-testid=reminder-bot-switch]').attributes('aria-checked')).toBe('false')
      expect(r.wrapper.get('[data-testid=reminder-denied]').text()).toContain('Без разрешения бот не сможет напомнить')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/reminder')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(tg.requestWriteAccess).toHaveBeenCalledTimes(1)
      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: true, time: '20:00', write_access: 'denied' }],
      ])
    })

    it('asks when the switch is turned on by hand, and does not ask when it is turned off', async () => {
      const r = await render('/onboarding/reminder')

      await tap(r, '[data-testid=reminder-bot-switch]')
      expect(tg.requestWriteAccess).not.toHaveBeenCalled()
      expect(r.wrapper.get('[data-testid=reminder-bot-switch]').attributes('aria-checked')).toBe('false')

      tg.answer = 'denied'
      await tap(r, '[data-testid=reminder-bot-switch]')
      expect(tg.requestWriteAccess).toHaveBeenCalledTimes(1)
      expect(r.wrapper.get('[data-testid=reminder-bot-switch]').attributes('aria-checked')).toBe('false')
      expect(r.wrapper.find('[data-testid=reminder-denied]').exists()).toBe(true)
    })

    it('has no switch where the client cannot ask (older than 6.9, outside Telegram): not_asked', async () => {
      tg.canAsk = false
      const r = await render('/onboarding/reminder')

      expect(r.wrapper.find('[data-testid=reminder-bot]').exists()).toBe(false)
      await tap(r, '[data-testid=onboarding-continue]')

      expect(tg.requestWriteAccess).not.toHaveBeenCalled()
      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: true, time: '20:00', write_access: 'not_asked' }],
      ])
    })

    it('hides the switch when Telegram refuses the call after all', async () => {
      tg.answer = 'unsupported'
      const r = await render('/onboarding/reminder')

      await tap(r, '[data-testid=onboarding-continue]')

      expect(r.wrapper.find('[data-testid=reminder-bot]').exists()).toBe(false)
      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: true, time: '20:00', write_access: 'not_asked' }],
      ])
    })

    it('takes a custom time on the quarter of an hour', async () => {
      tg.canAsk = false
      const r = await render('/onboarding/reminder')

      await tap(r, '[data-testid=reminder-custom]')
      expect(continueBtn(r).attributes('aria-disabled')).toBe('true')
      await r.wrapper.get('[data-testid=reminder-custom-input]').setValue('07:50')
      await r.wrapper.get('[data-testid=reminder-custom-input]').trigger('change')
      expect(continueBtn(r).attributes('aria-disabled')).toBeUndefined()
      await tap(r, '[data-testid=onboarding-continue]')

      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: true, time: '07:45', write_access: 'not_asked' }],
      ])
    })

    it('"Do not remind" saves the reminder off without asking', async () => {
      const r = await render('/onboarding/reminder')

      await tap(r, '[data-testid=onboarding-secondary]')

      expect(tg.requestWriteAccess).not.toHaveBeenCalled()
      expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
        ['reminder', { enabled: false, time: null, write_access: 'not_asked' }],
      ])
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/plan')
    })
  })

  describe('O8 plan', () => {
    const saved = () => onboardingFixture({
      step: 'plan',
      values: {
        display_name: 'Азиз',
        first_name: 'Telegram Name',
        age_range: '18_24',
        goals: ['career', 'travel'],
        level: 'B1',
        course: { slug: 'english-b1', title: 'B1', level_label: 'B1', stages_count: 20 },
        daily_goal: 20,
        reminder: { enabled: true, time: '20:00' },
      },
    })

    it('sums up the plan with the chosen name - never Telegram first_name', async () => {
      const r = await render('/onboarding/plan', saved())

      expect(r.wrapper.get('[data-testid=onboarding-counter]').text()).toBe('7 / 7')
      expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Азиз, ваш план готов')
      expect(r.wrapper.text()).not.toContain('Telegram Name')
      expect(r.wrapper.get('[data-testid=plan-course] [data-testid=plan-value]').text()).toBe('B1 · 20 модулей')
      expect(r.wrapper.get('[data-testid=plan-goal] [data-testid=plan-value]').text()).toBe('20 карточек в день')
      expect(r.wrapper.get('[data-testid=plan-reminder] [data-testid=plan-value]').text()).toBe('каждый день в 20:00')
      expect(r.wrapper.get('[data-testid=plan-why] [data-testid=plan-value]').text()).toBe('работа, путешествия')
      expect(continueBtn(r).text()).toBe('Начать первый урок')
    })

    it('says "the reminder is off" and has no name in the title without a chosen name', async () => {
      const r = await render('/onboarding/plan', onboardingFixture({ step: 'plan', values: { reminder: { enabled: false, time: null } } }))

      expect(r.wrapper.get('[data-testid=onboarding-title]').text()).toBe('Ваш план готов')
      expect(r.wrapper.get('[data-testid=plan-reminder] [data-testid=plan-value]').text()).toBe('выключено')
      expect(r.wrapper.find('[data-testid=plan-why]').exists()).toBe(false)
    })

    it('finishes on the server and leads to "Today" with from=onboarding', async () => {
      vi.mocked(api.onboarding.complete).mockResolvedValue({ completed: true, course: { slug: 'english-b1', title: 'B1' }, next_lesson_id: 3 })
      const r = await render('/onboarding/plan', saved())

      await tap(r, '[data-testid=onboarding-continue]')

      expect(api.onboarding.complete).toHaveBeenCalledTimes(1)
      expect(r.router.currentRoute.value.fullPath).toBe('/today?from=onboarding')
      expect(auth.learner?.onboarding).toEqual({ completed: true, step: null })
      expect(localStorage.getItem('lexiflow:intro-seen')).toBe('1')
    })

    it('opens the missing step on 422 onboarding_incomplete; a lost connection offers "Retry"', async () => {
      vi.mocked(api.onboarding.complete)
        .mockRejectedValueOnce(network())
        .mockRejectedValueOnce(new ApiClientError('validation', 422, 'onboarding_incomplete', 'x', undefined, { missing: ['level'] }))
      const r = await render('/onboarding/plan', saved())

      await tap(r, '[data-testid=onboarding-continue]')
      expect(r.wrapper.get('[data-testid=onboarding-error]').text()).toContain('Нет связи с сервером')

      await tap(r, '[data-testid=onboarding-retry]')
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/level')
    })
  })

  describe('the screen', () => {
    it('goes a step back with the system "Back", the answers stay', async () => {
      const r = await render('/onboarding/age', onboardingFixture({ values: { age_range: '14_17' } }))
      await r.router.push('/onboarding/goals')
      await flushPromises()

      tg.back?.()
      await flushPromises()
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/age')
      expect(r.wrapper.get('[data-testid=age-14_17]').attributes('aria-checked')).toBe('true')

      tg.back?.()
      await flushPromises()
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding/name')
      tg.back?.()
      await flushPromises()
      expect(r.router.currentRoute.value.fullPath).toBe('/onboarding')
      expect(tg.back).toBeNull()
    })

    it('slides the next step in from the right and the one before from the left', async () => {
      const r = await render('/onboarding/age')
      await r.router.push('/onboarding/goals')
      await flushPromises()
      expect(r.wrapper.get('[data-testid=onboarding-step]').classes()).toContain('onboarding-enter-next')

      await r.router.push('/onboarding/age')
      await flushPromises()
      expect(r.wrapper.get('[data-testid=onboarding-step]').classes()).toContain('onboarding-enter-back')
    })

    it('shows a skeleton while loading, and the error with "Retry" when the load fails', async () => {
      vi.mocked(api.onboarding.get).mockRejectedValueOnce(network())
      const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/onboarding/:step?', name: 'onboarding', component: OnboardingView, props: (route) => ({ step: route.params.step || null }) }],
      })
      await router.push('/onboarding/age')
      const wrapper = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [router] } })
      expect(wrapper.find('[data-testid=onboarding-skeleton]').exists()).toBe(true)
      await flushPromises()

      expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
      vi.mocked(api.onboarding.get).mockResolvedValueOnce(onboardingFixture())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.findAll('[role=radio]')).toHaveLength(6)
    })

    it('leaves for "Today" when the server says the onboarding is done', async () => {
      const r = await render('/onboarding/age', onboardingFixture({ completed: true, step: null }))

      expect(r.router.currentRoute.value.fullPath).toBe('/today')
    })

    it('opens an unknown step as O1', async () => {
      const r = await render('/onboarding/whatever')

      expect(r.wrapper.get('[data-testid=onboarding]').attributes('data-step')).toBe('welcome')
    })
  })
})
