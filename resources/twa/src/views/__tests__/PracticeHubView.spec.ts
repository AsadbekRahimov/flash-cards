import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PracticeHubView from '../PracticeHubView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { showErrorToast } from '@/composables/errorToast'
import { activeSession, hub, session } from '@/api/__tests__/practiceFixtures'
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
      props: { reason: { type: String, default: 'info' } },
      emits: ['close', 'review'],
      setup: (props, { emit }) => () =>
        h('div', { 'data-testid': 'paywall', 'data-reason': props.reason }, [
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
async function render(props: { slug?: string; lessonId?: number | null } = {}, response: PracticeHubResponse | Promise<PracticeHubResponse> | Error = hub()) {
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
          collocation: { state: 'not_enough', available: 1, reason: 'no_items' },
          sentence: { state: 'disabled' },
          dictation: { state: 'pro_required' },
        },
      }),
    )

    expect(card(wrapper, 'pairs').attributes('data-state')).toBe('available')
    expect(card(wrapper, 'collocation').attributes('data-state')).toBe('not_enough')
    expect(card(wrapper, 'collocation').text()).toContain('Заданий: 1 из 3')
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
