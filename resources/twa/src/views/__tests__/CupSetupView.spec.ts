import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import CupSetupView from '../CupSetupView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { cupFields, cupSetup } from '@/api/__tests__/cupFixtures'
import type { CupSetup } from '@/api/types'

/** The setup of a cup by an administrator of the chat (docs/33 §7.1, docs/04 A.23 `/cups/setup/{code}`). */

vi.mock('@/api', () => ({
  api: { cups: { setup: vi.fn(), publish: vi.fn(), settings: vi.fn(), cancel: vi.fn(), list: vi.fn() } },
}))

const authEnsure = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: authEnsure }) }))
vi.mock('@/composables/useBackButton', () => ({ useBackButton: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: vi.fn() }) }))

const CODE = 'AbCdEfGhIjKlMnOpQrSt_-'

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

const published = (over: Partial<CupSetup> = {}): CupSetup => cupSetup({ ...cupFields({ status: 'scheduled', day: null }), ...over })

async function render(answer: CupSetup | Error = cupSetup()) {
  if (answer instanceof Error) vi.mocked(api.cups.setup).mockRejectedValue(answer)
  else vi.mocked(api.cups.setup).mockResolvedValue(answer)
  const stub = { template: '<div />' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: stub },
      { path: '/cups/setup/:code', name: 'cup-setup', component: stub },
      { path: '/cups/:id(\\d+)', name: 'cup', component: stub },
    ],
  })
  await router.push(`/cups/setup/${CODE}`)
  const wrapper = mount(CupSetupView, { props: { code: CODE }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

type Rendered = Awaited<ReturnType<typeof render>>
const tid = (r: Rendered, id: string) => r.wrapper.get(`[data-testid="${id}"]`)
const has = (r: Rendered, id: string) => r.wrapper.find(`[data-testid="${id}"]`).exists()
const state = (r: Rendered) => tid(r, 'cup-setup-view').attributes('data-state')

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  authEnsure.mockResolvedValue(undefined)
  setLocale('ru')
  document.body.innerHTML = ''
})

describe('CupSetupView: a draft', () => {
  it('the level and the language of the chat from the options, the rules, "Publish"', async () => {
    const r = await render()

    expect(api.cups.setup).toHaveBeenCalledWith(CODE)
    expect(state(r)).toBe('draft')
    expect(tid(r, 'setup-status').text()).toBe('Кубок ещё не опубликован')
    expect(tid(r, 'level-select').findAll('option').map((o) => o.text())).toEqual(['A1', 'A2', 'B1'])
    expect((tid(r, 'level-select').element as HTMLSelectElement).value).toBe('A2')
    expect(tid(r, 'locale-select').findAll('option').map((o) => o.text())).toEqual(['Русский', 'Узбекский'])
    expect(has(r, 'cup-rules')).toBe(true)
  })

  it('publishes with the level and the language chosen; the cup is scheduled then', async () => {
    let resolve!: (value: CupSetup) => void
    vi.mocked(api.cups.publish).mockReturnValue(new Promise((yes) => (resolve = yes)))
    const r = await render()

    await tid(r, 'level-select').setValue('B1')
    await tid(r, 'locale-select').setValue('uz')
    await tid(r, 'publish-btn').trigger('click')
    expect(tid(r, 'publish-btn').attributes('aria-busy')).toBe('true')
    await tid(r, 'publish-btn').trigger('click')
    resolve(published({ level: 'B1', locale: 'uz' }))
    await flushPromises()

    expect(api.cups.publish).toHaveBeenCalledTimes(1)
    expect(api.cups.publish).toHaveBeenCalledWith(CODE, { level: 'B1', locale: 'uz' })
    expect(state(r)).toBe('scheduled')
    expect(has(r, 'publish-card')).toBe(false)
    expect(has(r, 'settings-card')).toBe(true)
    expect(tid(r, 'to-cup').attributes('href')).toBe('/cups/5')
  })

  it.each([
    [apiError('conflict', 409, 'cup_bot_not_admin'), 'Сделайте бота администратором группы'],
    [apiError('validation', 422, 'cup_material_unavailable'), 'не хватает слов'],
    [apiError('forbidden', 403, 'cup_not_chat_admin'), 'только администратор группы'],
  ])('a refused publication is said above the button', async (err, text) => {
    vi.mocked(api.cups.publish).mockRejectedValue(err)
    const r = await render()

    await tid(r, 'publish-btn').trigger('click')
    await flushPromises()

    expect(tid(r, 'publish-error').text()).toContain(text)
    expect(state(r)).toBe('draft')
  })
})

describe('CupSetupView: published', () => {
  it('the summaries: off, or another time - PATCH settings with the minute of the day', async () => {
    vi.mocked(api.cups.settings).mockResolvedValue(published({ summary_time: '21:30', summary_minute: 1290 }))
    const r = await render(published())

    expect(tid(r, 'settings-save').attributes('disabled')).toBeDefined()
    await tid(r, 'summary-time').setValue('21:30')
    await tid(r, 'settings-save').trigger('click')
    await flushPromises()

    expect(api.cups.settings).toHaveBeenCalledWith(5, { summaries_enabled: true, summary_minute: 1290 })
    expect(has(r, 'settings-saved')).toBe(true)
  })

  it('cancels after the confirmation', async () => {
    vi.mocked(api.cups.cancel).mockResolvedValue(published({ status: 'canceled', cancel_reason: 'chat_admin' }))
    const r = await render(published())

    await tid(r, 'cancel-btn').trigger('click')
    ;(document.body.querySelector('[data-testid=cancel-confirm]') as HTMLButtonElement).click()
    await flushPromises()

    expect(api.cups.cancel).toHaveBeenCalledWith(5)
    expect(state(r)).toBe('canceled')
    expect(tid(r, 'setup-status').text()).toBe('Кубок отменён')
    expect(has(r, 'cancel-btn')).toBe(false)
  })

  it('cancel refused to one who is not an administrator any more', async () => {
    vi.mocked(api.cups.cancel).mockRejectedValue(apiError('forbidden', 403, 'cup_not_chat_admin'))
    const r = await render(published())

    await tid(r, 'cancel-btn').trigger('click')
    ;(document.body.querySelector('[data-testid=cancel-confirm]') as HTMLButtonElement).click()
    await flushPromises()

    expect(document.body.querySelector('[data-testid=cancel-error]')?.textContent).toBe('Это может сделать только администратор группы.')
  })
})

describe('CupSetupView: refusals', () => {
  it('not an administrator of the chat (also "no such code"): only an administrator can, no retry', async () => {
    const r = await render(apiError('forbidden', 403, 'cup_not_chat_admin'))

    expect(state(r)).toBe('not_admin')
    expect(r.wrapper.text()).toContain('Только для админа группы')
    expect(r.wrapper.text()).toContain('Это может сделать только администратор группы.')
    expect(has(r, 'retry-btn')).toBe(false)
  })

  it('Telegram down: try later, "Retry"', async () => {
    const r = await render(apiError('server', 503, 'cup_unverifiable', { reason: 'telegram_unavailable' }))

    expect(state(r)).toBe('telegram')
    expect(has(r, 'retry-btn')).toBe(true)
  })

  it('uz: the refusal in Uzbek', async () => {
    setLocale('uz')
    const r = await render(apiError('forbidden', 403, 'cup_not_chat_admin'))

    expect(r.wrapper.text()).toContain('Buni faqat guruh administratori qila oladi.')
  })
})
