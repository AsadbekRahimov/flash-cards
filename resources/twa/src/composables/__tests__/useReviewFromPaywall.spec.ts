import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'
import { useReviewFromPaywall, type ReviewFromPaywallOptions } from '../useReviewFromPaywall'
import { useToast } from '../useToast'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useTrainingStore } from '@/stores/training'

vi.mock('@/api', () => ({ api: { training: { open: vi.fn() } } }))

const session = (id: number) => ({ session_id: id, mode: 'daily_review' as const, lesson: null, total_words: 5 })

async function setup(options?: ReviewFromPaywallOptions) {
  const close = vi.fn()
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/here', name: 'here', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
  await router.push('/here')

  let review!: () => Promise<void>
  const Harness = defineComponent({
    setup() {
      review = useReviewFromPaywall(close, options).reviewFromPaywall
      return () => null
    },
  })
  mount(Harness, { global: { plugins: [router] } })
  return { close, router, review: () => review() }
}

describe('useReviewFromPaywall', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    useToast().hide()
  })
  afterEach(() => useToast().hide())

  it('closes the sheet, opens a personal daily review and goes to the training screen', async () => {
    vi.mocked(api.training.open).mockResolvedValue(session(41))
    const { close, router, review } = await setup()

    await review()

    expect(close).toHaveBeenCalledTimes(1)
    expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
    expect(router.currentRoute.value.name).toBe('training')
    expect(router.currentRoute.value.params.sessionId).toBe('41')
  })

  it('closes the sheet before the session is asked for, so the screen is free while it opens', async () => {
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.training.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { close, router, review } = await setup()

    const running = review()
    expect(close).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('here')

    finish(session(42))
    await running
    expect(router.currentRoute.value.name).toBe('training')
  })

  it('stays where it is on a refusal and leaves the message in the training store for a screen that draws it', async () => {
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('not_found', 404, 'lesson_not_found', 'x'))
    const { close, router, review } = await setup()

    await review()

    expect(close).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('here')
    expect(useTrainingStore().openError).toBe('Урок не найден.')
    expect(useToast().toast.value).toBeNull()
  })

  it('shows a refusal as a toast and forgets it when the screen has no place for the message', async () => {
    vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('not_found', 404, 'lesson_not_found', 'x'))
    const { router, review } = await setup({ toastErrors: true })

    await review()

    expect(router.currentRoute.value.name).toBe('here')
    expect(useToast().toast.value?.text).toBe('Урок не найден.')
    expect(useTrainingStore().openError).toBe('')
  })

  it('does not toast the tap that came while a session was already being opened', async () => {
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.training.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { router, review } = await setup({ toastErrors: true })

    const first = review()
    await review() // the store answers null at once: it is busy, nothing failed
    expect(useToast().toast.value).toBeNull()

    finish(session(43))
    await first
    await flushPromises()
    expect(api.training.open).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.params.sessionId).toBe('43')
  })
})
