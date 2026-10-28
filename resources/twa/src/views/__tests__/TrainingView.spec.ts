import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import TrainingView from '../TrainingView.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useTrainingStore } from '@/stores/training'
import type { Card, LessonHeader, TrainingStartResponse } from '@/api/types'

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

// `backHandler` is what the system "Back" button of Telegram would call (useBackButton registers it).
const state = vi.hoisted(() => ({ close: vi.fn(), backHandler: null as (() => void) | null }))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: state.close,
    showBackButton: (handler: () => void) => {
      state.backHandler = handler
      return () => {
        state.backHandler = null
      }
    },
    haptic: () => {},
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

const gone = () => new ApiClientError('gone', 410, 'session_closed', 'This training session is closed.')
const offline = () => new ApiClientError('network', 0, 'unknown', 'fetch failed')

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
      { path: '/today', name: 'today', component: { template: '<div />' } },
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
    // A lock icon in a circle, no emoji in the text.
    expect(wrapper.get('[data-testid=closed]').findComponent(AppIcon).props('name')).toBe('lock-closed')
    expect(wrapper.get('[data-testid=closed]').text()).not.toContain('🔒')
    expect(wrapper.find('[data-testid=restart-btn]').exists()).toBe(false)
    // The closed screen is the whole screen: its own button is the only "Close".
    expect(wrapper.findAll('[data-testid=close-btn]')).toHaveLength(1)
    expect(wrapper.find('[data-testid=progress]').exists()).toBe(false)
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

describe('TrainingView — leaving (docs/14 §5.3, N3)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    state.backHandler = null
  })

  it.each(['lesson', 'daily_review'] as const)('a %s session: ✕ goes home, the answers are already saved', async (mode) => {
    vi.mocked(api.training.start).mockResolvedValue(started(1, mode))
    const { wrapper, router } = await render(1)

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('today')
    expect(state.close).not.toHaveBeenCalled()
  })

  it.each(['lesson', 'daily_review'] as const)('a %s session: the system "Back" goes home too', async (mode) => {
    vi.mocked(api.training.start).mockResolvedValue(started(1, mode))
    const { router } = await render(1)

    expect(state.backHandler).not.toBeNull()
    state.backHandler?.()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('today')
    expect(state.close).not.toHaveBeenCalled()
  })

  it('leaving replaces the training entry, so "Back" from the home tab does not return to it', async () => {
    vi.mocked(api.training.start).mockResolvedValue(started(1, 'lesson'))
    const { wrapper, router } = await render(1)

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()
    router.back()
    await flushPromises()

    expect(router.currentRoute.value.name).not.toBe('training')
  })

  it('a group lesson (opened from a chat) closes the Mini App with ✕ and with "Back"', async () => {
    vi.mocked(api.training.start).mockResolvedValue(started(1, 'group_lesson'))
    const { wrapper, router } = await render(1)

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    expect(state.close).toHaveBeenCalledTimes(1)

    state.backHandler?.()
    expect(state.close).toHaveBeenCalledTimes(2)
    expect(router.currentRoute.value.name).toBe('training')
  })

  it('a session this run knows nothing about closes the Mini App', async () => {
    vi.mocked(api.training.start).mockRejectedValue(offline())
    const { wrapper, router } = await render(5)
    expect(useTrainingStore().mode).toBeNull()

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    state.backHandler?.()

    expect(state.close).toHaveBeenCalledTimes(2)
    expect(router.currentRoute.value.name).toBe('training')
  })

  it('a session this run has opened is personal even before its mode is known', async () => {
    const store = useTrainingStore()
    store.$patch({ opened: { sessionId: 5, request: { mode: 'daily_review', source: 'self' } } })
    vi.mocked(api.training.start).mockRejectedValue(offline())
    const { wrapper, router } = await render(5)
    expect(store.mode).toBeNull()

    await wrapper.get('[data-testid=close-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('today')
    expect(state.close).not.toHaveBeenCalled()
  })

  it('a session opened for another id does not make this one personal', async () => {
    useTrainingStore().$patch({ opened: { sessionId: 99, request: { mode: 'daily_review', source: 'self' } } })
    vi.mocked(api.training.start).mockRejectedValue(offline())
    const { wrapper, router } = await render(5)

    await wrapper.get('[data-testid=close-btn]').trigger('click')

    expect(state.close).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('training')
  })

  it('shows the system Back button while the screen is open and takes it away after', async () => {
    vi.mocked(api.training.start).mockResolvedValue(started(1))
    const { wrapper } = await render(1)
    expect(state.backHandler).not.toBeNull()

    wrapper.unmount()

    expect(state.backHandler).toBeNull()
  })
})

describe('TrainingView — header and states', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  const withLesson = (lesson: LessonHeader | null, mode: TrainingStartResponse['mode'] = 'lesson') => ({
    ...started(1, mode),
    lesson,
    total_words: 20,
    progress: { done: 7, total: 20 },
  })

  it('has a round ✕, the progress bar and the counter "7 / 20"', async () => {
    vi.mocked(api.training.start).mockResolvedValue(withLesson({ stage: 2, lesson: 4, title: null }))
    const { wrapper } = await render(1)

    const close = wrapper.get('[data-testid=close-btn]')
    expect(close.attributes('aria-label')).toBe('Закрыть')
    expect(close.findComponent(AppIcon).props('name')).toBe('x-mark')
    expect(wrapper.get('[data-testid=progress]').text()).toBe('7 / 20')
    expect(wrapper.get('[data-testid=progress-bar]').classes()).toContain('progress')
    expect(wrapper.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 35%')
  })

  it('names the lesson: «Module 2 · Lesson 4 · Title»', async () => {
    vi.mocked(api.training.start).mockResolvedValue(withLesson({ stage: 2, lesson: 4, title: 'Еда и напитки' }))
    const { wrapper } = await render(1)

    expect(wrapper.get('[data-testid=lesson-header]').text()).toBe('Модуль 2 · Урок 4 · Еда и напитки')
    expect(wrapper.find('[data-testid=daily-header]').exists()).toBe(false)
  })

  it('leaves the title out when the lesson has none, and the module when it has none', async () => {
    vi.mocked(api.training.start).mockResolvedValue(withLesson({ stage: 2, lesson: 4, title: null }))
    expect((await render(1)).wrapper.get('[data-testid=lesson-header]').text()).toBe('Модуль 2 · Урок 4')

    vi.mocked(api.training.start).mockResolvedValue(withLesson({ stage: null, lesson: 4, title: 'Моя колода' }))
    expect((await render(1)).wrapper.get('[data-testid=lesson-header]').text()).toBe('Урок 4 · Моя колода')
  })

  it('writes the lesson header in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.training.start).mockResolvedValue(withLesson({ stage: 2, lesson: 4, title: 'Food' }))
    const { wrapper } = await render(1)

    expect(wrapper.get('[data-testid=lesson-header]').text()).toBe('2-modul · 4-dars · Food')
  })

  it('a daily review says «Review» instead of a lesson', async () => {
    vi.mocked(api.training.start).mockResolvedValue(withLesson(null, 'daily_review'))
    const { wrapper } = await render(1)

    expect(wrapper.get('[data-testid=daily-header]').text()).toBe('Повторение')
    expect(wrapper.find('[data-testid=lesson-header]').exists()).toBe(false)
  })

  it('fills the height of the screen, with the card in the body and the actions below', async () => {
    vi.mocked(api.training.start).mockResolvedValue(withLesson(null, 'daily_review'))
    const { wrapper } = await render(1)

    const root = wrapper.get('[data-testid=training-view]')
    expect(root.attributes('style')).toContain('min-height: var(--tg-viewport-stable-height, 100dvh)')
    expect(root.classes()).toEqual(expect.arrayContaining(['flex', 'flex-col']))
    expect(root.find('[data-testid=flashcard]').exists()).toBe(true)
    expect(root.get('[data-testid=actions]').find('[data-testid=reveal-btn]').exists()).toBe(true)
  })

  it('shows a spinner until the first card arrives', async () => {
    let release: (value: TrainingStartResponse) => void = () => {}
    vi.mocked(api.training.start).mockReturnValue(new Promise((resolve) => (release = resolve)))
    const { wrapper } = await render(1)

    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(false)

    release(withLesson({ stage: 2, lesson: 4, title: null }))
    await flushPromises()
    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
  })

  it('shows the error state with "Retry", which asks the server for the card again', async () => {
    vi.mocked(api.training.start).mockRejectedValue(offline())
    const { wrapper } = await render(1)

    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
    expect(wrapper.get('[data-testid=error]').text()).not.toContain('⚠')
    // The header stays, so the learner can still leave.
    expect(wrapper.find('[data-testid=close-btn]').exists()).toBe(true)

    vi.mocked(api.training.next).mockResolvedValue({ card, progress: { done: 1, total: 3 } })
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(api.training.next).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=flashcard]').exists()).toBe(true)
  })
})
