import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeSessionView from '../PracticeSessionView.vue'
import { api } from '@/api'
import { setLocale } from '@/i18n'
import { answerResponse, finishedSession, pairsElement, summary, timedSession } from '@/api/__tests__/practiceFixtures'
import type { PracticeSession } from '@/api/types'

vi.mock('@/api', () => ({
  api: { practice: { get: vi.fn(), open: vi.fn(), start: vi.fn(), answer: vi.fn(), replace: vi.fn(), audioBlob: vi.fn() } },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }) }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ close: vi.fn(), showBackButton: () => () => {}, haptic: vi.fn(), notificationHaptic: vi.fn(), selectionHaptic: () => {} }),
}))
vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn(), hideErrorToast: vi.fn() }))
// The board itself is tested apart: here a probe that sends a pair when told to.
vi.mock('@/components/practice/modeComponents', async () => {
  const { defineComponent, h } = await import('vue')
  const Probe = defineComponent({
    props: { element: { type: Object, required: true }, mode: { type: String, default: 'solo' }, disabled: { type: Boolean, default: false } },
    emits: ['answer'],
    setup: (props, { emit }) => () =>
      h('div', { 'data-testid': 'probe', 'data-element': (props.element as { id: string }).id, 'data-disabled': String(props.disabled) }, [
        h('button', { 'data-testid': 'probe-answer', onClick: () => emit('answer', { left_id: 'lM1', right_id: 'rU2' }) }),
      ]),
  })
  return { MODE_COMPONENTS: {}, modeComponentFor: () => Probe }
})

const NOW = Date.parse('2026-10-30T10:00:05.321Z')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(NOW)
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
  document.body.innerHTML = ''
})

async function render(opened: PracticeSession) {
  vi.mocked(api.practice.get).mockResolvedValue(opened)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
    ],
  })
  await router.push({ name: 'practice-session', params: { id: 77 } })
  const wrapper = mount(PracticeSessionView, { props: { id: 77 }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const closed = () => finishedSession({ mode: 'pairs_timed', summary: summary({ score: 12, best_score: 12, is_record: true, finish_reason: 'time_over', mistakes: [], retry_available: false }) })

describe('PracticeSessionView — timed pairs', () => {
  it('before the start: "Ready? 60 seconds" and a Start button, no clock and no board', async () => {
    const { wrapper } = await render(timedSession(null))

    expect(wrapper.get('[data-testid=timed-ready-title]').text()).toBe('Готов?')
    expect(wrapper.get('[data-testid=timed-ready-text]').text()).toContain('60 секунд')
    expect(wrapper.find('[data-testid=timed-bar]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=probe]').exists()).toBe(false)
  })

  it('Start: POST /start, then the clock and the board', async () => {
    const { wrapper } = await render(timedSession(null))
    vi.mocked(api.practice.start).mockResolvedValue(timedSession(60_000))

    await wrapper.get('[data-testid=timed-start-btn]').trigger('click')
    await flushPromises()

    expect(api.practice.start).toHaveBeenCalledWith(77)
    expect(wrapper.find('[data-testid=timed-ready]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=timed-seconds]').text()).toBe('60')
    expect(wrapper.get('[data-testid=timed-score]').text()).toBe('Пар: 0')
    expect(wrapper.get('[data-testid=probe]').attributes('data-disabled')).toBe('false')
  })

  it('a started game goes straight to the board; a right pair raises the score and a wrong one flashes "−2 с"', async () => {
    const { wrapper } = await render(timedSession(30_000, 3))
    expect(wrapper.get('[data-testid=timed-score]').text()).toBe('Пар: 3')

    vi.mocked(api.practice.answer).mockResolvedValue(
      answerResponse({ verdict: 'wrong', pair: { left_id: 'lM1', right_id: 'rU2' }, round_done: false, penalty_ms: 2000, session: timedSession(28_000, 3, { current: pairsElement({ mismatches: 1 }) }) }),
    )
    await wrapper.get('[data-testid=probe-answer]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=timed-penalty]').text()).toBe('−2 с')
    expect(wrapper.get('[data-testid=timed-seconds]').text()).toBe('28')
  })

  it('at zero: the board is locked, the session is read and the summary shows the score and the record', async () => {
    const { wrapper } = await render(timedSession(2_000, 12))
    vi.mocked(api.practice.get).mockClear()
    let reply!: (value: PracticeSession) => void
    vi.mocked(api.practice.get).mockReturnValue(new Promise((resolve) => (reply = resolve)))

    await vi.advanceTimersByTimeAsync(2_300)
    expect(wrapper.get('[data-testid=timed-time-up]').text()).toBe('Время вышло…')
    expect(wrapper.get('[data-testid=probe]').attributes('data-disabled')).toBe('true')
    reply(closed())
    await flushPromises()

    expect(api.practice.answer).not.toHaveBeenCalled()
    expect(api.practice.get).toHaveBeenCalledWith(77)
    expect(wrapper.get('[data-testid=timed-summary-score]').text()).toBe('Счёт: 12')
    expect(wrapper.get('[data-testid=timed-new-record]').text()).toBe('Новый рекорд!')
  })

  it('"Again" opens a new game and goes to it', async () => {
    const { wrapper, router } = await render(timedSession(2_000, 12))
    vi.mocked(api.practice.get).mockResolvedValue(closed())
    await vi.advanceTimersByTimeAsync(2_300)
    await flushPromises()

    vi.mocked(api.practice.open).mockResolvedValue(timedSession(null, 0, { id: 78 }))
    await wrapper.get('[data-testid=again-btn]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('78')
    expect(api.practice.open).toHaveBeenCalledWith(expect.objectContaining({ mode: 'pairs_timed' }))
  })

  it('coming back to the app reads the session: the game may be over', async () => {
    const { wrapper } = await render(timedSession(30_000, 2))
    vi.mocked(api.practice.get).mockResolvedValue(closed())

    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()

    expect(wrapper.find('[data-testid=timed-summary-score]').exists()).toBe(true)
  })
})
