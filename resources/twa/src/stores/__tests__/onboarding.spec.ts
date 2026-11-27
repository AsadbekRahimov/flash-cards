import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { onboardingFixture } from '@/api/__tests__/onboardingFixture'
import { hasSeen } from '@/composables/seenFlag'
import { INTRO_KEY, useOnboardingStore } from '../onboarding'
import type { Learner } from '@/api/types'

/*
 * The store of the onboarding (docs/40 §2.1): every step is saved at once (PUT with the body of the contract), the saved answers come
 * back into the draft (resume), a failure keeps the answer for "Retry", 409 means "finished elsewhere", 403 puts the goal back to the
 * plan's limit, `complete` finishes it and lets the guard go.
 */

const state = vi.hoisted(() => ({
  ensure: vi.fn(),
  learner: null as Learner | null,
  telegramName: 'Aziz' as string | null,
}))

vi.mock('@/api', () => ({
  api: { onboarding: { get: vi.fn(), saveStep: vi.fn(), complete: vi.fn(), placement: vi.fn(), placementAnswers: vi.fn() } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ensure: state.ensure,
    get learner() {
      return state.learner
    },
  }),
}))

vi.mock('@/composables/telegramOnboarding', () => ({
  telegramFirstName: () => state.telegramName,
}))

const network = () => new ApiClientError('network', 0, 'network', 'Failed to fetch')

describe('onboarding store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
    state.ensure.mockResolvedValue(undefined)
    state.telegramName = 'Aziz'
    state.learner = { id: 1, first_name: 'Aziz', locale: 'ru', onboarding_completed: false, onboarding: { completed: false, step: null } }
  })

  it('loads the state, prefills the name from Telegram and the defaults of the kit', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    const store = useOnboardingStore()

    await store.load()

    expect(store.status).toBe('ready')
    expect(store.draft).toMatchObject({
      name: 'Aziz',
      nameFromTelegram: true,
      age: null,
      goals: [],
      level: null,
      dailyGoal: 20,
      reminderTime: '20:00',
      reminderCustom: false,
      botMessages: true,
      writeAccess: 'not_asked',
    })
    expect(state.learner?.onboarding).toEqual({ completed: false, step: 'name' })
  })

  it('resumes with what was saved: the chosen name, the row of the course, a custom time', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({
      step: 'plan',
      values: {
        display_name: 'Азиз',
        age_range: '18_24',
        goals: ['career', 'travel'],
        level: 'A2',
        course: { slug: 'english-a1-a2', title: 'A1–A2', level_label: 'A1–A2', stages_count: 20 },
        daily_goal: 40,
        reminder: { enabled: true, time: '07:45' },
      },
    }))
    const store = useOnboardingStore()

    await store.load()

    expect(store.draft).toMatchObject({
      name: 'Азиз',
      nameFromTelegram: false,
      age: '18_24',
      goals: ['career', 'travel'],
      level: 'A1',
      dailyGoal: 40,
      reminderTime: '07:45',
      reminderCustom: true,
    })
    expect(state.learner?.display_name).toBe('Азиз')
  })

  it('takes the goal and the reminder the server sends by default only once their steps were saved', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({
      step: 'level',
      values: { daily_goal: 10, reminder: { enabled: false, time: null } },
    }))
    const store = useOnboardingStore()

    await store.load()

    expect(store.draft).toMatchObject({ dailyGoal: 20, reminderTime: '20:00', reminderCustom: false })
  })

  it('starts the goal at the plan limit when the recommended one is behind Pro; no Telegram name - an empty field', async () => {
    state.telegramName = null
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({
      options: { ...onboardingFixture().options, daily_goal_max: 10 },
    }))
    const store = useOnboardingStore()

    await store.load()

    expect(store.draft.dailyGoal).toBe(10)
    expect(store.draft.name).toBe('')
    expect(store.draft.nameFromTelegram).toBe(false)
  })

  it('says why the load failed (no connection)', async () => {
    vi.mocked(api.onboarding.get).mockRejectedValue(network())
    const store = useOnboardingStore()

    await store.load()

    expect(store.status).toBe('error')
    expect(store.loadError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
  })

  it('saves each step with the body of the contract', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockImplementation(async () => onboardingFixture())
    const store = useOnboardingStore()
    await store.load()

    store.draft.name = '  Азиз '
    store.draft.age = '25_34'
    store.draft.goals = ['study', 'media']
    store.draft.level = 'B1'
    store.draft.dailyGoal = 40
    store.draft.reminderTime = '21:30'
    store.draft.writeAccess = 'granted'

    expect(await store.saveStep('name')).toBe('ok')
    expect(await store.saveStep('age')).toBe('ok')
    expect(await store.saveStep('goals')).toBe('ok')
    expect(await store.saveStep('level')).toBe('ok')
    expect(await store.saveStep('daily_goal')).toBe('ok')
    expect(await store.saveReminder(true)).toBe('ok')
    expect(await store.saveReminder(false)).toBe('ok')

    expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
      ['name', { display_name: 'Азиз' }],
      ['age', { age_range: '25_34' }],
      ['goals', { goals: ['study', 'media'] }],
      ['level', { level: 'B1', course_slug: 'english-b1' }],
      ['daily_goal', { daily_goal: 40 }],
      ['reminder', { enabled: true, time: '21:30', write_access: 'granted' }],
      ['reminder', { enabled: false, time: null, write_access: 'granted' }],
    ])
  })

  it('moves the step of the learner with the answer, so a reopened app resumes there', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockResolvedValue(onboardingFixture({ step: 'goals', values: { age_range: '18_24' } }))
    const store = useOnboardingStore()
    await store.load()
    store.draft.age = '18_24'

    await store.saveStep('age')

    expect(state.learner?.onboarding).toEqual({ completed: false, step: 'goals' })
    expect(store.data?.step).toBe('goals')
  })

  it('keeps the answer on a failed save and sends it again on "Retry"', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockRejectedValueOnce(network()).mockResolvedValueOnce(onboardingFixture({ step: 'goals' }))
    const store = useOnboardingStore()
    await store.load()
    store.draft.age = '35_44'

    expect(await store.saveStep('age')).toBe('error')
    expect(store.saveError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(store.saveErrorStep).toBe('age')
    expect(store.draft.age).toBe('35_44')
    expect(store.saving).toBeNull()

    expect(await store.saveStep('age')).toBe('ok')
    expect(store.saveError).toBe('')
    expect(vi.mocked(api.onboarding.saveStep).mock.calls).toEqual([
      ['age', { age_range: '35_44' }],
      ['age', { age_range: '35_44' }],
    ])
  })

  it('sends nothing without an answer, and one save at a time', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    let release: (value: ReturnType<typeof onboardingFixture>) => void = () => {}
    vi.mocked(api.onboarding.saveStep).mockImplementation(() => new Promise((resolve) => { release = resolve }))
    const store = useOnboardingStore()
    await store.load()

    expect(await store.saveStep('age')).toBe('error')
    expect(api.onboarding.saveStep).not.toHaveBeenCalled()

    store.draft.age = 'lt14'
    const first = store.saveStep('age')
    await vi.waitFor(() => expect(api.onboarding.saveStep).toHaveBeenCalledTimes(1))
    expect(await store.saveStep('age')).toBe('error')
    release(onboardingFixture())
    expect(await first).toBe('ok')
    expect(api.onboarding.saveStep).toHaveBeenCalledTimes(1)
  })

  it('reads 409 onboarding_completed as finished elsewhere: the learner passes the guard, the intro is seen', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockRejectedValue(new ApiClientError('conflict', 409, 'onboarding_completed', 'done'))
    const store = useOnboardingStore()
    await store.load()
    store.draft.goals = ['career']

    expect(await store.saveStep('goals')).toBe('completed')
    expect(state.learner?.onboarding).toEqual({ completed: true, step: null })
    expect(state.learner?.onboarding_completed).toBe(true)
    expect(hasSeen(INTRO_KEY)).toBe(true)
    expect(store.saveError).toBe('')
  })

  it('puts the goal back to the limit on 403 daily_goal_pro_required and locks the goals above it', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockRejectedValue(
      new ApiClientError('forbidden', 403, 'daily_goal_pro_required', 'pro', undefined, { max: 10 }),
    )
    const store = useOnboardingStore()
    await store.load()
    store.draft.dailyGoal = 40

    expect(await store.saveStep('daily_goal')).toBe('pro_required')
    expect(store.goalProRequired).toBe(true)
    expect(store.goalMax).toBe(10)
    expect(store.draft.dailyGoal).toBe(10)
    expect(store.saveError).toBe('')
  })

  it('keeps the reason of 422 display_name_invalid for the field', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture())
    vi.mocked(api.onboarding.saveStep).mockRejectedValue(
      new ApiClientError('validation', 422, 'display_name_invalid', 'bad', undefined, { reason: 'link' }),
    )
    const store = useOnboardingStore()
    await store.load()

    expect(await store.saveStep('name')).toBe('invalid')
    expect(store.nameError).toBe('link')
    store.clearError()
    expect(store.nameError).toBeNull()
  })

  it('completes: the learner is done, the intro slides are seen', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({ step: 'plan' }))
    vi.mocked(api.onboarding.complete).mockResolvedValue({ completed: true, course: { slug: 'english-b1', title: 'B1' }, next_lesson_id: 7 })
    const store = useOnboardingStore()
    await store.load()

    expect(await store.complete()).toBe('ok')
    expect(state.learner?.onboarding).toEqual({ completed: true, step: null })
    expect(hasSeen(INTRO_KEY)).toBe(true)
  })

  it('names the missing step on 422 onboarding_incomplete, and keeps a failed finish for "Retry"', async () => {
    vi.mocked(api.onboarding.get).mockResolvedValue(onboardingFixture({ step: 'plan' }))
    vi.mocked(api.onboarding.complete)
      .mockRejectedValueOnce(new ApiClientError('validation', 422, 'onboarding_incomplete', 'x', undefined, { missing: ['level'] }))
      .mockRejectedValueOnce(network())
    const store = useOnboardingStore()
    await store.load()

    expect(await store.complete()).toBe('incomplete')
    expect(store.missingStep).toBe('level')
    expect(await store.complete()).toBe('error')
    expect(store.saveErrorStep).toBe('complete')
    expect(state.learner?.onboarding?.completed).toBe(false)
  })

  describe('the placement test (docs/40 §2.1a)', () => {
    const test = () => ({
      questions: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, word: `w${i + 1}`, options: ['a', 'b', 'c', 'd'] })),
      expires_at: new Date(Date.now() + 60_000).toISOString(),
    })

    async function started() {
      const store = useOnboardingStore()
      store.take(onboardingFixture({ step: 'level' }))
      vi.mocked(api.onboarding.placement).mockResolvedValue(test())
      expect(await store.startPlacement()).toBe('ok')
      for (let i = 0; i < 9; i++) await store.answerPlacement(i % 4)
      return store
    }

    it('a 422 of the answers drops the set: the next start asks a new one', async () => {
      const store = await started()
      vi.mocked(api.onboarding.placementAnswers).mockRejectedValueOnce(new ApiClientError('validation', 422, 'validation', 'bad'))

      expect(await store.answerPlacement(0)).toBe('error')
      expect(store.placement.phase).toBe('idle')
      expect(store.placement.test).toBeNull()
      expect(store.placement.error).not.toBe('')

      await store.startPlacement()
      expect(api.onboarding.placement).toHaveBeenCalledTimes(2)
    })

    it('takes the row of the level when the course of the result is not among the rows', async () => {
      const store = await started()
      vi.mocked(api.onboarding.placementAnswers).mockResolvedValueOnce({ level: 'A2', course_slug: 'english-a2-old', correct_by_level: { A1: 2, A2: 1 } })

      expect(await store.answerPlacement(0)).toBe('ok')
      expect(store.draft.level).toBe('A1')
      expect(store.placement.suggested).toBe('A1')
      expect(api.onboarding.saveStep).not.toHaveBeenCalled()
    })

    it('leaves the choice alone when no row fits the result', async () => {
      const store = await started()
      store.draft.level = 'C2'
      vi.mocked(api.onboarding.placementAnswers).mockResolvedValueOnce({ level: 'X9', course_slug: 'nope', correct_by_level: {} })

      await store.answerPlacement(0)
      expect(store.placement.phase).toBe('result')
      expect(store.draft.level).toBe('C2')
      expect(store.placement.suggested).toBeNull()
    })

    it('an empty set is an error, not an endless skeleton', async () => {
      const store = useOnboardingStore()
      vi.mocked(api.onboarding.placement).mockResolvedValue({ questions: [], expires_at: new Date().toISOString() })

      expect(await store.startPlacement()).toBe('error')
      expect(store.placement.phase).toBe('idle')
      expect(store.placement.error).toBe('Непредвиденная ошибка.')
    })
  })
})
