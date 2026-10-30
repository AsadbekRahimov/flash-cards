import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useBattleSetupStore } from '../battleSetup'
import { useCoursesStore } from '../courses'
import { useFriendsStore } from '../friends'
import { courseDetail, courseRow, invitedBattle, options, roomBattle } from '@/api/__tests__/battleFixtures'
import type { Battle, BattleOptionsResponse, Course, CourseDetail } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    battles: { options: vi.fn(), invite: vi.fn(), get: vi.fn() },
    courses: { list: vi.fn(), get: vi.fn() },
    decks: { list: vi.fn() },
  },
}))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

function pending<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

const CATALOG: Course[] = [
  courseRow({ slug: 'english-a1', title: 'Английский A1', is_active: true }),
  courseRow({ slug: 'ielts-core', title: 'IELTS', level: 'B2', words_count: 1200 }),
  courseRow({ slug: 'empty-course', title: 'Пустой курс', words_count: 0 }),
  courseRow({ slug: 'my-work', title: 'Слова с работы', words_count: 34 }),
]

const detailOf = (slug: string): CourseDetail => courseDetail({ slug, title: slug })

function serve(catalog: Course[] = CATALOG, decks: string[] = ['my-work']) {
  vi.mocked(api.courses.list).mockResolvedValue({ courses: catalog })
  vi.mocked(api.courses.get).mockImplementation((slug) => Promise.resolve(detailOf(slug)))
  vi.mocked(api.decks.list).mockResolvedValue({ decks: decks.map((slug) => ({ slug, title: slug, words_count: 5, is_active: false, progress: { done: 0, total: 5 } })), limits: { decks: 3, deck_words: 50, used_decks: decks.length } } as never)
  vi.mocked(api.battles.options).mockImplementation(() => Promise.resolve(options()))
}

async function begun(friendshipId: number | null = 11, course: string | null = null, lessonId: number | null = null) {
  serve()
  const setup = useBattleSetupStore()
  await setup.begin(friendshipId, course, lessonId)
  return setup
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
})

describe('battle setup — the course', () => {
  it('lists the courses a battle can be played on: no decks, nothing without words', async () => {
    const setup = await begun()

    expect(setup.courses.map((course) => course.slug)).toEqual(['english-a1', 'ielts-core'])
    expect(api.decks.list).toHaveBeenCalled()
  })

  it('takes the course of the address', async () => {
    const setup = await begun(11, 'ielts-core')

    expect(setup.course).toBe('ielts-core')
    expect(api.courses.get).toHaveBeenCalledWith('ielts-core')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'ielts-core', null)
  })

  it('takes the main course when the address names none, or names one that a battle cannot be played on', async () => {
    expect((await begun()).course).toBe('english-a1')
    expect((await begun(11, 'my-work')).course).toBe('english-a1') // a deck
    expect((await begun(11, 'nowhere')).course).toBe('english-a1')
  })

  it('takes the first course when none is the main one', async () => {
    serve([courseRow({ slug: 'ielts-core', is_active: false }), courseRow({ slug: 'english-a1', is_active: false })], [])
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.course).toBe('ielts-core')
  })

  it('asks the formats and the lessons of the chosen course together', async () => {
    const detail = pending<CourseDetail>()
    const formats = pending<BattleOptionsResponse>()
    serve()
    vi.mocked(api.courses.get).mockReturnValue(detail.promise)
    vi.mocked(api.battles.options).mockReturnValue(formats.promise)
    const setup = useBattleSetupStore()

    const starting = setup.begin(11, null, null)
    await vi.waitFor(() => expect(api.courses.get).toHaveBeenCalled())

    expect(api.battles.options).toHaveBeenCalledWith(11, 'english-a1', null)
    detail.resolve(courseDetail())
    formats.resolve(options())
    await starting
  })

  it('choosing another course starts from the whole course with no format chosen, and reads its lessons and formats', async () => {
    const setup = await begun(11, null, 41)
    vi.mocked(api.battles.options).mockClear()

    await setup.selectCourse('ielts-core')

    expect(setup.course).toBe('ielts-core')
    expect(setup.lessonId).toBeNull()
    expect(api.courses.get).toHaveBeenLastCalledWith('ielts-core')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'ielts-core', null)
  })

  it('choosing the same course again changes nothing', async () => {
    const setup = await begun()
    vi.mocked(api.battles.options).mockClear()

    await setup.selectCourse('english-a1')

    expect(api.battles.options).not.toHaveBeenCalled()
  })

  it('has no course to choose when the server lists none', async () => {
    serve([], [])
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.course).toBeNull()
    expect(setup.courses).toEqual([])
    expect(api.battles.options).not.toHaveBeenCalled()
  })

  it('a catalog that could not be read is an error of the catalog, and no formats are asked for', async () => {
    serve()
    vi.mocked(api.courses.list).mockRejectedValue(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(useCoursesStore().listStatus).toBe('error')
    expect(setup.course).toBeNull()
    expect(api.battles.options).not.toHaveBeenCalled()
  })

  it('decks that could not be asked for are not hidden: the server refuses a deck later', async () => {
    serve()
    vi.mocked(api.decks.list).mockRejectedValue(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.courses.map((course) => course.slug)).toContain('my-work')
    expect(setup.course).toBe('english-a1')
  })

  it('a setup with no friend loads nothing', async () => {
    const setup = await begun(null)

    expect(api.courses.list).not.toHaveBeenCalled()
    expect(setup.course).toBeNull()
  })
})

describe('battle setup — the lesson', () => {
  it('lists the lessons of the chosen course, module by module', async () => {
    const setup = await begun()

    expect(setup.lessons).toEqual([
      { id: 31, stage: 1, number: 3, title: null },
      { id: 32, stage: 1, number: 4, title: 'Дом' },
      { id: 41, stage: 2, number: 4, title: 'Еда' },
    ])
  })

  it('has no lessons while those of another course are on hand', async () => {
    const setup = await begun()
    const other = pending<CourseDetail>()
    vi.mocked(api.courses.get).mockReturnValue(other.promise)

    const choosing = setup.selectCourse('ielts-core')
    await vi.waitFor(() => expect(api.courses.get).toHaveBeenLastCalledWith('ielts-core'))

    expect(setup.lessons).toEqual([])
    other.resolve(detailOf('ielts-core'))
    await choosing
    expect(setup.lessons).toHaveLength(3)
  })

  it('takes the lesson of the address, after the lessons are known, and asks the formats for it', async () => {
    const setup = await begun(11, 'english-a1', 41)

    expect(setup.lessonId).toBe(41)
    expect(api.battles.options).toHaveBeenCalledTimes(1)
    expect(api.battles.options).toHaveBeenCalledWith(11, 'english-a1', 41)
  })

  it('drops a lesson that is not in the course: the whole course', async () => {
    const setup = await begun(11, 'english-a1', 999)

    expect(setup.lessonId).toBeNull()
    expect(api.battles.options).toHaveBeenCalledWith(11, 'english-a1', null)
  })

  it('ignores the lesson of the address when the course of the address was not usable', async () => {
    const setup = await begun(11, 'my-work', 41)

    expect(setup.course).toBe('english-a1')
    expect(setup.lessonId).toBeNull()
  })

  it('choosing a lesson reads the formats again for it; choosing “whole course” asks for none', async () => {
    const setup = await begun()
    vi.mocked(api.battles.options).mockClear()

    await setup.selectLesson(32)
    expect(api.battles.options).toHaveBeenLastCalledWith(11, 'english-a1', 32)
    await setup.selectLesson(null)
    expect(api.battles.options).toHaveBeenLastCalledWith(11, 'english-a1', null)
    await setup.selectLesson(null)
    expect(api.battles.options).toHaveBeenCalledTimes(2)
  })

  it('the lessons that could not be read leave the course as a whole, and “Retry” asks again', async () => {
    serve()
    vi.mocked(api.courses.get).mockRejectedValueOnce(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)

    expect(useCoursesStore().detailStatus).toBe('error')
    expect(setup.lessons).toEqual([])
    expect(setup.optionsStatus).toBe('ready')

    await setup.reloadLessons()
    expect(useCoursesStore().detailStatus).toBe('ready')
    expect(setup.lessons).toHaveLength(3)
  })
})

describe('battle setup — the format', () => {
  it('chooses the first format that can be played', async () => {
    const setup = await begun()

    expect(setup.mode).toBe('pairs')
    expect(setup.selectedMode?.round_seconds).toBe(45)
  })

  it('skips a format that cannot be played, and chooses none when none can', async () => {
    serve()
    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { pairs: { state: 'not_enough', reason: 'no_words' }, collocation: { state: 'disabled' } } }))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)
    expect(setup.mode).toBe('sentence')

    vi.mocked(api.battles.options).mockResolvedValue(
      options({ modes: { pairs: { state: 'not_enough' }, collocation: { state: 'not_enough' }, sentence: { state: 'disabled' }, dictation: { state: 'pro_required' } } }),
    )
    await setup.selectLesson(32)
    expect(setup.mode).toBeNull()
    expect(setup.canInvite).toBe(false)
  })

  it('takes only a format that can be played', async () => {
    serve()
    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { collocation: { state: 'not_enough' } } }))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)

    setup.selectMode('collocation')
    expect(setup.mode).toBe('pairs')
    setup.selectMode('dictation')
    expect(setup.mode).toBe('dictation')
  })

  it('keeps the chosen format while it can still be played, and moves to another when it cannot', async () => {
    const setup = await begun()
    setup.selectMode('sentence')

    await setup.selectLesson(32)
    expect(setup.mode).toBe('sentence')

    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { sentence: { state: 'not_enough', reason: 'no_items' } } }))
    await setup.selectLesson(41)
    expect(setup.mode).toBe('pairs')
  })

  it('an answer that comes late for an earlier choice is dropped', async () => {
    const setup = await begun()
    const early = pending<BattleOptionsResponse>()
    vi.mocked(api.battles.options).mockReturnValueOnce(early.promise).mockResolvedValueOnce(options({ friend_access: 'no_access' }))

    const first = setup.selectLesson(32)
    await setup.selectLesson(41)
    early.resolve(options({ friend_access: 'available' }))
    await first

    expect(setup.lessonId).toBe(41)
    expect(setup.options?.friend_access).toBe('no_access')
  })

  it('a format that could not be read is an error with the text, and nothing is chosen', async () => {
    serve()
    vi.mocked(api.battles.options).mockRejectedValue(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.optionsStatus).toBe('error')
    expect(setup.optionsError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(setup.options).toBeNull()
    expect(setup.canInvite).toBe(false)
    expect(setup.blocked).toBeNull()
  })

  it('“Retry” reads them again', async () => {
    serve()
    vi.mocked(api.battles.options).mockRejectedValueOnce(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)

    await setup.loadOptions()

    expect(setup.optionsStatus).toBe('ready')
    expect(setup.mode).toBe('pairs')
  })
})

describe('battle setup — what the server refuses when it is asked for the formats', () => {
  async function refused(code: string, status: number, kind: ConstructorParameters<typeof ApiClientError>[0]) {
    serve()
    vi.mocked(api.battles.options).mockRejectedValue(apiError(kind, status, code))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)
    return setup
  }

  it.each([
    ['friendship_not_found', 404, 'not_found'],
    ['not_friends', 403, 'forbidden'],
  ] as const)('%s: the friend is not a friend any more', async (code, status, kind) => {
    expect((await refused(code, status, kind)).blocked).toBe('friend')
  })

  it('battles are switched off', async () => {
    expect((await refused('battle_disabled', 409, 'conflict')).blocked).toBe('off')
  })

  it('practice is not open to the learner', async () => {
    expect((await refused('practice_unavailable', 403, 'forbidden')).blocked).toBe('unavailable')
  })

  it('practice is behind Pro for the learner: the paywall, not a dead end', async () => {
    const setup = await refused('practice_pro_required', 403, 'forbidden')

    expect(setup.paywall).toBe(true)
    expect(setup.blocked).toBeNull()
    setup.dismissPaywall()
    expect(setup.paywall).toBe(false)
  })

  it('a course that cannot have a battle (private, unpublished) is not offered again: the next course takes its place', async () => {
    serve()
    vi.mocked(api.battles.options).mockImplementation((_friendship, slug) =>
      slug === 'english-a1' ? Promise.reject(apiError('not_found', 404, 'course_not_found')) : Promise.resolve(options()),
    )
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.courses.map((course) => course.slug)).toEqual(['ielts-core'])
    expect(setup.course).toBe('ielts-core')
    expect(setup.optionsStatus).toBe('ready')
  })

  it('a lesson the server does not know is dropped: the whole course is asked for', async () => {
    serve()
    vi.mocked(api.battles.options).mockImplementation((_friendship, _slug, lesson) =>
      lesson === 41 ? Promise.reject(apiError('not_found', 404, 'lesson_not_found')) : Promise.resolve(options()),
    )
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)

    await setup.selectLesson(41)

    expect(setup.lessonId).toBeNull()
    expect(setup.optionsStatus).toBe('ready')
  })
})

describe('battle setup — the friend', () => {
  it('knows the name from the formats', async () => {
    const setup = await begun()

    expect(setup.friendName).toBe('Dilnoza')
  })

  it('knows the name from the Friends list before the formats come', async () => {
    serve()
    const formats = pending<BattleOptionsResponse>()
    vi.mocked(api.battles.options).mockReturnValue(formats.promise)
    useFriendsStore().friends = {
      invite_link: null,
      limit: 50,
      friends: [{ id: 11, first_name: 'Тимур', streak: 3, words_learned: 10, since: null }],
      incoming: [],
      outgoing: [],
    }
    const setup = useBattleSetupStore()

    void setup.begin(11, null, null)
    await vi.waitFor(() => expect(api.battles.options).toHaveBeenCalled())

    expect(setup.friendName).toBe('Тимур')
    formats.resolve(options())
  })

  it('does not know the name of a friend that is not in the list', async () => {
    serve()
    vi.mocked(api.battles.options).mockRejectedValue(apiError('network', 0, 'network'))
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.friendName).toBeNull()
  })

  it('a friend without access to practice cannot be invited', async () => {
    serve()
    vi.mocked(api.battles.options).mockResolvedValue(options({ friend_access: 'no_access' }))
    const setup = useBattleSetupStore()

    await setup.begin(11, null, null)

    expect(setup.options?.friend_access).toBe('no_access')
    expect(setup.canInvite).toBe(false)
  })

  it('a format that is short of material or switched off cannot be invited to, whatever the state holds', async () => {
    serve()
    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { sentence: { state: 'not_enough', reason: 'no_items', available: 3 }, dictation: { state: 'disabled' } } }))
    const setup = useBattleSetupStore()
    await setup.begin(11, null, null)
    expect(setup.canInvite).toBe(true)

    for (const mode of ['sentence', 'dictation'] as const) {
      setup.mode = mode
      expect(setup.canInvite).toBe(false)
      expect(await setup.invite()).toBeNull()
    }
    expect(api.battles.invite).not.toHaveBeenCalled()
  })
})

describe('battle setup — the invitation', () => {
  it('sends the friend, the format, the course and the lesson, and gives the id of the new battle', async () => {
    const setup = await begun(11, 'english-a1', 41)
    setup.selectMode('sentence')
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

    expect(setup.canInvite).toBe(true)
    expect(await setup.invite()).toBe(21)

    expect(api.battles.invite).toHaveBeenCalledWith({ friendship_id: 11, mode: 'sentence', course: 'english-a1', lesson_id: 41 })
    expect(setup.inviting).toBe(false)
  })

  it('the whole course is a null lesson', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

    await setup.invite()

    expect(api.battles.invite).toHaveBeenCalledWith({ friendship_id: 11, mode: 'pairs', course: 'english-a1', lesson_id: null })
  })

  it('sends one invitation: a second press while the first is on its way is ignored', async () => {
    const setup = await begun()
    const reply = pending<ReturnType<typeof invitedBattle>>()
    vi.mocked(api.battles.invite).mockReturnValue(reply.promise)

    const first = setup.invite()
    expect(setup.inviting).toBe(true)
    expect(setup.canInvite).toBe(false)
    expect(await setup.invite()).toBeNull()
    reply.resolve(invitedBattle({ id: 21 }))
    await first

    expect(api.battles.invite).toHaveBeenCalledTimes(1)
  })

  describe('when a battle is in the way (409 battle_busy)', () => {
    const BUSY = 'У вас уже есть активный баттл. Завершите его, чтобы начать новый.'
    const refused = (id: number | null = 9) => apiError('conflict', 409, 'battle_busy', id === null ? null : { battle_id: id })

    it('a battle of the learner’s own is in the way: the text and the id to go to', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused())
      vi.mocked(api.battles.get).mockResolvedValue(invitedBattle({ id: 9, role: 'challenger' }))

      expect(await setup.invite()).toBeNull()

      expect(api.battles.get).toHaveBeenCalledWith(9)
      expect(setup.inviteError).toBe(BUSY)
      expect(setup.busyBattleId).toBe(9)
      expect(setup.inviting).toBe(false)
      setup.clearInviteError()
      expect(setup.inviteError).toBe('')
      expect(setup.busyBattleId).toBeNull()
    })

    it('the friend invited the learner at the same moment: their invitation is the one to go to - its id is the answer, nothing is refused', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused(16))
      vi.mocked(api.battles.get).mockResolvedValue(invitedBattle({ id: 16, role: 'opponent' }))

      expect(await setup.invite()).toBe(16)

      expect(api.battles.get).toHaveBeenCalledWith(16)
      expect(setup.inviteError).toBe('')
      expect(setup.busyBattleId).toBeNull()
      expect(setup.inviting).toBe(false)
    })

    it('a battle that is on (the learner is in its room) is not an invitation to accept', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused())
      vi.mocked(api.battles.get).mockResolvedValue(roomBattle({ id: 9, role: 'opponent' }))

      expect(await setup.invite()).toBeNull()

      expect(setup.inviteError).toBe(BUSY)
      expect(setup.busyBattleId).toBe(9)
    })

    it('the battle in the way cannot be read: the text stands, with the way to it', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused())
      vi.mocked(api.battles.get).mockRejectedValue(apiError('network', 0, 'network'))

      expect(await setup.invite()).toBeNull()

      expect(setup.inviteError).toBe(BUSY)
      expect(setup.busyBattleId).toBe(9)
    })

    it('a refusal that names no battle is not looked up', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused(null))

      expect(await setup.invite()).toBeNull()

      expect(api.battles.get).not.toHaveBeenCalled()
      expect(setup.inviteError).toBe(BUSY)
      expect(setup.busyBattleId).toBeNull()
    })

    it('other refusals are not looked up either', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_friend_no_access'))

      await setup.invite()

      expect(api.battles.get).not.toHaveBeenCalled()
    })

    it('a lookup that comes back after the setup was left (another friend) changes nothing', async () => {
      const setup = await begun()
      vi.mocked(api.battles.invite).mockRejectedValue(refused(16))
      const looked = pending<Battle>()
      vi.mocked(api.battles.get).mockReturnValue(looked.promise)

      const inviting = setup.invite()
      await Promise.resolve()
      await setup.begin(12, null, null)
      looked.resolve(invitedBattle({ id: 16, role: 'opponent' }))

      expect(await inviting).toBeNull()
      expect(setup.inviteError).toBe('')
    })
  })

  it('the material is gone since the numbers were read: told, and the formats are read again', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('validation', 422, 'battle_material_unavailable', { mode: 'pairs', available: 12, required: 20, reason: 'no_words' }))
    vi.mocked(api.battles.options).mockClear()
    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { pairs: { state: 'not_enough', available: 12, reason: 'no_words' } } }))

    await setup.invite()

    expect(setup.inviteError).toBe('Для баттла в этом формате не хватает материала. Выберите другой урок или формат.')
    expect(api.battles.options).toHaveBeenCalledTimes(1)
    expect(setup.mode).toBe('collocation')
  })

  it.each([
    ['practice_mode_disabled', 409, 'conflict', 'Этот режим сейчас выключен. Выберите другой.'],
    ['battle_friend_no_access', 409, 'conflict', 'У вашего друга пока нет доступа к практике.'],
  ] as const)('%s: told, and the formats are read again', async (code, status, kind, message) => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError(kind, status, code))
    vi.mocked(api.battles.options).mockClear()

    await setup.invite()

    expect(setup.inviteError).toBe(message)
    expect(api.battles.options).toHaveBeenCalledTimes(1)
  })

  it('the friend is not a friend any more: the setup is closed with the reason', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'not_friends'))

    await setup.invite()

    expect(setup.blocked).toBe('friend')
  })

  it('battles were switched off meanwhile', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_disabled'))

    await setup.invite()

    expect(setup.blocked).toBe('off')
  })

  it('Pro is needed: the paywall, and no sentence of refusal beside it', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))

    await setup.invite()

    expect(setup.paywall).toBe(true)
    expect(setup.inviteError).toBe('')
  })

  it('too many invitations a minute is told in words', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('rate_limited', 429, 'too_many_requests'))

    await setup.invite()

    expect(setup.inviteError).toBe('Слишком много запросов. Подождите немного и повторите.')
  })

  it('a lost connection is told, and the learner can press again', async () => {
    const setup = await begun()
    vi.mocked(api.battles.invite).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValueOnce(invitedBattle({ id: 22 }))

    expect(await setup.invite()).toBeNull()
    expect(setup.inviteError).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    expect(await setup.invite()).toBe(22)
    expect(setup.inviteError).toBe('')
  })

  it('cannot invite before the formats are read, or with no format that can be played', async () => {
    serve()
    const setup = useBattleSetupStore()
    expect(setup.canInvite).toBe(false)
    expect(await setup.invite()).toBeNull()
    expect(api.battles.invite).not.toHaveBeenCalled()
  })

  it('an answer that comes after a new setup was started moves nobody', async () => {
    const setup = await begun()
    const reply = pending<ReturnType<typeof invitedBattle>>()
    vi.mocked(api.battles.invite).mockReturnValue(reply.promise)

    const sending = setup.invite()
    serve()
    await setup.begin(12, null, null)
    reply.resolve(invitedBattle({ id: 21 }))

    expect(await sending).toBeNull()
    expect(setup.friendshipId).toBe(12)
  })
})
