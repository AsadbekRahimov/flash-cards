import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '@/App.vue'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useTrainingStore } from '@/stores/training'
import type { Card, ReviewResponse, TrainingStartResponse } from '@/api/types'

/**
 * The lesson under F5 «Нет сети» (docs/40 §2.6), the way the app has it: the shell (`App.vue`) and the training screen in its
 * `RouterView`. The api is mocked, so the signals of the real client never come: the device's events say what the network does.
 */

vi.mock('@/api', () => ({
  api: { training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), answer: vi.fn(), finish: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined), accountRestored: null, takeAccountRestored: () => null }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    showBackButton: () => () => {},
    haptic: () => {},
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

const offline = () => new ApiClientError('network', 0, 'unknown', 'fetch failed')

const card = (overrides: Partial<Card> = {}): Card => ({
  word_id: 7,
  word: 'walk',
  translation: 'идти',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'due',
  serve_token: 'ticket-7',
  ...overrides,
})

const started = (): TrainingStartResponse => ({
  session_id: 1,
  mode: 'lesson',
  lesson: { stage: 1, lesson: 3, title: null },
  total_words: 8,
  card: card(),
  progress: { done: 4, total: 8 },
  done_reason: null,
})

const reviewed = (): ReviewResponse => ({
  next_review_at: null,
  new_interval_days: 2,
  easiness_factor: 2.5,
  graded: null,
  card: card({ word_id: 8, word: 'run', serve_token: 'ticket-8' }),
  progress: { done: 5, total: 8 },
})

function setDeviceOnline(value: boolean): void {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => value })
}

function goOffline(): void {
  setDeviceOnline(false)
  window.dispatchEvent(new Event('offline'))
}

function goOnline(): void {
  setDeviceOnline(true)
  window.dispatchEvent(new Event('online'))
}

let wrapper: VueWrapper | null = null

async function renderApp() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div data-testid="today" />' } },
      {
        path: '/training/:sessionId(\\d+)',
        name: 'training',
        component: TrainingView,
        meta: { immersive: true },
        props: (route) => ({ sessionId: Number(route.params.sessionId) }),
      },
      { path: '/finished', name: 'finished', component: { template: '<div data-testid="finished" />' } },
    ],
  })
  await router.push({ name: 'training', params: { sessionId: 1 } })
  wrapper = mount(App, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function grade(w: VueWrapper): Promise<void> {
  await w.get('[data-testid=reveal-btn]').trigger('click')
  await w.get('[data-testid=grade-medium]').trigger('click')
  await flushPromises()
}

describe('the lesson and F5 «Нет сети»', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    window.sessionStorage.clear()
    setDeviceOnline(true)
    vi.mocked(api.training.start).mockResolvedValue(started())
  })
  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    setDeviceOnline(true)
    document.body.innerHTML = ''
  })

  it('a grade lost while the device says online: the lesson keeps its own error state, no F5', async () => {
    const w = await renderApp()
    vi.mocked(api.training.review).mockRejectedValueOnce(offline())

    await grade(w)

    expect(w.find('[data-testid=error]').exists()).toBe(true)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
  })

  it('offline: F5 says the answer waits and how much of the lesson is saved; back online: the answer goes once, the lesson goes on', async () => {
    const w = await renderApp()
    vi.mocked(api.training.review).mockRejectedValueOnce(offline())
    await grade(w)
    goOffline()
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
    expect(w.get('[data-testid=offline-chip]').text()).toBe('Урок 3 · сохранено 4 из 8')
    expect(w.get('[data-testid=offline-text]').text()).toContain('Последний ответ сохранён на телефоне')
    expect(w.text()).not.toContain('Повторять слова офлайн')

    vi.mocked(api.training.review).mockResolvedValueOnce(reviewed())
    goOnline()
    await flushPromises()

    expect(api.training.review).toHaveBeenCalledTimes(2)
    expect(vi.mocked(api.training.review).mock.calls[1]).toEqual(vi.mocked(api.training.review).mock.calls[0])
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    expect(w.find('[data-testid=error]').exists()).toBe(false)
    expect(w.get('[data-testid=flashcard]').text()).toContain('run')
    expect(useTrainingStore().pending).toBeNull()
  })

  it('"Retry" on F5 while still offline sends the answer again; it keeps waiting', async () => {
    const w = await renderApp()
    vi.mocked(api.training.review).mockRejectedValueOnce(offline())
    await grade(w)
    goOffline()
    await flushPromises()

    vi.mocked(api.training.review).mockRejectedValueOnce(offline())
    await w.get('[data-testid=offline-retry]').trigger('click')
    await flushPromises()

    expect(api.training.review).toHaveBeenCalledTimes(2)
    expect(w.find('[data-testid=offline-screen]').exists()).toBe(true)
    expect(useTrainingStore().pending?.serveToken).toBe('ticket-7')
  })

  it('offline before any answer: F5 with the chip, and the return of the network sends nothing', async () => {
    const w = await renderApp()
    goOffline()
    await flushPromises()
    expect(w.get('[data-testid=offline-text]').text()).not.toContain('Последний ответ')

    goOnline()
    await flushPromises()

    expect(w.find('[data-testid=offline-screen]').exists()).toBe(false)
    expect(api.training.review).not.toHaveBeenCalled()
    expect(api.training.next).not.toHaveBeenCalled()
    expect(w.find('[data-testid=flashcard]').exists()).toBe(true)
  })

  it('a card lost to the network: the return of the network asks for it again', async () => {
    vi.mocked(api.training.start).mockRejectedValueOnce(offline())
    const w = await renderApp()
    goOffline()
    await flushPromises()
    // Nothing of the lesson is known yet: no chip.
    expect(w.find('[data-testid=offline-chip]').exists()).toBe(false)

    goOnline()
    await flushPromises()

    expect(api.training.start).toHaveBeenCalledTimes(2)
    expect(w.find('[data-testid=flashcard]').exists()).toBe(true)
  })

  it('a reload of the tab with a waiting answer: the answer goes first, then the lesson opens on the card after it', async () => {
    window.sessionStorage.setItem(
      'training:pending-review:1',
      JSON.stringify({
        sessionId: 1, wordId: 7, serveToken: 'ticket-7', exercise: 'flash', timeSpentMs: 1200,
        quality: 4, answer: null, hold: false, introduction: false, at: 1,
      }),
    )
    const order: string[] = []
    vi.mocked(api.training.review).mockImplementationOnce(async () => {
      order.push('review')
      return reviewed()
    })
    vi.mocked(api.training.start).mockImplementationOnce(async () => {
      order.push('start')
      return { ...started(), card: card({ word_id: 8, word: 'run' }), progress: { done: 5, total: 8 } }
    })

    const w = await renderApp()

    expect(order).toEqual(['review', 'start'])
    expect(vi.mocked(api.training.review).mock.calls[0]).toEqual([1, 7, 4, 1200, 'ticket-7', 'flash'])
    expect(w.get('[data-testid=flashcard]').text()).toContain('run')
    expect(window.sessionStorage.getItem('training:pending-review:1')).toBeNull()
  })
})
