import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeHubView from '../PracticeHubView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { useBillingStore } from '@/stores/billing'
import { usePracticeStore } from '@/stores/practice'
import { activeSession, freeLimits, hub, inHours, limitDetails, session } from '@/api/__tests__/practiceFixtures'
import type { BattleListResponse, FriendsResponse, PracticeHubResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    practice: { hub: vi.fn(), open: vi.fn() },
    battles: { list: vi.fn() },
    friends: { get: vi.fn() },
    challenges: { list: vi.fn() },
    training: { open: vi.fn() },
  },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))

const tg = vi.hoisted(() => ({ backHandler: null as (() => void) | null, haptic: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    showBackButton: (handler: () => void) => {
      tg.backHandler = handler
      return () => {
        tg.backHandler = null
      }
    },
    haptic: tg.haptic,
    selectionHaptic: () => {},
    notificationHaptic: () => {},
  }),
}))

vi.mock('@/composables/errorToast', () => ({ showErrorToast: vi.fn() }))

// The paywall is its own screen with its own tests: here only that it opens, with which reason, and how it ends.
vi.mock('@/components/PaywallSheet.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      props: { reason: { type: String, default: 'info' }, limitHit: { type: Object, default: null } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason, 'data-kind': props.limitHit?.kind ?? '', 'data-limit': String(props.limitHit?.limit ?? '') }, [
          h('button', { 'data-testid': 'paywall-close', onClick: () => emit('close') }),
          h('button', { 'data-testid': 'paywall-review', onClick: () => emit('review') }),
        ]),
    }),
  }
})

const offline = () => new ApiClientError('network', 0, 'network', 'fetch failed')
const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const friendsResponse = (): FriendsResponse => ({
  invite_link: null,
  limit: 50,
  friends: [
    { id: 31, first_name: 'Тимур', streak: 9, words_learned: 410, since: '2026-10-01' },
    { id: 32, first_name: 'Камила', streak: 0, words_learned: 95, since: '2026-10-15' },
  ],
  incoming: [],
  outgoing: [],
})

const incoming = (id: number, name: string): BattleListResponse['incoming'][number] => ({
  id,
  mode: 'pairs',
  from: { first_name: name },
  course: { slug: 'english-a1', title: 'Английский A1' },
  lesson: { id: 41, stage: 2, number: 4, title: 'Еда' },
  invite_expires_at: '2026-10-30T10:05:00.000Z',
  rematch: false,
})

/** `response`: the hub, a hub that never comes (a pending promise), or the error the server answers with. */
async function render(props: { slug?: string; lessonId?: number | null; from?: 'finished' | null } = {}, response: PracticeHubResponse | Promise<PracticeHubResponse> | Error = hub()) {
  if (response instanceof Error) vi.mocked(api.practice.hub).mockRejectedValue(response)
  else vi.mocked(api.practice.hub).mockReturnValue(Promise.resolve(response))
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/practice/:slug', name: 'practice-hub', component: stub },
      { path: '/practice/sessions/:id(\\d+)', name: 'practice-session', component: stub },
      { path: '/battles/new', name: 'battle-new', component: stub },
      { path: '/battles/:id(\\d+)', name: 'battle', component: stub },
      { path: '/friends', name: 'friends', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
  await router.push({ name: 'practice-hub', params: { slug: props.slug ?? 'english-a1' } })
  const wrapper = mount(PracticeHubView, {
    props: { slug: 'english-a1', lessonId: 41, ...props },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

const card = (wrapper: Awaited<ReturnType<typeof render>>['wrapper'], mode: string) => wrapper.get(`[data-testid=mode-card-${mode}]`)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  vi.mocked(api.battles.list).mockResolvedValue({ active: null, incoming: [], finished: [] })
  vi.mocked(api.friends.get).mockResolvedValue(friendsResponse())
  vi.mocked(api.challenges.list).mockResolvedValue({ days: 7, open: [], finished: [] })
  tg.backHandler = null
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('PracticeHubView — the states', () => {
  it('is a skeleton while the hub loads', async () => {
    const { wrapper } = await render({}, new Promise<PracticeHubResponse>(() => {}))

    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=mode-cards]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('asks for the course and the lesson of its address, after the login', async () => {
    const { wrapper } = await render({ slug: 'ielts-core', lessonId: 12 })

    expect(authEnsure).toHaveBeenCalled()
    expect(api.practice.hub).toHaveBeenCalledWith('ielts-core', 12)
    wrapper.unmount()
  })

  it('shows the hub: the heading is the name of the lesson, under it "Practice" and where the lesson is', async () => {
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe('Еда')
    expect(wrapper.text()).toContain('Практика · Модуль 2 · Урок 4')
    expect(wrapper.findAll('[data-testid^=mode-card-]').map((c) => c.attributes('data-testid'))).toEqual([
      'mode-card-pairs',
      'mode-card-collocation',
      'mode-card-sentence',
      'mode-card-dictation',
    ])
    wrapper.unmount()
  })

  it('a lesson without a name is headed by its place, and the course goes under it', async () => {
    const { wrapper } = await render({}, hub({ lesson: { id: 41, stage: 2, number: 4, title: null } }))

    expect(wrapper.get('h1').text()).toBe('Модуль 2 · Урок 4')
    expect(wrapper.text()).toContain('Практика · Английский A1')
    wrapper.unmount()
  })

  it('a lesson outside a module is headed "Lesson 4", never "Module null"', async () => {
    const { wrapper } = await render({}, hub({ lesson: { id: 41, stage: null, number: 4, title: null } }))

    expect(wrapper.get('h1').text()).toBe('Урок 4')
    expect(wrapper.text()).toContain('Практика · Английский A1')
    expect(wrapper.text()).not.toContain('null')
    wrapper.unmount()
  })

  it('a lesson the server knows no number of is headed by its name, and without one by its course', async () => {
    const named = await render({}, hub({ lesson: { id: 41, stage: null, number: null, title: 'Еда' } }))
    expect(named.wrapper.get('h1').text()).toBe('Еда')
    expect(named.wrapper.text()).toContain('Практика · Английский A1')
    expect(named.wrapper.text()).not.toContain('null')
    named.wrapper.unmount()

    const bare = await render({}, hub({ lesson: { id: 41, stage: null, number: null, title: null } }))
    expect(bare.wrapper.get('h1').text()).toBe('Английский A1')
    expect(bare.wrapper.text()).not.toContain('null')
    bare.wrapper.unmount()
  })

  it('the hub of a whole course is headed by the course', async () => {
    const { wrapper } = await render({ lessonId: null }, hub({ lesson: null }))

    expect(api.practice.hub).toHaveBeenCalledWith('english-a1', null)
    expect(wrapper.get('h1').text()).toBe('Английский A1')
    expect(wrapper.text()).toContain('Практика')
    wrapper.unmount()
  })

  it('shows each state of a card: playable, short of material, off, behind Pro', async () => {
    const { wrapper } = await render(
      {},
      hub({
        modes: {
          collocation: { state: 'not_enough', available: 1, reason: 'no_words' },
          sentence: { state: 'disabled' },
          dictation: { state: 'pro_required' },
        },
      }),
    )

    expect(card(wrapper, 'pairs').attributes('data-state')).toBe('available')
    expect(card(wrapper, 'collocation').attributes('data-state')).toBe('not_enough')
    expect(card(wrapper, 'collocation').text()).toContain('Изучено слов: 1 из 3')
    expect(card(wrapper, 'sentence').attributes('data-state')).toBe('disabled')
    expect(card(wrapper, 'dictation').attributes('data-state')).toBe('pro_required')
    wrapper.unmount()
  })

  it('has no primary button: pressing a card is the main action; the battle is a tinted button', async () => {
    const { wrapper } = await render()

    expect(wrapper.findAll('.btn-primary')).toHaveLength(0)
    expect(wrapper.get('[data-testid=battle-play-btn]').classes()).toContain('btn-tinted')
    wrapper.unmount()
  })

  it('says practice is not available yet (403 practice_unavailable), with a way back to the course', async () => {
    const { wrapper, router } = await render({}, apiError('forbidden', 403, 'practice_unavailable'))

    expect(wrapper.get('[data-testid=unavailable]').text()).toContain('Практика пока недоступна')
    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    await wrapper.get('[data-testid=to-course]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('course')
    wrapper.unmount()
  })

  it('is an error with "Retry" when the hub cannot be read; retrying reads it again', async () => {
    const { wrapper } = await render({}, offline())

    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
    vi.mocked(api.practice.hub).mockResolvedValue(hub())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mode-cards]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('a login that fails is an error too', async () => {
    authEnsure.mockRejectedValue(offline())
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    expect(api.practice.hub).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=battle-card]').text()).toContain("Do'st bilan o'ynash")
    expect(card(wrapper, 'pairs').text()).toContain('Juftlarni toping')
    wrapper.unmount()
  })
})

describe('PracticeHubView — starting a session', () => {
  it('opens the mode of the card with the course and the lesson, and goes into the session', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 78, mode: 'sentence' }))
    const { wrapper, router } = await render()

    await card(wrapper, 'sentence').trigger('click')
    await flushPromises()

    expect(api.practice.open).toHaveBeenCalledWith({ mode: 'sentence', course: 'english-a1', lesson_id: 41, source: 'hub', replace_active: false })
    expect(router.currentRoute.value.name).toBe('practice-session')
    expect(router.currentRoute.value.params.id).toBe('78')
    expect(tg.haptic).toHaveBeenCalledWith('light')
    wrapper.unmount()
  })

  it('says the session is "after training" when the hub was opened from the finish screen (?from=finished)', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 80, mode: 'pairs' }))
    const { wrapper } = await render({ from: 'finished' })

    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()

    expect(api.practice.open).toHaveBeenCalledWith({ mode: 'pairs', course: 'english-a1', lesson_id: 41, source: 'after_training', replace_active: false })
    wrapper.unmount()
  })

  it('opens the practice of a course without a lesson', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 79, lesson: null }))
    const { wrapper } = await render({ lessonId: null }, hub({ lesson: null }))

    await card(wrapper, 'pairs').trigger('click')

    expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ lesson_id: null })
    wrapper.unmount()
  })

  it('shows the wait on the card that was pressed, and a second press does not open a second session', async () => {
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.practice.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { wrapper } = await render()

    await card(wrapper, 'pairs').trigger('click')
    expect(card(wrapper, 'pairs').find('[data-testid=mode-busy]').exists()).toBe(true)
    await card(wrapper, 'pairs').trigger('click')
    await card(wrapper, 'dictation').trigger('click')
    expect(api.practice.open).toHaveBeenCalledTimes(1)

    finish(session({ id: 80 }))
    await flushPromises()
    wrapper.unmount()
  })

  it('the card pressed while another is being opened does not become the mode of "Start a new one"', async () => {
    let refuse!: (reason: unknown) => void
    vi.mocked(api.practice.open).mockReturnValueOnce(new Promise((_, reject) => (refuse = reject)))
    const { wrapper } = await render()
    await card(wrapper, 'sentence').trigger('click')
    await card(wrapper, 'dictation').trigger('click') // the first answer has not come yet: this press changes nothing
    expect(api.practice.open).toHaveBeenCalledTimes(1)

    refuse(apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } }))
    await flushPromises()
    vi.mocked(api.practice.open).mockResolvedValueOnce(session({ id: 83, mode: 'sentence' }))
    await wrapper.get('[data-testid=active-replace]').trigger('click')
    await flushPromises()

    expect(vi.mocked(api.practice.open).mock.calls.map((call) => call[0].mode)).toEqual(['sentence', 'sentence'])
    wrapper.unmount()
  })

  it('does not carry the learner away from the screen they have left', async () => {
    let finish!: (value: ReturnType<typeof session>) => void
    vi.mocked(api.practice.open).mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { wrapper, router } = await render()
    await card(wrapper, 'pairs').trigger('click')
    wrapper.unmount()
    await router.push({ name: 'friends' })

    finish(session({ id: 81 }))
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('friends')
  })

  it('tells a refusal in a toast and stays', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('validation', 422, 'practice_not_enough_material'))
    const { wrapper, router } = await render()

    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Для этого режима пока мало материала. Выучите ещё несколько слов.')
    expect(router.currentRoute.value.name).toBe('practice-hub')
    wrapper.unmount()
  })
})

describe('PracticeHubView — the unfinished session', () => {
  const withActive = (overrides = {}) => hub({ active_session: activeSession({ id: 77, mode: 'pairs', progress: { done: 1, total: 3 }, ...overrides }) })

  it('shows a "Continue" card on top, with the progress, that leads back into the session', async () => {
    const { wrapper, router } = await render({}, withActive())

    const link = wrapper.get('[data-testid=continue-card]')
    expect(link.text()).toContain('Продолжить')
    expect(link.text()).toContain('Найди пары')
    expect(link.text()).toContain('Выполнено 1 из 3')
    expect(link.attributes('href')).toBe('/practice/sessions/77')
    await link.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('practice-session')
    wrapper.unmount()
  })

  it('has none when there is no unfinished session', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid=continue-card]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('the card of its mode says "Continue" and goes straight back into the session, without asking the server to open one', async () => {
    const { wrapper, router } = await render({}, withActive())

    expect(card(wrapper, 'pairs').attributes('data-state')).toBe('resume')
    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()

    expect(api.practice.open).not.toHaveBeenCalled()
    expect(router.currentRoute.value.params.id).toBe('77')
    wrapper.unmount()
  })

  it('a session of another lesson is not "Continue" on this lesson’s card', async () => {
    const { wrapper } = await render({}, withActive({ lesson_id: 99 }))

    expect(card(wrapper, 'pairs').attributes('data-state')).toBe('available')
    expect(wrapper.find('[data-testid=continue-card]').exists()).toBe(true)
    wrapper.unmount()
  })

  // The server names the place of an unfinished session (`course_title`, `lesson`): the card and the sheet say where it is, whichever
  // course or lesson it is of. The very place of the hub is named short, by the hub (the rest of the place is on the screen); any other
  // place with its course first. Where the server has no names, the line is left out: no slug, no id and no guess is made up for a name.
  describe('where it is', () => {
    const elsewhere = (overrides = {}) =>
      activeSession({
        id: 77,
        mode: 'pairs',
        course: 'ielts-core',
        course_title: 'IELTS Core',
        lesson_id: 12,
        lesson: { stage: 1, number: 2, title: 'Travel' },
        progress: { done: 1, total: 3 },
        ...overrides,
      })

    it('the card names the place of the hub when the session is its own: the module, the lesson and its name', async () => {
      const { wrapper } = await render({}, withActive())

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('Модуль 2 · Урок 4 · Еда')
      expect(wrapper.get('[data-testid=continue-card]').attributes('aria-label')).toBe('Продолжить: Найди пары, выполнено 1 из 3. Модуль 2 · Урок 4 · Еда')
      wrapper.unmount()
    })

    it('the card names the course when the session is of the whole course of the hub', async () => {
      const { wrapper } = await render({}, withActive({ lesson_id: null, lesson: null }))

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('Английский A1')
      wrapper.unmount()
    })

    it('the card names a session of another course by what the server says: the course, the module, the lesson and its name', async () => {
      const { wrapper } = await render({}, hub({ active_session: elsewhere() }))

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('IELTS Core · Модуль 1 · Урок 2 · Travel')
      expect(wrapper.get('[data-testid=continue-card]').attributes('aria-label')).toBe('Продолжить: Найди пары, выполнено 1 из 3. IELTS Core · Модуль 1 · Урок 2 · Travel')
      wrapper.unmount()
    })

    it('the card names a session of another lesson of the same course with the course too', async () => {
      const { wrapper } = await render({}, withActive({ lesson_id: 99, lesson: { stage: 3, number: 1, title: 'Семья' } }))

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('Английский A1 · Модуль 3 · Урок 1 · Семья')
      expect(card(wrapper, 'pairs').attributes('data-state')).toBe('available') // not "Continue" on this lesson’s card
      wrapper.unmount()
    })

    it('the card names the course alone for a whole-course session of another course', async () => {
      const { wrapper } = await render({}, hub({ active_session: elsewhere({ lesson_id: null, lesson: null }) }))

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('IELTS Core')
      wrapper.unmount()
    })

    it('says nothing of a session that the server has no names for, and shows no slug and no id', async () => {
      const { wrapper } = await render({}, hub({ active_session: elsewhere({ course_title: '', lesson: null }) }))

      expect(wrapper.find('[data-testid=continue-place]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=continue-card]').text()).not.toMatch(/ielts-core|12/)
      expect(wrapper.get('[data-testid=continue-card]').attributes('aria-label')).toBe('Продолжить: Найди пары, выполнено 1 из 3')
      wrapper.unmount()
    })

    it('does not take the session on screen for the unfinished one: the names are the server’s', async () => {
      usePracticeStore().session = session({ id: 5, course: { slug: 'ielts-core', title: 'IELTS Core' }, lesson: { id: 12, stage: 1, number: 2, title: 'Travel' } })
      const { wrapper } = await render({}, hub({ active_session: elsewhere({ course_title: '', lesson: null }) })) // the unfinished one is 77, not 5

      expect(wrapper.find('[data-testid=continue-place]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('the sheet of the unfinished practice names it too: the place of the hub short, another place from the details of the 409', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(
        apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } }),
      )
      const { wrapper } = await render()

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=active-place]').text()).toBe('Модуль 2 · Урок 4 · Еда')
      expect(wrapper.get('[data-testid=active-text]').text()).toContain('Найди пары: выполнено 1 из 3')
      wrapper.unmount()
    })

    it('the sheet names a session of another course by the names in the details of the 409: the course, the module, the lesson', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(
        apiError('conflict', 409, 'practice_session_active', {
          session_id: 90,
          mode: 'dictation',
          course: 'ielts-core',
          course_title: 'IELTS Core',
          lesson_id: 12,
          lesson: { stage: 1, number: 2, title: 'Travel' },
          progress: { done: 2, total: 5 },
        }),
      )
      const { wrapper } = await render()

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=active-place]').text()).toBe('IELTS Core · Модуль 1 · Урок 2 · Travel')
      expect(wrapper.get('[data-testid=active-session-sheet]').text()).toContain('Слушай и пиши: выполнено 2 из 5')
      wrapper.unmount()
    })

    it('and the sheet says nothing where the 409 has no names', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(
        apiError('conflict', 409, 'practice_session_active', { session_id: 90, mode: 'dictation', course: 'ielts-core', lesson_id: 12, progress: { done: 2, total: 5 } }),
      )
      const { wrapper } = await render()

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=active-place]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=active-session-sheet]').text()).toContain('Слушай и пиши: выполнено 2 из 5')
      wrapper.unmount()
    })

    it('speaks Uzbek', async () => {
      setLocale('uz')
      const { wrapper } = await render({}, withActive())

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('2-modul · 4-dars · Еда')
      wrapper.unmount()
    })

    it('speaks Uzbek for another place too, with the course first', async () => {
      setLocale('uz')
      const { wrapper } = await render({}, hub({ active_session: elsewhere() }))

      expect(wrapper.get('[data-testid=continue-place]').text()).toBe('IELTS Core · 1-modul · 2-dars · Travel')
      wrapper.unmount()
    })
  })

  // "No sound for now" on a dictation walks the learner out of it on purpose, to choose another mode. The hub does not ask "Continue or
  // start a new one" about the dictation they have just left: the mode they choose takes its place at once.
  describe('a dictation dropped on purpose ("No sound for now")', () => {
    const dropped = () => hub({ active_session: activeSession({ id: 77, mode: 'dictation', progress: { done: 2, total: 5 } }) })

    it('another mode starts over it at once: replace_active, and no sheet', async () => {
      vi.mocked(api.practice.open).mockResolvedValue(session({ id: 82, mode: 'sentence' }))
      usePracticeStore().abandoned = 77 // what the session screen does
      const { wrapper, router } = await render({}, dropped())

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(api.practice.open).toHaveBeenCalledTimes(1)
      expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toEqual({ mode: 'sentence', course: 'english-a1', lesson_id: 41, source: 'hub', replace_active: true })
      expect(wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(false)
      expect(router.currentRoute.value.params.id).toBe('82')
      wrapper.unmount()
    })

    it('is for one transition: once the new session is open the flag is gone', async () => {
      vi.mocked(api.practice.open).mockResolvedValue(session({ id: 82, mode: 'sentence' }))
      const store = usePracticeStore()
      store.abandoned = 77
      const { wrapper } = await render({}, dropped())

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(store.abandoned).toBeNull()
      wrapper.unmount()
    })

    it('is for this visit only: leaving the hub forgets it, and the next hub asks as always', async () => {
      const store = usePracticeStore()
      store.abandoned = 77
      const first = await render({}, dropped())
      first.wrapper.unmount()
      expect(store.abandoned).toBeNull()

      vi.mocked(api.practice.open).mockRejectedValue(
        apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'dictation', course: 'english-a1', lesson_id: 41, progress: { done: 2, total: 5 } }),
      )
      const next = await render({}, dropped())
      await card(next.wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ replace_active: false })
      expect(next.wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(true)
      next.wrapper.unmount()
    })

    it('the dropped dictation itself is resumed by its own card, as always - dropping it was about choosing another mode', async () => {
      usePracticeStore().abandoned = 77
      const { wrapper, router } = await render({}, dropped())

      await card(wrapper, 'dictation').trigger('click')
      await flushPromises()

      expect(api.practice.open).not.toHaveBeenCalled()
      expect(router.currentRoute.value.params.id).toBe('77')
      wrapper.unmount()
    })

    it('a mode that could not be opened (no material) leaves it for the next try: the next mode still starts over the dictation', async () => {
      vi.mocked(api.practice.open)
        .mockRejectedValueOnce(apiError('validation', 422, 'practice_not_enough_material'))
        .mockResolvedValueOnce(session({ id: 83, mode: 'collocation' }))
      usePracticeStore().abandoned = 77
      const { wrapper } = await render({}, dropped())

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()
      await card(wrapper, 'collocation').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.practice.open).mock.calls.map((call) => call[0].replace_active)).toEqual([true, true])
      expect(wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('does not touch an unfinished session that is not the dropped one: that one is asked about as always', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(
        apiError('conflict', 409, 'practice_session_active', { session_id: 90, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } }),
      )
      usePracticeStore().abandoned = 77 // the dictation 77 was dropped, but the unfinished session now is 90
      const { wrapper } = await render({}, hub({ active_session: activeSession({ id: 90, mode: 'pairs' }) }))

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.practice.open).mock.calls[0]![0]).toMatchObject({ replace_active: false })
      expect(wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(true)
      wrapper.unmount()
    })
  })

  // A refusal that comes after the hub was left must not be written down: the next hub would open with the sheet (or the paywall) by
  // itself, and "Start a new one" would do nothing, for no card was pressed there.
  describe('a refusal that comes after the hub was left', () => {
    it('is not kept: the next hub does not open with the sheet of the unfinished practice', async () => {
      let refuse!: (reason: unknown) => void
      vi.mocked(api.practice.open).mockReturnValueOnce(new Promise((_, reject) => (refuse = reject)))
      const first = await render()
      await card(first.wrapper, 'sentence').trigger('click')
      first.wrapper.unmount() // the learner leaves the hub

      refuse(apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } }))
      await flushPromises()
      const next = await render()

      expect(usePracticeStore().conflict).toBeNull()
      expect(next.wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(false)
      next.wrapper.unmount()
    })

    it('nor the paywall', async () => {
      let refuse!: (reason: unknown) => void
      vi.mocked(api.practice.open).mockReturnValueOnce(new Promise((_, reject) => (refuse = reject)))
      const first = await render()
      await card(first.wrapper, 'sentence').trigger('click')
      first.wrapper.unmount()

      refuse(apiError('forbidden', 403, 'practice_pro_required'))
      await flushPromises()
      const next = await render()

      expect(next.wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      next.wrapper.unmount()
    })

    it('nor the toast of the reason', async () => {
      let refuse!: (reason: unknown) => void
      vi.mocked(api.practice.open).mockReturnValueOnce(new Promise((_, reject) => (refuse = reject)))
      const first = await render()
      await card(first.wrapper, 'sentence').trigger('click')
      first.wrapper.unmount()

      refuse(apiError('validation', 422, 'practice_not_enough_material'))
      await flushPromises()
      const next = await render()

      expect(showErrorToast).not.toHaveBeenCalled()
      next.wrapper.unmount()
    })

    it('is still told while the learner is on the hub', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(apiError('validation', 422, 'practice_not_enough_material'))
      const { wrapper } = await render()

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      expect(showErrorToast).toHaveBeenCalledWith('Для этого режима пока мало материала. Выучите ещё несколько слов.')
      wrapper.unmount()
    })

    it('leaves no card "busy" on the next hub when the learner left while a session was being opened', async () => {
      vi.mocked(api.practice.open).mockReturnValueOnce(new Promise(() => {}))
      const first = await render()
      await card(first.wrapper, 'sentence').trigger('click')
      expect(usePracticeStore().opening).toBe('sentence')
      first.wrapper.unmount()

      const next = await render()

      expect(usePracticeStore().opening).toBeNull()
      expect(next.wrapper.find('[data-testid=mode-busy]').exists()).toBe(false)
      next.wrapper.unmount()
    })
  })

  describe('a new session while one is unfinished (409)', () => {
    const conflict = () =>
      apiError('conflict', 409, 'practice_session_active', { session_id: 77, mode: 'pairs', course: 'english-a1', lesson_id: 41, progress: { done: 1, total: 3 } })

    it('asks what to do: continue the old one, or start the new one', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(conflict())
      const { wrapper } = await render()

      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      const sheet = wrapper.get('[data-testid=active-session-sheet]')
      expect(sheet.text()).toContain('Незавершённая практика')
      expect(sheet.text()).toContain('Найди пары: выполнено 1 из 3')
      expect(showErrorToast).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('"Continue" goes into the old session', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(conflict())
      const { wrapper, router } = await render()
      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      await wrapper.get('[data-testid=active-continue]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.params.id).toBe('77')
      expect(wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(false)
      expect(api.practice.open).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('"Start a new one" opens the mode that was pressed, closing the old one (replace_active)', async () => {
      vi.mocked(api.practice.open).mockRejectedValueOnce(conflict()).mockResolvedValueOnce(session({ id: 82, mode: 'sentence' }))
      const { wrapper, router } = await render()
      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      await wrapper.get('[data-testid=active-replace]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.practice.open).mock.calls[1]![0]).toEqual({ mode: 'sentence', course: 'english-a1', lesson_id: 41, source: 'hub', replace_active: true })
      expect(router.currentRoute.value.params.id).toBe('82')
      wrapper.unmount()
    })

    it('"Start a new one" keeps "after training" in a hub that was opened from the finish screen', async () => {
      vi.mocked(api.practice.open).mockRejectedValueOnce(conflict()).mockResolvedValueOnce(session({ id: 83, mode: 'sentence' }))
      const { wrapper } = await render({ from: 'finished' })
      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      await wrapper.get('[data-testid=active-replace]').trigger('click')
      await flushPromises()

      expect(vi.mocked(api.practice.open).mock.calls.map((call) => call[0].source)).toEqual(['after_training', 'after_training'])
      wrapper.unmount()
    })

    it('"Cancel" does nothing at all', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(conflict())
      const { wrapper, router } = await render()
      await card(wrapper, 'sentence').trigger('click')
      await flushPromises()

      await wrapper.get('[data-testid=active-cancel]').trigger('click')

      expect(wrapper.find('[data-testid=active-session-sheet]').exists()).toBe(false)
      expect(api.practice.open).toHaveBeenCalledTimes(1)
      expect(router.currentRoute.value.name).toBe('practice-hub')
      wrapper.unmount()
    })
  })
})

describe('PracticeHubView — Pro', () => {
  it('a card behind Pro opens the paywall for practice, without asking the server', async () => {
    const { wrapper } = await render({}, hub({ modes: { dictation: { state: 'pro_required' } } }))

    await card(wrapper, 'dictation').trigger('click')

    expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
    expect(api.practice.open).not.toHaveBeenCalled()

    await wrapper.get('[data-testid=paywall-close]').trigger('click')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('a 403 practice_pro_required of the server opens it as well', async () => {
    vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_pro_required'))
    const { wrapper } = await render()

    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
    expect(showErrorToast).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  describe('when the plan changes under the hub', () => {
    const plan = (value: 'free' | 'pro') => ({
      entitlement: { plan: value, source: value === 'pro' ? 'payment' : 'free', until: null, new_words_per_day: value === 'pro' ? null : 20 },
      paywall: { mode: 'purchase' as const, contact: null },
    })

    it('asks the hub again after Pro is paid for, and the Pro chip is gone', async () => {
      const billing = useBillingStore()
      billing.apply(plan('free'))
      const { wrapper } = await render({}, hub({ modes: { dictation: { state: 'pro_required' } } }))
      expect(card(wrapper, 'dictation').text()).toContain('Pro')

      vi.mocked(api.practice.hub).mockResolvedValue(hub())
      billing.apply(plan('pro'))
      await flushPromises()

      expect(api.practice.hub).toHaveBeenCalledTimes(2)
      expect(card(wrapper, 'dictation').text()).not.toContain('Pro')
      wrapper.unmount()
    })

    it('does not ask again when the plan only becomes known', async () => {
      const { wrapper } = await render()

      useBillingStore().apply(plan('pro'))
      await flushPromises()

      expect(api.practice.hub).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('does not ask again for a /me that changes nothing', async () => {
      const billing = useBillingStore()
      billing.apply(plan('free'))
      const { wrapper } = await render()

      billing.apply(plan('free'))
      await flushPromises()

      expect(api.practice.hub).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })
  })
})

describe('PracticeHubView — short of material: no tasks, and tasks that wait for the words', () => {
  // The server tells `no_items` (there are no tasks for the topic: nothing to learn will change it) from `items_locked` (the tasks are
  // there and open when the words of the lesson are met). The hub does not promise what will not come, and offers learning where it helps.
  it('says in a lesson that there are no tasks, with no promise and no "Learn the lesson"', async () => {
    const { wrapper } = await render({}, hub({ modes: { collocation: { state: 'not_enough', available: 0, reason: 'no_items' } } }))

    const collocation = card(wrapper, 'collocation')
    expect(collocation.get('[data-testid=mode-reason]').text()).toBe('Для этой темы заданий пока нет.')
    expect(collocation.find('[data-testid=mode-learn-collocation]').exists()).toBe(false)
    expect(collocation.text()).not.toContain('Учить урок')
    wrapper.unmount()
  })

  it('says in a lesson that the tasks open with its words, and offers "Learn the lesson" - which starts the lesson', async () => {
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 502, mode: 'lesson', lesson: { stage: 2, lesson: 4, title: 'Еда' }, total_words: 20 })
    const { wrapper, router } = await render({}, hub({ modes: { sentence: { state: 'not_enough', available: 0, reason: 'items_locked' } } }))

    const sentence = card(wrapper, 'sentence')
    expect(sentence.get('[data-testid=mode-reason]').text()).toBe('Выучите слова урока — задания откроются.')
    expect(sentence.get('[data-testid=mode-learn-sentence]').text()).toBe('Учить урок')
    await sentence.get('[data-testid=mode-learn-sentence]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'picker' }) // the same action as for `no_words`
    expect(router.currentRoute.value.name).toBe('training')
    wrapper.unmount()
  })

  it('in a course: no tasks says so with no button; tasks that wait for words offer "To the lessons"', async () => {
    const { wrapper, router } = await render(
      { lessonId: null },
      hub({ lesson: null, modes: { collocation: { state: 'not_enough', reason: 'no_items' }, sentence: { state: 'not_enough', reason: 'items_locked' } } }),
    )

    expect(card(wrapper, 'collocation').get('[data-testid=mode-reason]').text()).toBe('Для этой темы заданий пока нет.')
    expect(wrapper.find('[data-testid=mode-learn-collocation]').exists()).toBe(false)
    expect(card(wrapper, 'sentence').get('[data-testid=mode-reason]').text()).toBe('Выучите слова уроков — задания откроются.') // the lessons, next to "To the lessons"
    expect(wrapper.get('[data-testid=mode-learn-sentence]').text()).toBe('К урокам')
    await wrapper.get('[data-testid=mode-learn-sentence]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('course')
    wrapper.unmount()
  })

  it('a topic with a task or two says "few" with the numbers, not "none" - in a lesson and in a course', async () => {
    const lesson = await render({}, hub({ modes: { sentence: { state: 'not_enough', available: 2, required: 3, reason: 'no_items' } } }))
    expect(card(lesson.wrapper, 'sentence').get('[data-testid=mode-reason]').text()).toBe('Заданий пока мало: 2 из 3.')
    expect(lesson.wrapper.find('[data-testid=mode-learn-sentence]').exists()).toBe(false)
    lesson.wrapper.unmount()

    const course = await render({ lessonId: null }, hub({ lesson: null, modes: { collocation: { state: 'not_enough', available: 1, required: 3, reason: 'no_items' } } }))
    expect(card(course.wrapper, 'collocation').get('[data-testid=mode-reason]').text()).toBe('Заданий пока мало: 1 из 3.')
    course.wrapper.unmount()
  })

  it('both cards side by side, in Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(
      {},
      hub({ modes: { collocation: { state: 'not_enough', reason: 'no_items' }, sentence: { state: 'not_enough', reason: 'items_locked' } } }),
    )

    expect(card(wrapper, 'collocation').text()).toContain("Bu mavzu bo'yicha topshiriqlar hozircha yo'q.")
    expect(card(wrapper, 'sentence').text()).toContain("Dars so'zlarini o'rganing — topshiriqlar ochiladi.")
    expect(wrapper.find('[data-testid=mode-learn-collocation]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mode-learn-sentence]').exists()).toBe(true)
    wrapper.unmount()
  })
})

describe('PracticeHubView — short of material', () => {
  it('"Learn the lesson" starts the lesson itself', async () => {
    vi.mocked(api.training.open).mockResolvedValue({ session_id: 501, mode: 'lesson', lesson: { stage: 2, lesson: 4, title: 'Еда' }, total_words: 20 })
    const { wrapper, router } = await render({}, hub({ modes: { pairs: { state: 'not_enough', available: 2, reason: 'no_words' } } }))

    await wrapper.get('[data-testid=mode-learn-pairs]').trigger('click')
    await flushPromises()

    expect(api.training.open).toHaveBeenCalledWith({ mode: 'lesson', lesson_id: 41, source: 'picker' })
    expect(router.currentRoute.value.name).toBe('training')
    expect(router.currentRoute.value.params.sessionId).toBe('501')
    wrapper.unmount()
  })

  it('a lesson that cannot be started is a toast', async () => {
    vi.mocked(api.training.open).mockRejectedValue(apiError('not_found', 404, 'lesson_not_found'))
    const { wrapper, router } = await render({}, hub({ modes: { pairs: { state: 'not_enough', reason: 'no_words' } } }))

    await wrapper.get('[data-testid=mode-learn-pairs]').trigger('click')
    await flushPromises()

    expect(showErrorToast).toHaveBeenCalledWith('Урок не найден.')
    expect(router.currentRoute.value.name).toBe('practice-hub')
    wrapper.unmount()
  })

  it('in a course there is no lesson to learn: "To the lessons" opens the course', async () => {
    const { wrapper, router } = await render({ lessonId: null }, hub({ lesson: null, modes: { pairs: { state: 'not_enough', reason: 'no_words' } } }))

    expect(wrapper.get('[data-testid=mode-learn-pairs]').text()).toBe('К урокам')
    await wrapper.get('[data-testid=mode-learn-pairs]').trigger('click')
    await flushPromises()

    expect(api.training.open).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('course')
    expect(router.currentRoute.value.params.slug).toBe('english-a1')
    wrapper.unmount()
  })
})

describe('PracticeHubView — the battle with a friend', () => {
  it('has a card with "Play with a friend"', async () => {
    const { wrapper } = await render()

    const battle = wrapper.get('[data-testid=battle-card]')
    expect(battle.text()).toContain('Баттл 1 на 1')
    expect(wrapper.get('[data-testid=battle-play-btn]').text()).toBe('Играть с другом')
    wrapper.unmount()
  })

  it('has none when battles are off', async () => {
    const { wrapper } = await render({}, hub({ battle: { enabled: false, active_battle_id: null, incoming_count: 0 } }))

    expect(wrapper.find('[data-testid=battle-card]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('opens the friends of the learner; a tap on a friend leads to the setup of the battle with what the hub knows', async () => {
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=friend-picker]').exists()).toBe(true)
    await wrapper.get('[data-testid=friend-32]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('battle-new')
    expect(router.currentRoute.value.query).toEqual({ friendship: '32', course: 'english-a1', lesson: '41' })
    wrapper.unmount()
  })

  it('from a course, the setup has no lesson', async () => {
    const { wrapper, router } = await render({ lessonId: null }, hub({ lesson: null }))

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid=friend-31]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ friendship: '31', course: 'english-a1' })
    wrapper.unmount()
  })

  it('with no friends it says so and leads to the Friends screen', async () => {
    vi.mocked(api.friends.get).mockResolvedValue({ ...friendsResponse(), friends: [] })
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid=friends-invite]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('friends')
    wrapper.unmount()
  })

  it('the picker can be closed without choosing', async () => {
    const { wrapper } = await render()
    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid=sheet-x]').trigger('click')

    expect(wrapper.find('[data-testid=friend-picker]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('when a battle is on, the button leads back into it', async () => {
    const { wrapper, router } = await render({}, hub({ battle: { enabled: true, active_battle_id: 15, incoming_count: 0 } }))

    expect(wrapper.get('[data-testid=battle-play-btn]').text()).toBe('Вернуться в баттл')
    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('battle')
    expect(router.currentRoute.value.params.id).toBe('15')
    expect(wrapper.find('[data-testid=friend-picker]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the invitations to me, each leading to its battle', async () => {
    vi.mocked(api.battles.list).mockResolvedValue({ active: null, incoming: [incoming(15, 'Тимур'), incoming(16, 'Камила')], finished: [] })
    const { wrapper, router } = await render({}, hub({ battle: { enabled: true, active_battle_id: null, incoming_count: 2 } }))

    const group = wrapper.get('[data-testid=battle-invites]')
    expect(group.text()).toContain('Приглашения на баттл')
    expect(wrapper.get('[data-testid=invitation-15]').text()).toContain('Тимур зовёт вас на баттл')
    expect(wrapper.get('[data-testid=invitation-15]').text()).toContain('Найди пары · Модуль 2 · Урок 4 · Еда')
    await wrapper.get('[data-testid=invitation-16]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('battle')
    expect(router.currentRoute.value.params.id).toBe('16')
    wrapper.unmount()
  })

  it('asks for the list only when the hub says there are invitations (a battle that is on needs no list)', async () => {
    const quiet = await render()
    expect(api.battles.list).not.toHaveBeenCalled()
    quiet.wrapper.unmount()

    const playing = await render({}, hub({ battle: { enabled: true, active_battle_id: 15, incoming_count: 0 } }))
    expect(api.battles.list).not.toHaveBeenCalled()
    playing.wrapper.unmount()

    const busy = await render({}, hub({ battle: { enabled: true, active_battle_id: null, incoming_count: 1 } }))
    expect(api.battles.list).toHaveBeenCalledTimes(1)
    busy.wrapper.unmount()
  })

  it('a list that cannot be read costs the hub nothing', async () => {
    vi.mocked(api.battles.list).mockRejectedValue(offline())
    const { wrapper } = await render({}, hub({ battle: { enabled: true, active_battle_id: null, incoming_count: 1 } }))

    expect(wrapper.find('[data-testid=battle-invites]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mode-cards]').exists()).toBe(true)
    expect(showErrorToast).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('does not show invitations while battles are off', async () => {
    vi.mocked(api.battles.list).mockResolvedValue({ active: null, incoming: [incoming(15, 'Тимур')], finished: [] })
    const { wrapper } = await render({}, hub({ battle: { enabled: false, active_battle_id: null, incoming_count: 1 } }))

    expect(wrapper.find('[data-testid=battle-invites]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('PracticeHubView — Back', () => {
  it('the system "Back" leads to the course', async () => {
    const { wrapper, router } = await render()
    expect(tg.backHandler).not.toBeNull()

    tg.backHandler?.()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('course')
    expect(router.currentRoute.value.params.slug).toBe('english-a1')
    wrapper.unmount()
  })
})

// docs/18 §3 (G1): Free has two sessions of practice and one battle a day. The hub says how many sessions are left, in a quiet line; when
// none is left a card still looks and presses like a card, and the press opens the paywall of the limit at once, without a request.
describe('PracticeHubView — the Free limit of sessions', () => {
  const line = (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) => wrapper.find('[data-testid=practice-limit]')
  const resetsAt = () => inHours(4)

  it('says how many sessions of the day are left, quietly, under "Choose a mode" and above the cards', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(1, 0) }))

    expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 1 из 2')
    const html = wrapper.html()
    expect(html.indexOf('Выберите режим')).toBeLessThan(html.indexOf('Сегодня бесплатно'))
    expect(html.indexOf('Сегодня бесплатно')).toBeLessThan(html.indexOf('data-testid="mode-cards"'))
    wrapper.unmount()
  })

  it('is plain secondary text: no button, no chip, no alert, no colour of its own', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(0, 0) }))

    const quiet = line(wrapper)
    expect(quiet.element.tagName).toBe('P')
    expect(quiet.classes()).toEqual(expect.arrayContaining(['text-tg-hint', 'text-[13px]']))
    expect(quiet.find('.btn, .chip, [role=alert], svg').exists()).toBe(false)
    wrapper.unmount()
  })

  it('wraps on a narrow screen instead of overflowing: no nowrap and no truncation', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(1, 0) }))

    expect(line(wrapper).classes()).not.toContain('truncate')
    expect(line(wrapper).classes()).not.toContain('whitespace-nowrap')
    wrapper.unmount()
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render({}, hub({ limits: freeLimits(1, 0) }))

    expect(line(wrapper).text()).toBe('Bugun bepul: 2 tadan 1 tasi qoldi')
    wrapper.unmount()
  })

  it('Pro sees nothing: no line, and the cards are as they were', async () => {
    const { wrapper } = await render({}, hub({ limits: null }))

    expect(line(wrapper).exists()).toBe(false)
    expect(wrapper.find('[data-testid=mode-cards]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('a server that does not send the limits (an older one) shows nothing, and stops nothing', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 90 }))
    const { wrapper } = await render({}, hub())

    expect(line(wrapper).exists()).toBe(false)
    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()
    expect(api.practice.open).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('a counter whose day is over says nothing: the app stayed open overnight, and the server decides', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 90 }))
    const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, new Date(Date.now() - 60_000).toISOString()) }))

    expect(line(wrapper).exists()).toBe(false)
    await card(wrapper, 'pairs').trigger('click')
    await flushPromises()
    expect(api.practice.open).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('opens a session as usual while some are left', async () => {
    vi.mocked(api.practice.open).mockResolvedValue(session({ id: 91, mode: 'sentence' }))
    const { wrapper, router } = await render({}, hub({ limits: freeLimits(1, 0) }))

    await card(wrapper, 'sentence').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('practice-session')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    wrapper.unmount()
  })

  describe('when none is left', () => {
    it('the line says so in the same words, and the cards are still there, as cards', async () => {
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))

      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 0 из 2')
      for (const mode of ['pairs', 'collocation', 'sentence', 'dictation']) {
        expect(card(wrapper, mode).element.tagName).toBe('BUTTON')
        expect((card(wrapper, mode).element as HTMLButtonElement).disabled).toBe(false)
      }
      wrapper.unmount()
    })

    it('a press opens the paywall of the limit at once - no request, no toast - with the numbers the sheet quotes', async () => {
      const at = resetsAt()
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, at) }))

      await card(wrapper, 'sentence').trigger('click')

      const sheet = wrapper.get('[data-testid=paywall]')
      expect(sheet.attributes('data-reason')).toBe('practice_limit')
      expect(sheet.attributes('data-kind')).toBe('sessions')
      expect(sheet.attributes('data-limit')).toBe('2')
      expect(api.practice.open).not.toHaveBeenCalled()
      expect(showErrorToast).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=mode-busy]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('every mode does the same, and the sheet closes and opens again', async () => {
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))

      for (const mode of ['pairs', 'collocation', 'sentence', 'dictation']) {
        await card(wrapper, mode).trigger('click')
        expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice_limit')
        await wrapper.get('[data-testid=paywall-close]').trigger('click')
        expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      }
      expect(api.practice.open).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('"Repeat words" of the sheet leaves for the review, as on every paywall', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 503, mode: 'daily_review', lesson: null, total_words: 12 })
      const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))
      await card(wrapper, 'pairs').trigger('click')

      await wrapper.get('[data-testid=paywall-review]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      expect(router.currentRoute.value.name).toBe('training')
      wrapper.unmount()
    })

    it('the session that is unfinished is still the learner\'s: its card says "Continue" and goes back into it - it was counted when it began', async () => {
      const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()), active_session: activeSession({ id: 77, mode: 'pairs' }) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(api.practice.open).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      expect(router.currentRoute.value.name).toBe('practice-session')
      expect(router.currentRoute.value.params.id).toBe('77')
      wrapper.unmount()
    })

    it('and so is the "Continue" card on top', async () => {
      const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()), active_session: activeSession({ id: 77, mode: 'pairs' }) }))

      await wrapper.get('[data-testid=continue-card]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.params.id).toBe('77')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('another mode than the unfinished one is a new session, and meets the limit - not the question about the old one', async () => {
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()), active_session: activeSession({ id: 77, mode: 'pairs' }) }))

      await card(wrapper, 'dictation').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice_limit')
      expect(wrapper.find('[data-testid=active-sheet]').exists()).toBe(false)
      expect(api.practice.open).not.toHaveBeenCalled()
      wrapper.unmount()
    })

    it('a card behind Pro is still the paywall of Pro, not of the limit', async () => {
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()), modes: { dictation: { state: 'pro_required' } } }))

      await card(wrapper, 'dictation').trigger('click')

      expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice')
      expect(wrapper.get('[data-testid=paywall]').attributes('data-kind')).toBe('')
      wrapper.unmount()
    })

    it('a mode that lacks material is still told so: its card is not a button, the limit has nothing to say about it', async () => {
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()), modes: { dictation: { state: 'not_enough', reason: 'no_words', available: 1, required: 3 } } }))

      expect(card(wrapper, 'dictation').element.tagName).not.toBe('BUTTON')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('leaving the hub forgets the sheet: the next hub does not open with it', async () => {
      const first = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))
      await card(first.wrapper, 'pairs').trigger('click')
      expect(first.wrapper.find('[data-testid=paywall]').exists()).toBe(true)
      first.wrapper.unmount()

      const next = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))

      expect(next.wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      next.wrapper.unmount()
    })
  })

  describe('the answer of the server to a session that is opened', () => {
    it('the 201 brings the counters as they are after it, and the line follows', async () => {
      vi.mocked(api.practice.open).mockResolvedValue(session({ id: 92, limits: freeLimits(1, 0) }))
      const { wrapper } = await render({}, hub({ limits: freeLimits(0, 0) }))
      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 2 из 2')

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 1 из 2')
      wrapper.unmount()
    })

    it('the last free session: the line goes to 0, and the next press is the paywall', async () => {
      vi.mocked(api.practice.open).mockResolvedValueOnce(session({ id: 93, limits: freeLimits(2, 0, resetsAt()) }))
      const { wrapper } = await render({}, hub({ limits: freeLimits(1, 0, resetsAt()) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()
      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 0 из 2')

      await card(wrapper, 'collocation').trigger('click')
      expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice_limit')
      expect(api.practice.open).toHaveBeenCalledTimes(1)
      wrapper.unmount()
    })

    it('a 201 with `null` (the learner is Pro now) takes the line away', async () => {
      vi.mocked(api.practice.open).mockResolvedValue(session({ id: 94, limits: null }))
      const { wrapper } = await render({}, hub({ limits: freeLimits(0, 0) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(line(wrapper).exists()).toBe(false)
      wrapper.unmount()
    })

    it('403 practice_limit_reached - the screen believed a session was left - is the paywall of the limit with the numbers of the refusal, no toast', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('sessions', resetsAt())))
      const { wrapper } = await render({}, hub({ limits: freeLimits(1, 0, resetsAt()) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      const sheet = wrapper.get('[data-testid=paywall]')
      expect(sheet.attributes('data-reason')).toBe('practice_limit')
      expect(sheet.attributes('data-kind')).toBe('sessions')
      expect(showErrorToast).not.toHaveBeenCalled()
      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 0 из 2')
      wrapper.unmount()
    })

    it('403 practice_limit_reached whose details cannot be read is told in a toast, as any refusal', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', { kind: 'sessions' }))
      const { wrapper } = await render({}, hub({ limits: freeLimits(0, 0) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(showErrorToast).toHaveBeenCalledWith('Бесплатный лимит на сегодня закончился. Он обновится завтра.')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      wrapper.unmount()
    })

    it('403 practice_limit_reached for a learner the screen thought was Pro: the sheet opens, the line appears', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(apiError('forbidden', 403, 'practice_limit_reached', limitDetails('sessions', resetsAt())))
      const { wrapper } = await render({}, hub({ limits: null }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=paywall]').attributes('data-reason')).toBe('practice_limit')
      wrapper.unmount()
    })

    it('a refusal of another kind is as it was: a toast, and no paywall', async () => {
      vi.mocked(api.practice.open).mockRejectedValue(offline())
      const { wrapper } = await render({}, hub({ limits: freeLimits(0, 0) }))

      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(showErrorToast).toHaveBeenCalledWith('Нет связи с сервером. Попробуйте ещё раз.')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      expect(line(wrapper).text()).toBe('Сегодня бесплатно: осталось 2 из 2')
      wrapper.unmount()
    })
  })

  describe('when the plan changes under the hub', () => {
    const plan = (value: 'free' | 'pro') => ({
      entitlement: { plan: value, source: value === 'pro' ? 'payment' : 'free', until: null, new_words_per_day: value === 'pro' ? null : 20 },
      paywall: { mode: 'purchase' as const, contact: null },
    })

    it('Pro is paid for in the sheet: the hub is asked again, and the line is gone with the limit', async () => {
      const billing = useBillingStore()
      billing.apply(plan('free'))
      const { wrapper } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))
      expect(line(wrapper).exists()).toBe(true)

      vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: null }))
      billing.apply(plan('pro'))
      await flushPromises()

      expect(line(wrapper).exists()).toBe(false)
      wrapper.unmount()
    })

    it('and the next press is a session, not the paywall', async () => {
      vi.mocked(api.practice.open).mockResolvedValue(session({ id: 95, limits: null }))
      const billing = useBillingStore()
      billing.apply(plan('free'))
      const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))

      vi.mocked(api.practice.hub).mockResolvedValue(hub({ limits: null }))
      billing.apply(plan('pro'))
      await flushPromises()
      await card(wrapper, 'pairs').trigger('click')
      await flushPromises()

      expect(api.practice.open).toHaveBeenCalledTimes(1)
      expect(router.currentRoute.value.name).toBe('practice-session')
      wrapper.unmount()
    })
  })
})

// "Play with a friend" starts a battle: a challenge, with a counter of its own (one a day on Free).
describe('PracticeHubView — the Free limit of battles', () => {
  const resetsAt = () => inHours(4)

  it('with none left it opens the paywall of the limit, not the list of friends that would end in it', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(0, 1, resetsAt()) }))

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    const sheet = wrapper.get('[data-testid=paywall]')
    expect(sheet.attributes('data-reason')).toBe('practice_limit')
    expect(sheet.attributes('data-kind')).toBe('battles')
    expect(sheet.attributes('data-limit')).toBe('1')
    expect(wrapper.find('[data-testid=friend-picker]').exists()).toBe(false)
    expect(api.friends.get).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('closing the sheet leaves the hub as it was', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(0, 1, resetsAt()) }))
    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')

    await wrapper.get('[data-testid=paywall-close]').trigger('click')

    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=mode-cards]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('with a challenge left it opens the friends as it always did, even when the sessions of the day are spent', async () => {
    const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 0, resetsAt()) }))

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid=friend-picker]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    await wrapper.get('[data-testid=friend-32]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('battle-new')
    wrapper.unmount()
  })

  it('a battle that is on is not a new challenge: the button leads back into it, whatever the counter says', async () => {
    const { wrapper, router } = await render({}, hub({ limits: freeLimits(0, 1, resetsAt()), battle: { enabled: true, active_battle_id: 15, incoming_count: 0 } }))

    await wrapper.get('[data-testid=battle-play-btn]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('battle')
    expect(router.currentRoute.value.params.id).toBe('15')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('an invitation of a friend is answered in its battle: the list of invitations is never behind the limit', async () => {
    vi.mocked(api.battles.list).mockResolvedValue({ active: null, incoming: [incoming(15, 'Тимур')], finished: [] })
    const { wrapper, router } = await render({}, hub({ limits: freeLimits(2, 1, resetsAt()), battle: { enabled: true, active_battle_id: null, incoming_count: 1 } }))

    await wrapper.get('[data-testid=invitation-15]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('battle')
    expect(router.currentRoute.value.params.id).toBe('15')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('says nothing about battles on the hub: the line is about the sessions, the battle counter is told where a battle is made', async () => {
    const { wrapper } = await render({}, hub({ limits: freeLimits(0, 1, resetsAt()) }))

    expect(wrapper.find('[data-testid=practice-limit]').text()).not.toContain('Вызов')
    expect(wrapper.get('[data-testid=battle-card]').text()).not.toContain('осталось')
    wrapper.unmount()
  })
})
