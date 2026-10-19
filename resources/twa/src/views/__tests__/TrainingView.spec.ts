import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import type { Card, TrainingStartResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    training: {
      open: vi.fn(),
      start: vi.fn(),
      next: vi.fn(),
      review: vi.fn(),
      finish: vi.fn(),
    },
  },
}))

const state = vi.hoisted(() => ({ close: vi.fn() }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: state.close,
    showBackButton: () => () => {},
    haptic: () => {},
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

const gone = () => new ApiClientError('gone', 410, 'session_closed', 'This training session is closed.')

const card: Card = {
  word_id: 7,
  word: 'Resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: null,
  transcription: null,
  card_kind: 'new',
  serve_token: null,
}

const started = (sessionId: number, mode: TrainingStartResponse['mode'] = 'lesson'): TrainingStartResponse => ({
  session_id: sessionId,
  mode,
  lesson: mode === 'daily_review' ? null : { stage: 1, lesson: 2, title: null },
  total_words: 3,
  card,
  progress: { done: 0, total: 3 },
  done_reason: null,
})

async function render(sessionId: number) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/training/:sessionId(\\d+)', name: 'training', component: { template: '<div />' } },
      { path: '/finished', name: 'finished', component: { template: '<div />' } },
    ],
  })
  await router.push({ name: 'training', params: { sessionId } })
  const wrapper = mount(TrainingView, {
    props: { sessionId },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

describe('TrainingView — closed session', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('uses a neutral title and only offers to close a group session', async () => {
    vi.mocked(api.training.start).mockRejectedValue(gone())

    const { wrapper } = await render(5)

    expect(wrapper.get('[data-testid=closed]').text()).toContain('Тренировка завершена')
    expect(wrapper.get('[data-testid=closed]').text()).not.toContain('учителем')
    expect(wrapper.find('[data-testid=restart-btn]').exists()).toBe(false)
    await wrapper.get('[data-testid=close-btn]').trigger('click')
    expect(state.close).toHaveBeenCalled()
  })

  it('offers "start again" for a personal session this run opened and moves to the new session', async () => {
    const store = useTrainingStore()
    vi.mocked(api.training.open).mockResolvedValueOnce(started(5, 'daily_review'))
    await store.open({ mode: 'daily_review', source: 'self' })
    vi.mocked(api.training.start).mockRejectedValue(gone())
    // The first visit consumes the preloaded answer; a later one gets the 410.
    const { wrapper, router } = await render(5)
    expect(wrapper.find('[data-testid=closed]').exists()).toBe(false)
    await store.start(5)
    await flushPromises()

    expect(wrapper.get('[data-testid=restart-btn]').text()).toBe('Начать заново')
    expect(wrapper.find('[data-testid=close-btn]').exists()).toBe(false)

    vi.mocked(api.training.open).mockResolvedValueOnce(started(6, 'daily_review'))
    await wrapper.get('[data-testid=restart-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenLastCalledWith({ mode: 'daily_review', source: 'self' })
    expect(router.currentRoute.value.params.sessionId).toBe('6')
    // RouterView re-renders the reused component with the new route prop.
    await wrapper.setProps({ sessionId: 6 })
    await flushPromises()
    // The new session came with its first card: no second /start for it.
    expect(api.training.start).not.toHaveBeenCalledWith(6)
    expect(wrapper.find('[data-testid=closed]').exists()).toBe(false)
    expect(store.sessionId).toBe(6)
    expect(store.status).toBe('ready')
  })

  it('restarts the same lesson, localized', async () => {
    setLocale('uz')
    const store = useTrainingStore()
    vi.mocked(api.training.open).mockResolvedValueOnce(started(8))
    await store.open({ mode: 'lesson', lesson_id: 11, source: 'picker' })
    vi.mocked(api.training.start).mockRejectedValue(gone())
    const { wrapper } = await render(8)
    await store.start(8)
    await flushPromises()

    expect(wrapper.get('[data-testid=restart-btn]').text()).toBe('Qaytadan boshlash')
    vi.mocked(api.training.open).mockResolvedValueOnce(started(9))
    await wrapper.get('[data-testid=restart-btn]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenLastCalledWith({ mode: 'lesson', lesson_id: 11, source: 'picker' })
  })

  it('shows why a restart failed and stays on the closed screen', async () => {
    const store = useTrainingStore()
    vi.mocked(api.training.open).mockResolvedValueOnce(started(5, 'daily_review'))
    await store.open({ mode: 'daily_review', source: 'self' })
    vi.mocked(api.training.start).mockRejectedValue(gone())
    const { wrapper } = await render(5)
    await store.start(5)
    await flushPromises()

    vi.mocked(api.training.open).mockRejectedValueOnce(
      new ApiClientError('not_found', 404, 'lesson_not_found', 'Training lesson not found.'),
    )
    await wrapper.get('[data-testid=restart-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=open-error]').text()).toBe('Урок не найден.')
    expect(wrapper.find('[data-testid=restart-btn]').exists()).toBe(true)

    wrapper.unmount()
    expect(store.openError).toBe('')
  })
})

describe('TrainingView — first card from open()', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('does not call /start again for a session that open() has just answered', async () => {
    const store = useTrainingStore()
    vi.mocked(api.training.open).mockResolvedValueOnce(started(12))
    await store.open({ mode: 'lesson', lesson_id: 11 })

    const { wrapper } = await render(12)

    expect(api.training.start).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Resilient')
  })
})
