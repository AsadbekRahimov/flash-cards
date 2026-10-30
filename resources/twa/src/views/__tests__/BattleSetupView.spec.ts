import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import BattleSetupView from '../BattleSetupView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { courseDetail, courseRow, invitedBattle, options } from '@/api/__tests__/battleFixtures'
import type { BattleOptionsResponse, Course, CourseDetail } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    battles: { options: vi.fn(), invite: vi.fn() },
    courses: { list: vi.fn(), get: vi.fn() },
    decks: { list: vi.fn() },
    me: { get: vi.fn() },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({
  backHandler: null as (() => void) | null,
  haptic: vi.fn(),
}))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: (handler: () => void) => {
      tg.backHandler = handler
      return () => {
        tg.backHandler = null
      }
    },
    haptic: tg.haptic,
    notificationHaptic: () => {},
    selectionHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }, [h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') })]),
    }),
  }
})

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
  courseRow({ slug: 'ielts-core', title: 'IELTS: академическая лексика', level: 'B2', words_count: 1200 }),
  courseRow({ slug: 'empty-course', title: 'Пустой курс', words_count: 0 }),
  courseRow({ slug: 'my-work', title: 'Слова с работы', words_count: 34 }),
]

function serve(overrides: { catalog?: Course[]; decks?: string[]; options?: BattleOptionsResponse } = {}) {
  vi.mocked(api.courses.list).mockResolvedValue({ courses: overrides.catalog ?? CATALOG })
  vi.mocked(api.courses.get).mockImplementation((slug) => Promise.resolve(courseDetail({ slug, title: slug }) as CourseDetail))
  const decks = overrides.decks ?? ['my-work']
  vi.mocked(api.decks.list).mockResolvedValue({
    decks: decks.map((slug) => ({ slug, title: slug, words_count: 5, is_active: false, progress: { done: 0, total: 5 } })),
    limits: { decks: 3, deck_words: 50, used_decks: decks.length },
  } as never)
  vi.mocked(api.battles.options).mockImplementation(() => Promise.resolve(overrides.options ?? options()))
}

const mounted: VueWrapper[] = []

async function render(query: { friendship?: number | null; course?: string | null; lesson?: number | null } = {}) {
  const props = {
    friendshipId: query.friendship === undefined ? 11 : query.friendship,
    course: query.course ?? null,
    lessonId: query.lesson ?? null,
  }
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/friends', name: 'friends', component: stub },
      { path: '/battles/new', name: 'battle-new', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
    ],
  })
  await router.push({ name: 'battle-new' })
  const wrapper = mount(BattleSetupView, { props, global: { plugins: [router] }, attachTo: document.body })
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>

const has = (r: Rendered, testid: string) => r.wrapper.find(`[data-testid=${testid}]`).exists()
const get = (r: Rendered, testid: string) => r.wrapper.get(`[data-testid=${testid}]`)
const text = (r: Rendered, testid: string) => get(r, testid).text()
const click = async (r: Rendered, testid: string) => {
  await get(r, testid).trigger('click')
  await flushPromises()
}
const lessonOptions = (r: Rendered) => r.wrapper.findAll('[data-testid=lesson-select] option').map((option) => option.text())
const route = (r: Rendered) => r.router.currentRoute.value

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  tg.backHandler = null
  serve()
})
afterEach(() => {
  while (mounted.length > 0) mounted.pop()?.unmount()
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('BattleSetupView — the screen', () => {
  it('is a skeleton while the catalog is read, then the form', async () => {
    const catalog = pending<{ courses: Course[] }>()
    vi.mocked(api.courses.list).mockReturnValue(catalog.promise)
    const r = await render()

    expect(has(r, 'spinner')).toBe(true)
    expect(has(r, 'course-section')).toBe(false)

    catalog.resolve({ courses: CATALOG })
    await flushPromises()
    expect(has(r, 'spinner')).toBe(false)
    expect(has(r, 'course-section')).toBe(true)
    expect(authEnsure).toHaveBeenCalled()
  })

  it('has one heading for every friend and shows the friend in a card under it', async () => {
    const r = await render()

    expect(r.wrapper.get('h1').text()).toBe('Новый баттл')
    expect(text(r, 'friend-name')).toBe('Dilnoza')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'english-a1', null)
  })

  it('has the steps in order: the friend, the course, the lesson, the format, then “Invite”', async () => {
    const r = await render()

    const order = ['friend-card', 'course-section', 'lesson-section', 'format-section', 'invite-section']
    const html = r.wrapper.html()
    const positions = order.map((id) => html.indexOf(`data-testid="${id}"`))
    expect(positions.every((position) => position >= 0)).toBe(true)
    expect([...positions].sort((a, b) => a - b)).toEqual(positions)
  })

  it('has one primary button, “Invite”, and no tab bar of its own', async () => {
    const r = await render()

    const primaries = r.wrapper.findAll('button.btn-primary')
    expect(primaries).toHaveLength(1)
    expect(primaries[0]?.text()).toBe('Пригласить')
    expect(primaries[0]?.classes()).toContain('btn-lg')
  })

  it('the system “Back” leads to the Friends', async () => {
    const r = await render()

    expect(tg.backHandler).not.toBeNull()
    tg.backHandler?.()
    await flushPromises()

    expect(route(r).name).toBe('friends')
  })
})

describe('BattleSetupView — no friend, or no catalog', () => {
  it('without a friend in the address it says a battle is played by two, and leads to the friends; nothing is read', async () => {
    const r = await render({ friendship: null })

    expect(has(r, 'no-friend')).toBe(true)
    expect(r.wrapper.text()).toContain('Выберите друга')
    expect(api.courses.list).not.toHaveBeenCalled()
    await click(r, 'to-friends')
    expect(route(r).name).toBe('friends')
  })

  it('a catalog that could not be read: the error with “Retry”', async () => {
    vi.mocked(api.courses.list).mockRejectedValueOnce(apiError('network', 0, 'network'))
    const r = await render()

    expect(has(r, 'error')).toBe(true)
    expect(r.wrapper.text()).toContain('Нет связи с сервером')
    expect(has(r, 'invite-btn')).toBe(false)

    await click(r, 'retry-btn')
    expect(has(r, 'error')).toBe(false)
    expect(has(r, 'course-section')).toBe(true)
  })

  it('a failed sign-in is the error, and nothing is read', async () => {
    authEnsure.mockRejectedValue(new Error('opened_outside_telegram'))
    const r = await render()

    expect(r.wrapper.text()).toContain('Откройте приложение через Telegram.')
    expect(api.courses.list).not.toHaveBeenCalled()
  })

  it('no course to play on: says so', async () => {
    serve({ catalog: [courseRow({ slug: 'empty-course', words_count: 0 })] })
    const r = await render()

    expect(has(r, 'no-courses')).toBe(true)
    expect(has(r, 'invite-btn')).toBe(false)
  })

  it('starts again when the address changes (another friend)', async () => {
    const r = await render()
    vi.mocked(api.battles.options).mockClear()

    await r.wrapper.setProps({ friendshipId: 12 })
    await flushPromises()

    expect(api.battles.options).toHaveBeenCalledWith(12, 'english-a1', null)
  })
})

describe('BattleSetupView — the course', () => {
  it('lists the courses of the catalog without the decks and the empty courses, the main one chosen', async () => {
    const r = await render()

    const rows = r.wrapper.findAll('[data-testid=course-section] [role=radio]')
    expect(rows.map((row) => row.text())).toEqual(['Английский A1A1', 'IELTS: академическая лексикаB2'])
    expect(get(r, 'course-english-a1').attributes('aria-checked')).toBe('true')
    expect(get(r, 'course-ielts-core').attributes('aria-checked')).toBe('false')
    expect(get(r, 'course-english-a1').find('svg').exists()).toBe(true) // the check mark: the choice is not a colour alone
    expect(has(r, 'course-my-work')).toBe(false)
    expect(has(r, 'course-empty-course')).toBe(false)
  })

  it('is a group of radio buttons with a name', async () => {
    const r = await render()

    const group = r.wrapper.get('[data-testid=course-section] [role=radiogroup]')
    expect(group.attributes('aria-label')).toBe('Курс')
  })

  it('takes the course of the address (the hub passes the one the learner was in)', async () => {
    const r = await render({ course: 'ielts-core' })

    expect(get(r, 'course-ielts-core').attributes('aria-checked')).toBe('true')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'ielts-core', null)
  })

  it('choosing another course reads its lessons and its formats', async () => {
    const r = await render()
    vi.mocked(api.battles.options).mockClear()

    await click(r, 'course-ielts-core')

    expect(get(r, 'course-ielts-core').attributes('aria-checked')).toBe('true')
    expect(get(r, 'course-english-a1').attributes('aria-checked')).toBe('false')
    expect(api.courses.get).toHaveBeenLastCalledWith('ielts-core')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'ielts-core', null)
    expect(tg.haptic).toHaveBeenCalledWith('light')
  })
})

describe('BattleSetupView — the lesson', () => {
  it('offers the whole course and the lessons: “Module N · Lesson M”, with the name of the lesson when there is one', async () => {
    const r = await render()

    expect(lessonOptions(r)).toEqual(['Весь курс', 'Модуль 1 · Урок 3', 'Модуль 1 · Урок 4 · Дом', 'Модуль 2 · Урок 4 · Еда'])
    expect((get(r, 'lesson-select').element as HTMLSelectElement).value).toBe('')
  })

  it('has a name for a screen reader, and is a select of the design system', async () => {
    const r = await render()

    expect(get(r, 'lesson-select').attributes('aria-label')).toBe('Урок')
    expect(get(r, 'lesson-select').classes()).toContain('field')
  })

  it('takes the lesson of the address', async () => {
    const r = await render({ course: 'english-a1', lesson: 41 })

    expect((get(r, 'lesson-select').element as HTMLSelectElement).value).toBe('41')
    expect(api.battles.options).toHaveBeenCalledWith(11, 'english-a1', 41)
  })

  it('choosing a lesson reads the formats for it; choosing the whole course again reads them for the course', async () => {
    const r = await render()
    vi.mocked(api.battles.options).mockClear()

    await get(r, 'lesson-select').setValue('32')
    await flushPromises()
    expect(api.battles.options).toHaveBeenLastCalledWith(11, 'english-a1', 32)

    await get(r, 'lesson-select').setValue('')
    await flushPromises()
    expect(api.battles.options).toHaveBeenLastCalledWith(11, 'english-a1', null)
  })

  it('the lessons that could not be read: told, with “Retry”; the course as a whole can still be played', async () => {
    vi.mocked(api.courses.get).mockRejectedValueOnce(apiError('network', 0, 'network'))
    const r = await render()

    expect(text(r, 'lessons-error')).toContain('Не удалось загрузить уроки курса.')
    expect(get(r, 'lesson-select').attributes('disabled')).toBeDefined()
    expect(get(r, 'invite-btn').attributes('disabled')).toBeUndefined()

    await click(r, 'lessons-retry')
    expect(has(r, 'lessons-error')).toBe(false)
    expect(lessonOptions(r)).toHaveLength(4)
  })
})

describe('BattleSetupView — the format', () => {
  it('shows the four formats: the name, an example, the length of a round; the first that can be played is chosen', async () => {
    const r = await render()

    const cards = r.wrapper.findAll('[data-testid=formats] [data-testid^=format-]').filter((card) => /^format-(pairs|collocation|sentence|dictation)$/.test(card.attributes('data-testid') ?? ''))
    expect(cards.map((card) => card.attributes('data-testid'))).toEqual(['format-pairs', 'format-collocation', 'format-sentence', 'format-dictation'])
    expect(get(r, 'format-pairs').attributes('aria-checked')).toBe('true')
    expect(get(r, 'format-pairs').text()).toContain('45 с на раунд')
    expect(get(r, 'format-collocation').text()).toContain('30 с на раунд')
    expect(get(r, 'format-dictation').text()).toContain('60 с на раунд')
    expect(get(r, 'formats').attributes('role')).toBe('radiogroup')
  })

  it('choosing a format moves the ring; “Invite” follows it', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))

    await click(r, 'format-dictation')
    expect(get(r, 'format-dictation').attributes('aria-checked')).toBe('true')
    expect(get(r, 'format-pairs').attributes('aria-checked')).toBe('false')

    await click(r, 'invite-btn')
    expect(vi.mocked(api.battles.invite).mock.calls[0]![0].mode).toBe('dictation')
  })

  it('a format that lacks material says why, in words, with the numbers; it cannot be chosen', async () => {
    serve({
      options: options({ modes: { pairs: { state: 'not_enough', available: 14, required: 20, reason: 'no_words' }, dictation: { state: 'disabled' } } }),
    })
    const r = await render()

    expect(text(r, 'format-reason')).toBe('В этой теме слов меньше, чем нужно для баттла: 14 из 20. Выберите другой урок или весь курс.')
    expect(get(r, 'format-pairs').find('[data-testid=format-chip]').text()).toBe('Мало материала')
    expect(get(r, 'format-dictation').find('[data-testid=format-chip]').text()).toBe('Выключено')
    expect(get(r, 'format-collocation').attributes('aria-checked')).toBe('true') // the first one that can be played
    await click(r, 'format-pairs')
    expect(get(r, 'format-collocation').attributes('aria-checked')).toBe('true')
  })

  it('no format can be played: the reasons are there and “Invite” is off', async () => {
    serve({
      options: options({
        modes: {
          pairs: { state: 'not_enough', reason: 'no_words' },
          collocation: { state: 'not_enough', reason: 'no_items' },
          sentence: { state: 'not_enough', reason: 'ambiguous' },
          dictation: { state: 'not_enough', reason: 'audio_pending' },
        },
      }),
    })
    const r = await render()

    expect(r.wrapper.findAll('[data-testid=format-reason]')).toHaveLength(4)
    expect(get(r, 'invite-btn').attributes('disabled')).toBeDefined()
  })

  it('is a skeleton while the formats are read, and again when the lesson changes', async () => {
    const formats = pending<BattleOptionsResponse>()
    vi.mocked(api.battles.options).mockReturnValue(formats.promise)
    const r = await render()

    expect(has(r, 'formats')).toBe(false)
    expect(r.wrapper.find('[data-testid=format-section] [data-testid=spinner]').exists()).toBe(true)
    expect(get(r, 'invite-btn').attributes('disabled')).toBeDefined()

    formats.resolve(options())
    await flushPromises()
    expect(has(r, 'formats')).toBe(true)
  })

  it('formats that could not be read: told, with “Retry”', async () => {
    vi.mocked(api.battles.options).mockRejectedValueOnce(apiError('network', 0, 'network'))
    const r = await render()

    expect(text(r, 'options-error')).toContain('Нет связи с сервером')
    expect(get(r, 'invite-btn').attributes('disabled')).toBeDefined()

    await click(r, 'options-retry')
    expect(has(r, 'options-error')).toBe(false)
    expect(has(r, 'formats')).toBe(true)
  })
})

describe('BattleSetupView — the friend', () => {
  it('a friend who cannot play yet is explained in words, and “Invite” stays off', async () => {
    serve({ options: options({ friend_access: 'no_access' }) })
    const r = await render()

    expect(text(r, 'friend-no-access')).toBe('Dilnoza пока не может играть: у друга нет доступа к практике. Позвать получится, когда он откроется.')
    expect(get(r, 'invite-btn').attributes('disabled')).toBeDefined()
  })

  it('a friend without a name is “Friend”', async () => {
    serve({ options: options({ friend: { first_name: null }, friend_access: 'no_access' }) })
    const r = await render()

    expect(r.wrapper.get('h1').text()).toBe('Новый баттл')
    expect(text(r, 'friend-name')).toBe('Друг')
  })
})

describe('BattleSetupView — “Invite”', () => {
  it('sends the invitation and opens the battle (in place of the setup: Back does not return to the form)', async () => {
    const r = await render({ course: 'english-a1', lesson: 41 })
    vi.mocked(api.battles.invite).mockResolvedValue(invitedBattle({ id: 21 }))
    const replace = vi.spyOn(r.router, 'replace')

    await click(r, 'invite-btn')

    expect(api.battles.invite).toHaveBeenCalledWith({ friendship_id: 11, mode: 'pairs', course: 'english-a1', lesson_id: 41 })
    expect(replace).toHaveBeenCalledWith({ name: 'battle', params: { id: 21 } })
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('21')
    expect(tg.haptic).toHaveBeenCalledWith('medium')
  })

  it('waits while the invitation is on its way: one press is one invitation', async () => {
    const reply = pending<ReturnType<typeof invitedBattle>>()
    vi.mocked(api.battles.invite).mockReturnValue(reply.promise)
    const r = await render()

    await get(r, 'invite-btn').trigger('click')
    await get(r, 'invite-btn').trigger('click')
    await flushPromises()
    expect(text(r, 'invite-btn')).toBe('Приглашаем…')
    expect(get(r, 'invite-btn').attributes('disabled')).toBeDefined()

    reply.resolve(invitedBattle({ id: 21 }))
    await flushPromises()
    expect(api.battles.invite).toHaveBeenCalledTimes(1)
  })

  it('tells the friend will be invited in Telegram and in the app', async () => {
    const r = await render()

    expect(text(r, 'invite-hint')).toBe('Друг получит приглашение в Telegram и в приложении.')
  })

  it('a battle of the learner’s own is in the way: told, with a way to it', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_busy', { battle_id: 9 }))

    await click(r, 'invite-btn')

    expect(text(r, 'invite-error')).toBe('У вас уже есть активный баттл. Завершите его, чтобы начать новый.')
    expect(text(r, 'open-busy')).toBe('Открыть текущий баттл')
    expect(route(r).name).toBe('battle-new')

    await click(r, 'open-busy')
    expect(route(r).name).toBe('battle')
    expect(route(r).params.id).toBe('9')
  })

  it('the material is gone: told, and the formats on screen are the new ones', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('validation', 422, 'battle_material_unavailable', { mode: 'pairs', available: 12, required: 20, reason: 'no_words' }))
    vi.mocked(api.battles.options).mockResolvedValue(options({ modes: { pairs: { state: 'not_enough', available: 12, required: 20, reason: 'no_words' } } }))

    await click(r, 'invite-btn')

    expect(text(r, 'invite-error')).toBe('Для баттла в этом формате не хватает материала. Выберите другой урок или формат.')
    expect(get(r, 'format-pairs').attributes('data-state')).toBe('not_enough')
    expect(get(r, 'format-collocation').attributes('aria-checked')).toBe('true')
  })

  it('a refusal is not hidden: it is in the flow of the screen, beside the button', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('conflict', 409, 'battle_friend_no_access'))

    await click(r, 'invite-btn')

    const html = r.wrapper.html()
    expect(html.indexOf('data-testid="invite-error"')).toBeLessThan(html.indexOf('data-testid="invite-btn"'))
    expect(get(r, 'invite-error').attributes('role')).toBe('alert')
  })

  it('the next press clears the old refusal', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockRejectedValueOnce(apiError('network', 0, 'network')).mockResolvedValueOnce(invitedBattle({ id: 21 }))

    await click(r, 'invite-btn')
    expect(has(r, 'invite-error')).toBe(true)
    await click(r, 'invite-btn')

    expect(has(r, 'invite-error')).toBe(false)
    expect(route(r).name).toBe('battle')
  })
})

describe('BattleSetupView — when the setup cannot go on', () => {
  it.each([
    ['friendship_not_found', 404, 'not_found', 'friend', 'Этого друга нет в списке'],
    ['not_friends', 403, 'forbidden', 'friend', 'Этого друга нет в списке'],
    ['battle_disabled', 409, 'conflict', 'off', 'Баттлы сейчас выключены'],
    ['practice_unavailable', 403, 'forbidden', 'unavailable', 'Практика пока недоступна'],
  ] as const)('%s: a screen of its own with the reason and a way to the friends', async (code, status, kind, reason, title) => {
    vi.mocked(api.battles.options).mockRejectedValue(apiError(kind, status, code))
    const r = await render()

    expect(get(r, 'blocked').attributes('data-reason')).toBe(reason)
    expect(r.wrapper.text()).toContain(title)
    expect(has(r, 'invite-btn')).toBe(false)

    await click(r, 'to-friends')
    expect(route(r).name).toBe('friends')
  })

  it('practice behind Pro: the paywall opens over the form', async () => {
    vi.mocked(api.battles.options).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    const r = await render()

    expect(get(r, 'paywall').attributes('data-reason')).toBe('practice')
    await click(r, 'paywall-close')
    expect(has(r, 'paywall')).toBe(false)
  })

  it('Pro is needed at the moment of the invitation: the paywall, not a sentence', async () => {
    const r = await render()
    vi.mocked(api.battles.invite).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))

    await click(r, 'invite-btn')

    expect(has(r, 'paywall')).toBe(true)
    expect(has(r, 'invite-error')).toBe(false)
  })

  it('a course that cannot have a battle is taken off the list, and the next one is shown', async () => {
    vi.mocked(api.battles.options).mockImplementation((_friendship, slug) =>
      slug === 'english-a1' ? Promise.reject(apiError('not_found', 404, 'course_not_found')) : Promise.resolve(options()),
    )
    const r = await render()

    expect(has(r, 'course-english-a1')).toBe(false)
    expect(get(r, 'course-ielts-core').attributes('aria-checked')).toBe('true')
    expect(has(r, 'formats')).toBe(true)
  })
})

describe('BattleSetupView — Uzbek', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(r.wrapper.get('h1').text()).toBe('Yangi bellashuv')
    expect(text(r, 'invite-btn')).toBe('Taklif qilish')
    expect(lessonOptions(r)[0]).toBe('Butun kurs')
    expect(get(r, 'format-pairs').text()).toContain('Har bir raundga 45 soniya')
  })
})
