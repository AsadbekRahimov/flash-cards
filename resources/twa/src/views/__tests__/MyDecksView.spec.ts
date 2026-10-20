import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import MyDecksView from '../MyDecksView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { Deck, DecksResponse } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn().mockResolvedValue(null) },
    decks: { list: vi.fn(), create: vi.fn() },
    courses: { select: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const deck = (overrides: Partial<Deck> = {}): Deck => ({
  slug: 'deck-1-abc',
  title: 'Мои слова',
  words_count: 3,
  is_active: false,
  progress: { done: 1, total: 3 },
  ...overrides,
})

const response = (decks: Deck[] = [deck()], used = decks.length): DecksResponse => ({
  decks,
  limits: { decks: 1, deck_words: 50, used_decks: used },
})

async function render(data: DecksResponse = response()) {
  vi.mocked(api.decks.list).mockResolvedValue(data)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/today', name: 'today', component: { template: '<div />' } },
      { path: '/decks', name: 'decks', component: { template: '<div />' } },
      { path: '/decks/:slug', name: 'deck', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(MyDecksView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('MyDecksView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('lists the decks with count, progress and the plan limits', async () => {
    const { wrapper } = await render()

    const card = wrapper.get('[data-testid="deck-deck-1-abc"]')
    expect(card.get('h2').text()).toBe('Мои слова')
    expect(card.get('[data-testid=meta]').text()).toBe('3 слова')
    expect(card.get('[data-testid=progress-text]').text()).toBe('Изучено 1 из 3')
    expect(card.get('[data-testid=deck-link]').attributes('href')).toBe('/decks/deck-1-abc')
    expect(wrapper.get('[data-testid=limits]').text()).toBe('Колод: 1 из 1. В одной колоде — до 50 слов.')
  })

  it('shows the empty state', async () => {
    const { wrapper } = await render(response([]))

    expect(wrapper.get('[data-testid=empty]').text()).toBe('У вас пока нет колод.')
    expect(wrapper.find('[data-testid^="deck-deck"]').exists()).toBe(false)
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('h1').text()).toBe("Mening to'plamlarim")
    expect(wrapper.get('[data-testid=create-btn]').text()).toBe("To'plam yaratish")
  })

  it('creates a deck and opens it', async () => {
    vi.mocked(api.decks.create).mockResolvedValue(deck({ slug: 'deck-1-new', title: 'Новая', words_count: 0 }))
    const { wrapper, router } = await render(response([], 0))

    await wrapper.get('[data-testid=title-input]').setValue('  Новая ')
    await wrapper.get('[data-testid=create-form]').trigger('submit')
    await flushPromises()

    expect(api.decks.create).toHaveBeenCalledWith('Новая')
    expect(router.currentRoute.value.fullPath).toBe('/decks/deck-1-new')
  })

  it('does not submit an empty title', async () => {
    const { wrapper } = await render(response([], 0))

    expect(wrapper.get('[data-testid=create-btn]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid=title-input]').setValue('   ')
    await wrapper.get('[data-testid=create-form]').trigger('submit')

    expect(api.decks.create).not.toHaveBeenCalled()
  })

  it('opens the paywall when the deck limit refuses the new deck', async () => {
    vi.mocked(api.decks.create).mockRejectedValue(new ApiClientError('validation', 422, 'deck_limit_reached', 'x'))
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=title-input]').setValue('Вторая')
    await wrapper.get('[data-testid=create-form]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid=write-error]').text()).toBe('Достигнут лимит колод вашего тарифа.')
    expect(wrapper.get('[data-testid=paywall-lead]').text()).toContain('1 колода до 50 слов')
    expect(router.currentRoute.value.fullPath).toBe('/')

    await wrapper.get('[data-testid=paywall-close]').trigger('click')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('chooses a deck as the active course; an empty deck cannot be chosen', async () => {
    vi.mocked(api.courses.select).mockResolvedValue({
      slug: 'deck-1-abc',
      title: 'Мои слова',
      description: null,
      level: null,
      exam_tag: null,
      words_count: 3,
      is_featured: false,
      is_active: true,
      progress: { done: 1, total: 3 },
    })
    const { wrapper } = await render(response([deck(), deck({ slug: 'empty', words_count: 0 })], 1))

    expect(wrapper.get('[data-testid="deck-empty"] [data-testid=choose-btn]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="deck-deck-1-abc"] [data-testid=choose-btn]').trigger('click')
    await flushPromises()

    expect(api.courses.select).toHaveBeenCalledWith('deck-1-abc')
    expect(wrapper.get('[data-testid="deck-deck-1-abc"] [data-testid=active-badge]').text()).toBe('✓ Активный')
  })

  it('retries after a failed load', async () => {
    vi.mocked(api.decks.list).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'x'))
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/decks/:slug', name: 'deck', component: { template: '<div />' } }],
    })
    const wrapper = mount(MyDecksView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')

    vi.mocked(api.decks.list).mockResolvedValue(response())
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="deck-deck-1-abc"]').exists()).toBe(true)
  })
})
