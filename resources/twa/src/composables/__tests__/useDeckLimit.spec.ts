import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent } from 'vue'
import { useDeckLimit } from '../useDeckLimit'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useDecksStore } from '@/stores/decks'
import type { DeckLimits, MeResponse } from '@/api/types'
import { settingsFixture } from '@/api/__tests__/settingsFixture'

vi.mock('@/api', () => ({ api: { me: { get: vi.fn() } } }))

const FREE: DeckLimits = { decks: 1, deck_words: 50, used_decks: 1 }
const PRO: DeckLimits = { decks: 20, deck_words: 2000, used_decks: 20 }

const me = (plan: 'free' | 'pro'): MeResponse => ({
  learner: { id: 1, first_name: 'A', username: null, locale: 'ru', onboarding_completed: true },
  groups: [],
  stats: { words_learned: 0, words_due_today: 0 },
  entitlement: { plan, source: plan === 'pro' ? 'subscription' : 'free', until: null, new_words_per_day: plan === 'pro' ? null : 20 },
  paywall: { mode: 'coming_soon', contact: null },
  settings: settingsFixture(),
})

/** Mounts a screen that calls `useDeckLimit()`; returns what it gives, and the wrapper to unmount. */
async function setup(plan: 'free' | 'pro' | null) {
  if (plan === null) vi.mocked(api.me.get).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
  else vi.mocked(api.me.get).mockResolvedValue(me(plan))

  let given!: ReturnType<typeof useDeckLimit>
  const Screen = defineComponent({
    setup() {
      given = useDeckLimit()
      return () => null
    },
  })
  const wrapper = mount(Screen)
  await flushPromises()
  return { limit: given, wrapper, store: useDecksStore() }
}

/** The server refused a write: the flag, its kind and the numbers the refusal brought along. */
function refuse(store: ReturnType<typeof useDecksStore>, kind: 'decks' | 'deck_words', limits: DeckLimits | null) {
  store.limits = limits
  store.limitKind = kind
  store.limitReached = true
}

describe('useDeckLimit', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    vi.spyOn(console, 'warn').mockImplementation(() => {})
  })
  afterEach(() => {
    setLocale('ru')
    vi.restoreAllMocks()
  })

  it('asks /me when the screen opens, so the plan is known by the time of a refusal', async () => {
    await setup('free')

    expect(api.me.get).toHaveBeenCalledTimes(1)
  })

  it('shows nothing while no limit refused anything', async () => {
    const { limit } = await setup('pro')

    expect(limit.paywall.value).toBe(false)
    expect(limit.proNotice.value).toBe('')
  })

  it('opens the paywall for a Free learner and gives no explanation', async () => {
    const { limit, store } = await setup('free')

    refuse(store, 'decks', FREE)

    expect(limit.paywall.value).toBe(true)
    expect(limit.proNotice.value).toBe('')
  })

  it('does not sell Pro to a Pro learner: no paywall, the limit of the plan with the numbers the server applies', async () => {
    const { limit, store } = await setup('pro')

    refuse(store, 'decks', PRO)

    expect(limit.paywall.value).toBe(false)
    expect(limit.proNotice.value).toBe(
      'Это предел вашего тарифа Pro: 20 колод. Чтобы создать новую колоду, удалите ненужную — слова уйдут в архив, а прогресс сохранится.',
    )
  })

  it('names the size of a deck when it is the words that did not fit', async () => {
    const { limit, store } = await setup('pro')

    refuse(store, 'deck_words', PRO)

    expect(limit.paywall.value).toBe(false)
    expect(limit.proNotice.value).toBe(
      'Это предел вашего тарифа Pro: до 2000 слов в колоде. Ничего не добавлено — уберите ненужные слова или добавьте часть списка в другую колоду.',
    )
  })

  it('has no explanation without the numbers, and still no paywall: the screen shows its general refusal', async () => {
    const { limit, store } = await setup('pro')

    refuse(store, 'decks', null)

    expect(limit.paywall.value).toBe(false)
    expect(limit.proNotice.value).toBe('')
  })

  it('opens the paywall while the plan is not known: the sheet loads /me itself and its texts follow the plan', async () => {
    const { limit, store } = await setup(null)

    refuse(store, 'decks', FREE)

    expect(limit.paywall.value).toBe(true)
    expect(limit.proNotice.value).toBe('')
  })

  it('explains in Uzbek to a Pro learner', async () => {
    setLocale('uz')
    const { limit, store } = await setup('pro')

    refuse(store, 'decks', PRO)

    expect(limit.proNotice.value).toBe(
      "Bu Pro tarifingizdagi chegara: 20 ta to'plam. Yangi to'plam yaratish uchun keraksizini o'chiring — so'zlar arxivga o'tadi, natijangiz saqlanadi.",
    )
  })

  it('drops the refusal when the screen is left: it must not greet the learner on the other deck screen', async () => {
    const { wrapper, store } = await setup('free')
    refuse(store, 'decks', FREE)

    wrapper.unmount()

    expect(store.limitReached).toBe(false)
    expect(store.limitKind).toBeNull()
  })
})
