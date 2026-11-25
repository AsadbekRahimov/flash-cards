import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupInviteView from '../CupInviteView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useCupsStore } from '@/stores/cups'
import { cupInvite, cupFields } from '@/api/__tests__/cupFixtures'
import type { CupInvite } from '@/api/types'

/** The entry from the link of the chat (docs/33 §7.1, docs/04 A.23 `GET /cups/by-code`): the invitation, joining, every refusal. */

vi.mock('@/api', () => ({
  api: { cups: { invite: vi.fn(), join: vi.fn(), list: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))

const CODE = 'AbCdEfGhIjKlMnOpQrSt_-'

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

async function render(answer: CupInvite | Error = cupInvite(), play = false, rules = false) {
  if (answer instanceof Error) vi.mocked(api.cups.invite).mockRejectedValue(answer)
  else vi.mocked(api.cups.invite).mockResolvedValue(answer)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/cups/c/:code', name: 'cup-invite', component: stub },
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
      { path: '/cups/:id(\\d+)/round', name: 'cup-round', component: stub },
    ],
  })
  await router.push(`/cups/c/${CODE}`)
  const wrapper = mount(CupInviteView, { props: { code: CODE, play, rules }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const state = (r: Rendered) => tid(r, 'cup-invite-view').attributes('data-state')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('CupInviteView: the invitation', () => {
  it('shows the group, the level, the dates, the participants, the rules and the name; asks by the code', async () => {
    const r = await render()

    expect(api.cups.invite).toHaveBeenCalledWith(CODE)
    expect(state(r)).toBe('invite')
    expect(r.wrapper.text()).toContain('English Friends')
    expect(r.wrapper.text()).toContain('Кубок друзей Muhrla')
    expect(tid(r, 'invite-level').text()).toBe('Уровень A2')
    expect(tid(r, 'invite-period').text()).toMatch(/1.*дек.*7.*дек/)
    expect(tid(r, 'invite-status').text()).toBe('День 3 из 7')
    expect(tid(r, 'invite-participants').text()).toBe('4 участника')
    expect(tid(r, 'rule-round').text()).toContain('Одна попытка в день')
    expect(tid(r, 'rule-points').text()).toBe('За верный ответ — 2 очка, ответ с опечаткой — 0 очков.')
    expect(tid(r, 'rule-best').text()).toBe('В зачёт идут 5 лучших дней из 7.')
    expect(tid(r, 'rule-day').text()).toContain('по Ташкенту')
    expect((tid(r, 'name-input').element as HTMLInputElement).value).toBe('Aziza')
    expect(tid(r, 'name-hint').text()).toContain('увидят в чате')
    expect(tid(r, 'invite-rules').attributes('open')).toBeUndefined()
  })

  it('opens the rules at once for `?view=rules`', async () => {
    const r = await render(cupInvite(), false, true)

    expect(tid(r, 'invite-rules').attributes('open')).toBeDefined()
  })

  it('joins with the name of the field and opens the cup; a second tap while it goes does nothing', async () => {
    let resolveJoin: (value: unknown) => void = () => {}
    vi.mocked(api.cups.join).mockReturnValue(new Promise((resolve) => (resolveJoin = resolve)) as never)
    const r = await render()
    await tid(r, 'name-input').setValue('  Aziza B  ')

    await tid(r, 'join-btn').trigger('click')
    expect(tid(r, 'join-btn').attributes('aria-busy')).toBe('true')
    await tid(r, 'join-btn').trigger('click')
    resolveJoin({ participant_id: 9 })
    await flushPromises()

    expect(api.cups.join).toHaveBeenCalledTimes(1)
    expect(api.cups.join).toHaveBeenCalledWith(5, 'Aziza B')
    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })

  it('the play link takes a new participant to the round of the day', async () => {
    vi.mocked(api.cups.join).mockResolvedValue({} as never)
    const r = await render(cupInvite(), true)

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5/round')
  })

  it('a participant goes straight to the cup; with the play link - to the round', async () => {
    const joined = cupInvite({ me: { joined: true, participant_id: 2, status: 'active', display_name: 'Me', left: false } })
    const r = await render(joined)
    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')

    const p = await render(joined, true)
    expect(p.router.currentRoute.value.fullPath).toBe('/cups/5/round')
  })

  it('the play link of a cup that has not started opens the cup, not the round', async () => {
    const r = await render(cupInvite({ ...cupFields({ status: 'scheduled', day: null }), me: { joined: true, participant_id: 2, status: 'active', display_name: 'Me', left: false } }), true)

    expect(r.router.currentRoute.value.fullPath).toBe('/cups/5')
  })

  it('one who left the chat sees the invitation again with "Return"', async () => {
    const r = await render(cupInvite({ me: { joined: true, participant_id: 2, status: 'left', display_name: 'Me', left: true } }))

    expect(state(r)).toBe('invite')
    expect(tid(r, 'join-btn').text()).toBe('Вернуться в кубок')
  })

  it('a name the server refuses is told under the field; the learner stays', async () => {
    vi.mocked(api.cups.join).mockRejectedValue(apiError('validation', 422, 'cup_display_name_invalid'))
    const r = await render()

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'name-error').text()).toContain('от 1 до 32 символов')
    expect(tid(r, 'name-input').attributes('aria-invalid')).toBe('true')
    expect(r.router.currentRoute.value.name).toBe('cup-invite')
  })

  it.each([
    ['link', 'похоже на ссылку'],
    ['mention', 'не может начинаться с @'],
    ['marks', 'значков над буквами'],
    ['too_long', 'от 1 до 32 символов'],
  ])('a name refused for `%s` says why', async (reason, text) => {
    vi.mocked(api.cups.join).mockRejectedValue(apiError('validation', 422, 'cup_display_name_invalid', { reason }))
    const r = await render()

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'name-error').text()).toContain(text)
  })

  it('an emoji name is not cut by the field (the server counts what a person sees)', async () => {
    const r = await render()

    expect(Number(tid(r, 'name-input').attributes('maxlength'))).toBeGreaterThan(64)
  })

  it.each([
    ['telegram', apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }), 'Telegram сейчас не отвечает'],
    ['busy', apiError('rate_limited', 429, 'cup_check_busy'), 'Подождите несколько секунд'],
    ['bot', apiError('conflict', 409, 'cup_unverifiable', { reason: 'bot_not_admin' }), 'Попросите админа группы'],
    ['closed', apiError('gone', 410, 'cup_closed'), 'уже завершён'],
    ['not open', apiError('conflict', 409, 'cup_not_open'), 'ещё не опубликован'],
  ])('a refusal of joining (%s) is said above the button', async (_name, err, text) => {
    vi.mocked(api.cups.join).mockRejectedValue(err)
    const r = await render()

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'join-error').text()).toContain(text)
    expect(tid(r, 'join-btn').attributes('disabled')).toBeUndefined()
  })

  it('an empty name cannot join', async () => {
    const r = await render()
    await tid(r, 'name-input').setValue('   ')

    expect(tid(r, 'join-btn').attributes('disabled')).toBeDefined()
  })

  it('a draft and a cup that is over have no button', async () => {
    const draft = await render(cupInvite({ ...cupFields({ status: 'draft', starts_at: null, ends_at: null, day: null }) }))
    expect(has(draft, 'join-btn')).toBe(false)
    expect(tid(draft, 'not-open').text()).toContain('ещё не опубликован')

    const over = await render(cupInvite({ ...cupFields({ status: 'finished', day: null }) }))
    expect(has(over, 'join-btn')).toBe(false)
    expect(tid(over, 'not-open').text()).toContain('уже закончился')
  })

  it('joining invalidates "my cups" so that Today shows the new cup', async () => {
    vi.mocked(api.cups.join).mockResolvedValue({} as never)
    const r = await render()
    const cups = useCupsStore()
    cups.checkedAt = Date.now()

    await tid(r, 'join-btn').trigger('click')
    await flushPromises()

    expect(cups.checkedAt).toBeNull()
  })
})

describe('CupInviteView: refusals of the code', () => {
  it('a stranger, a missing cup and a code the router refused are ONE state, with nothing of the group', async () => {
    const stranger = await render(apiError('forbidden', 403, 'cup_not_member'))
    const missing = await render(apiError('not_found', 404, 'not_found'))

    for (const r of [stranger, missing]) {
      expect(state(r)).toBe('unavailable')
      expect(tid(r, 'failure').text()).toContain('Кубок недоступен')
      expect(r.wrapper.text()).not.toContain('English Friends')
      expect(has(r, 'retry-btn')).toBe(false)
    }
    expect(tid(stranger, 'cup-invite-view').text()).toBe(tid(missing, 'cup-invite-view').text())
  })

  it('the switched off cup', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_unavailable'))

    expect(state(r)).toBe('off')
    expect(r.wrapper.text()).toContain('Кубки недоступны')
  })

  it('503: Telegram does not answer - try later, and "Retry" asks again', async () => {
    const r = await render(apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }))

    expect(state(r)).toBe('telegram')
    expect(r.wrapper.text()).toContain('Попробуйте позже')
    vi.mocked(api.cups.invite).mockResolvedValue(cupInvite())
    await tid(r, 'retry-btn').trigger('click')
    await flushPromises()

    expect(api.cups.invite).toHaveBeenCalledTimes(2)
    expect(state(r)).toBe('invite')
  })

  it('429 cup_check_busy: wait and retry by hand - no loop of its own', async () => {
    vi.useFakeTimers()
    try {
      const r = await render(apiError('rate_limited', 429, 'cup_check_busy'))

      expect(state(r)).toBe('busy')
      expect(r.wrapper.text()).toContain('Подождите несколько секунд')
      await vi.advanceTimersByTimeAsync(60_000)
      expect(api.cups.invite).toHaveBeenCalledTimes(1)
      expect(has(r, 'retry-btn')).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('409 bot_not_admin: ask the administrator of the group', async () => {
    const r = await render(apiError('conflict', 409, 'cup_unverifiable', { reason: 'bot_not_admin' }))

    expect(state(r)).toBe('bot_not_admin')
    expect(r.wrapper.text()).toContain('Попросите админа группы сделать бота администратором')
  })

  it('409 cup_paused: the pause and why', async () => {
    const r = await render(apiError('conflict', 409, 'cup_paused', { reason: 'bot_removed' }))

    expect(state(r)).toBe('paused')
    expect(r.wrapper.text()).toContain('бота удалили из группы')
  })

  it('no connection: the error with "Retry"', async () => {
    const r = await render(apiError('network', 0, 'network'))

    expect(state(r)).toBe('other')
    expect(has(r, 'retry-btn')).toBe(true)
  })
})

describe('CupInviteView: uz', () => {
  it('speaks Uzbek', async () => {
    setLocale('uz')
    const r = await render()

    expect(tid(r, 'join-btn').text()).toBe('Qatnashish')
    expect(tid(r, 'invite-status').text()).toBe('7 kundan 3-kun')
    expect(tid(r, 'rule-best').text()).toBe('7 kundan 5 ta eng yaxshi kun hisobga olinadi.')
  })
})
