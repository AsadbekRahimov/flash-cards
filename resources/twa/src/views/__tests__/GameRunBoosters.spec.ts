import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import GameRunView from '../GameRunView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useTodayStore } from '@/stores/today'
import { pairs, runView, translateFwd } from '@/api/__tests__/gameRunFixtures'
import type { GameElement, GameRunView as RunView } from '@/api/gameRunTypes'

vi.mock('@/api', () => ({
  api: { gameRuns: { get: vi.fn(), answer: vi.fn(), hint: vi.fn(), abandon: vi.fn(), audioBlob: vi.fn(), booster: vi.fn() }, practice: { audioBlob: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ close: vi.fn(), showBackButton: () => () => undefined, haptic: vi.fn(), notificationHaptic: vi.fn(), selectionHaptic: () => {} }),
}))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))
vi.mock('@/composables/useReviewFromPaywall', () => ({ useReviewFromPaywall: () => ({ reviewFromPaywall: vi.fn() }) }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' }, boosterHit: { type: Object, default: null } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-limit': props.boosterHit ? String((props.boosterHit as { limit: number }).limit) : '' }, [
          h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') }),
        ]),
    }),
  }
})

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const stock = (over: Partial<RunView['boosters']> = {}, element: GameElement = translateFwd()): RunView =>
  runView(
    {
      boosters: {
        available: [
          { key: 'booster_hint', effect: 'hint', qty: 2 },
          { key: 'booster_extra_flask', effect: 'extra_flask', qty: 1 },
          { key: 'booster_second_chance', effect: 'second_chance', qty: 3 },
        ],
        used: [],
        ...over,
      },
    },
    element,
  )

async function render(opened: RunView) {
  vi.mocked(api.gameRuns.get).mockResolvedValue(opened)
  const stub = { template: '<div />' }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/game', name: 'game', component: stub }, { path: '/game/runs/:id(\\d+)', name: 'game-run', component: stub }] })
  await router.push({ name: 'game-run', params: { id: 9 } })
  const wrapper = mount(GameRunView, { props: { id: 9 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof render>>
const btn = (w: Wrapper, effect: string) => w.get(`[data-testid=booster-${effect}]`)
const disabled = (w: Wrapper, effect: string) => btn(w, effect).attributes('disabled') !== undefined

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('GameRunView - the row of boosters', () => {
  it('is not drawn while the stock is empty', async () => {
    const w = await render(runView())
    expect(w.find('[data-testid=boosters]').exists()).toBe(false)
    w.unmount()
  })

  it('draws an icon with the rest for every booster of the stock, all active', async () => {
    const w = await render(stock())

    expect(w.findAll('[data-testid=booster-qty]').map((n) => n.text())).toEqual(['×2', '×1', '×3'])
    for (const effect of ['hint', 'extra_flask', 'second_chance']) expect(disabled(w, effect)).toBe(false)
    expect(btn(w, 'hint').attributes('aria-label')).toBe('Подсказка, осталось 2')
    expect(btn(w, 'hint').find('img').attributes('data-asset')).toBe('booster_hint')
    w.unmount()
  })

  it('makes a booster that this run has used inactive: the whole-run kinds, and a hint of this element', async () => {
    const w = await render(
      stock({
        used: [
          { key: 'booster_extra_flask', effect: 'extra_flask', element_id: null },
          { key: 'booster_hint', effect: 'hint', element_id: 'e1' },
        ],
      }),
    )

    expect(disabled(w, 'extra_flask')).toBe(true)
    expect(disabled(w, 'hint')).toBe(true)
    expect(disabled(w, 'second_chance')).toBe(false)
    expect(btn(w, 'extra_flask').attributes('aria-label')).toBe('Запасная фляга, уже использовано')
    w.unmount()
  })

  it('keeps a hint active on another element than the one it was used for', async () => {
    const w = await render(stock({ used: [{ key: 'booster_hint', effect: 'hint', element_id: 'e0' }] }))
    expect(disabled(w, 'hint')).toBe(false)
    w.unmount()
  })

  it('makes the hint inactive on an element that has one already', async () => {
    const w = await render(stock({}, translateFwd({ hint: { options_removed: ['o2', 'o3'] } })))
    expect(disabled(w, 'hint')).toBe(true)
    expect(disabled(w, 'extra_flask')).toBe(false)
    w.unmount()
  })

  it('uses a hint: one request for the current element, the hint is drawn on the task and the row goes on', async () => {
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockResolvedValue(
      stock({ available: [{ key: 'booster_hint', effect: 'hint', qty: 1 }], used: [{ key: 'booster_hint', effect: 'hint', element_id: 'e1' }] }, translateFwd({ hint: { options_removed: ['o2', 'o3'] } })),
    )

    await btn(w, 'hint').trigger('click')
    await flushPromises()

    expect(api.gameRuns.booster).toHaveBeenCalledTimes(1)
    expect(vi.mocked(api.gameRuns.booster).mock.calls[0]![1]).toMatchObject({ item_key: 'booster_hint', element_id: 'e1' })
    expect(w.findAll('[data-testid=booster-qty]').map((n) => n.text())).toEqual(['×1'])
    expect(disabled(w, 'hint')).toBe(true)
    w.unmount()
  })

  it('409 booster_limit_reached opens the paywall of the game with the numbers; closing it leaves the task', async () => {
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'booster_limit_reached', { limit: 1, used: 1, resets_at: '2026-11-09T19:00:00.000Z' }))

    await btn(w, 'hint').trigger('click')
    await flushPromises()

    const paywall = w.get('[data-testid=paywall]')
    expect(paywall.attributes('data-reason')).toBe('game')
    expect(paywall.attributes('data-limit')).toBe('1')
    expect(showErrorToast).not.toHaveBeenCalled()

    await w.get('[data-testid=paywall-close]').trigger('click')
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    expect(w.find('[data-testid=choice-task]').exists()).toBe(true)
    w.unmount()
  })

  it('a Pro learner at the limit of the week (upsell: false) gets the date of the new boosters in a toast, never the paywall "buy Pro"', async () => {
    // The week ends at the local midnight of the learner: 19:00 UTC is the 10th in Tashkent.
    useTodayStore().data = { timezone: 'Asia/Tashkent' } as never
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockRejectedValue(
      apiError('conflict', 409, 'booster_limit_reached', { limit: 5, used: 5, resets_at: '2026-11-09T19:00:00.000Z', upsell: false }),
    )

    await btn(w, 'hint').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    expect(showErrorToast).toHaveBeenCalledTimes(1)
    expect(vi.mocked(showErrorToast).mock.calls[0]![0]).toMatch(/^Бустеры этой недели закончились\. Новые — 10 ноября\.$/)
    expect(w.find('[data-testid=choice-task]').exists()).toBe(true)
    w.unmount()
  })

  it('a refusal that does not say `upsell` still sells (an older server)', async () => {
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'booster_limit_reached', { limit: 1, used: 1, resets_at: '2026-11-09T19:00:00.000Z', upsell: true }))

    await btn(w, 'hint').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid=paywall]').exists()).toBe(true)
    w.unmount()
  })

  it('makes a booster inactive where it cannot work: a hint on pairs, a flask or a second chance in a fight without flasks', async () => {
    const onPairs = await render(stock({}, pairs()))
    expect(disabled(onPairs, 'hint')).toBe(true)
    expect(btn(onPairs, 'hint').attributes('aria-label')).toBe('Подсказка: здесь не сработает')
    expect(disabled(onPairs, 'extra_flask')).toBe(false)
    onPairs.unmount()

    const boss = await render({ ...stock(), flasks_max: 0, flasks: 0 })
    expect(disabled(boss, 'extra_flask')).toBe(true)
    expect(disabled(boss, 'second_chance')).toBe(true)
    expect(disabled(boss, 'hint')).toBe(false)
    boss.unmount()
  })

  it('names an effect it does not know "Бустер", never the key of the dictionary, and does not offer it', async () => {
    const odd = { key: 'booster_odd', effect: 'time_stop', qty: 1 } as unknown as RunView['boosters']['available'][number]
    const w = await render(stock({ available: [odd] }))

    expect(btn(w, 'time_stop').attributes('aria-label')).toBe('Бустер: здесь не сработает')
    expect(disabled(w, 'time_stop')).toBe(true)
    expect(w.html()).not.toContain('gameCaravan.booster.effect')
    w.unmount()
  })

  it('has no flasks drawn in a fight without them (nothing to read as "0 of 0")', async () => {
    const w = await render({ ...stock(), flasks_max: 0, flasks: 0 })
    expect(w.find('[data-testid=flasks]').exists()).toBe(false)
    w.unmount()

    const stop = await render(stock())
    expect(stop.find('[data-testid=flasks]').exists()).toBe(true)
    stop.unmount()
  })

  it('any other refusal is a toast', async () => {
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockRejectedValue(apiError('conflict', 409, 'booster_not_owned'))

    await btn(w, 'second_chance').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid=paywall]').exists()).toBe(false)
    w.unmount()
  })

  it('repeats a booster that got no reply with the same request_id', async () => {
    const w = await render(stock())
    vi.mocked(api.gameRuns.booster).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValue(stock())

    await btn(w, 'extra_flask').trigger('click')
    await flushPromises()
    await btn(w, 'extra_flask').trigger('click')
    await flushPromises()

    const [first, second] = vi.mocked(api.gameRuns.booster).mock.calls
    expect(second![1].request_id).toBe(first![1].request_id)
    w.unmount()
  })
})
