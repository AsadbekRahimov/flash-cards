import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../auth'
import { useTodayStore } from '../today'
import { useTrainingStore } from '../training'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { resetTimingMarks, startTimingMarks, timingMarks, type ClientTimingPayload } from '@/composables/useTimingMarks'
import type { Card, TodayResponse } from '@/api/types'

/*
 * Where the stores set the timing marks of an open (docs/04 A.21): `/auth` answered (and the share it carries), `/today` received, the
 * first answer acknowledged by the server - which sends the marks of a sampled open.
 */

vi.mock('@/api', () => ({
  api: {
    auth: { authenticate: vi.fn() },
    today: { get: vi.fn() },
    training: { open: vi.fn(), start: vi.fn(), next: vi.fn(), review: vi.fn(), finish: vi.fn() },
  },
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ initData: () => 'signed-init-data' }),
}))

/** Bucket 0 of the sample: in at any share above 0. */
const OPEN_ID = '00000006-0000-4000-8000-000000000006'
const learner = { id: 5, first_name: 'Alice', locale: 'ru', onboarding_completed: false }
const card: Card = {
  word_id: 7, word: 'Resilient', translation: 'Устойчивый', translation_fallback: false, example: null, transcription: null,
  card_kind: 'learning', serve_token: null,
}

let clock = 0
const send = vi.fn<(payload: ClientTimingPayload) => Promise<unknown>>()

async function startedTraining() {
  vi.mocked(api.training.start).mockResolvedValue({
    session_id: 1, mode: 'daily_review', lesson: null, total_words: 2, card, progress: { done: 0, total: 2 },
  } as never)
  const store = useTrainingStore()
  await store.start(1)
  return store
}

describe('timing marks set by the stores', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    resetTimingMarks()
    clock = 0
    send.mockReset()
    send.mockResolvedValue(undefined)
    startTimingMarks({ now: () => clock, openId: OPEN_ID, platform: 'ios', cold: true, entry: 'home', send })
  })

  afterEach(() => {
    resetTimingMarks()
  })

  it('marks auth_ready once and takes the share from /auth', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt', expires_in: 900, learner, client_timing: { sample_percent: 100 } })
    clock = 320
    await useAuthStore().login()
    clock = 9000
    await useAuthStore().login()

    expect(timingMarks()).toEqual({ auth_ready: 320 })

    const training = await startedTraining()
    vi.mocked(api.training.review).mockResolvedValue({ next_review_at: null, new_interval_days: 1, easiness_factor: 2.5, card, progress: { done: 1, total: 2 } })
    await training.submitReview(4)
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('sends nothing with an /auth of an older server (no share)', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt', expires_in: 900, learner })
    await useAuthStore().login()

    const training = await startedTraining()
    vi.mocked(api.training.review).mockResolvedValue({ next_review_at: null, new_interval_days: 1, easiness_factor: 2.5, card, progress: { done: 1, total: 2 } })
    await training.submitReview(4)

    expect(timingMarks()).toMatchObject({ auth_ready: 0, first_answer_ack: 0 })
    expect(send).not.toHaveBeenCalled()
  })

  it('marks today_received when /today arrives, not when it fails', async () => {
    vi.mocked(api.today.get).mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'offline'))
    clock = 500
    await useTodayStore().load()
    expect(timingMarks()).toEqual({})

    vi.mocked(api.today.get).mockResolvedValueOnce({} as TodayResponse)
    clock = 1500
    await useTodayStore().load()
    expect(timingMarks()).toEqual({ today_received: 1500 })
  })

  it('marks first_answer_ack on the first answer the server accepted, and sends the marks then', async () => {
    vi.mocked(api.auth.authenticate).mockResolvedValue({ token: 'jwt', expires_in: 900, learner, client_timing: { sample_percent: 10 } })
    clock = 300
    await useAuthStore().login()
    const training = await startedTraining()

    // A refused answer is no acknowledgement.
    vi.mocked(api.training.review).mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'x'))
    clock = 2000
    await training.submitReview(4)
    expect(send).not.toHaveBeenCalled()

    training.status = 'ready'
    vi.mocked(api.training.review).mockResolvedValue({ next_review_at: null, new_interval_days: 1, easiness_factor: 2.5, card, progress: { done: 1, total: 2 } })
    clock = 4100
    await training.submitReview(4)
    clock = 6000
    await training.submitReview(4)

    expect(send).toHaveBeenCalledTimes(1)
    expect(send.mock.calls[0][0]).toEqual({ open_id: OPEN_ID, platform: 'ios', cold: true, entry: 'home', auth_ready: 300, first_answer_ack: 4100 })
  })
})
