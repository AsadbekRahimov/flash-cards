import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import DeckEditView from '../DeckEditView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { DeckDetail, DeckImportResult } from '@/api/types'

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn().mockResolvedValue(null) },
    decks: { get: vi.fn(), rename: vi.fn(), remove: vi.fn(), addWords: vi.fn(), removeWord: vi.fn() },
    courses: { select: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: () => () => {} }),
}))

const detail = (overrides: Partial<DeckDetail> = {}): DeckDetail => ({
  slug: 'deck-1-abc',
  title: 'Мои слова',
  words_count: 2,
  is_active: false,
  progress: { done: 0, total: 2 },
  words: [
    { id: 1, word: 'apple', translation: 'яблоко', translation_uz: null, example: null },
    { id: 2, word: 'pear', translation: 'груша', translation_uz: 'nok', example: null },
  ],
  ...overrides,
})

async function render(data: DeckDetail = detail()) {
  vi.mocked(api.decks.get).mockResolvedValue(data)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/decks', name: 'decks', component: { template: '<div />' } },
      { path: '/decks/:slug', name: 'deck', component: DeckEditView, props: true },
    ],
  })
  await router.push('/decks/deck-1-abc')
  const wrapper = mount(DeckEditView, { props: { slug: 'deck-1-abc' }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('DeckEditView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })
  afterEach(() => setLocale('ru'))

  it('shows the title and the word list', async () => {
    const { wrapper } = await render()

    expect((wrapper.get('[data-testid=rename-input]').element as HTMLInputElement).value).toBe('Мои слова')
    expect(wrapper.get('[data-testid=word-1]').text()).toContain('apple')
    expect(wrapper.get('[data-testid=word-1]').text()).toContain('яблоко')
    expect(wrapper.findAll('[data-testid^=word-]')).toHaveLength(2)
  })

  it('shows an empty-deck hint and cannot choose an empty deck', async () => {
    const { wrapper } = await render(detail({ words: [], words_count: 0 }))

    expect(wrapper.get('[data-testid=no-words]').text()).toBe('В колоде пока нет слов.')
    expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeDefined()
  })

  it('renames only when the title changed', async () => {
    vi.mocked(api.decks.rename).mockResolvedValue({ ...detail(), title: 'Новое' })
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=rename-btn]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid=rename-input]').setValue('Новое')
    await wrapper.get('[data-testid=rename-form]').trigger('submit')
    await flushPromises()

    expect(api.decks.rename).toHaveBeenCalledWith('deck-1-abc', 'Новое')
  })

  it('sends the typed list with the chosen format and shows the report', async () => {
    const result: DeckImportResult = {
      added: 2,
      reactivated: 0,
      duplicates: 1,
      rejected: [
        { line: 3, reason: 'junk' },
        { line: 5, reason: 'empty_translation' },
      ],
      deck: detail(),
    }
    vi.mocked(api.decks.addWords).mockResolvedValue(result)
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=hint]').text()).toContain('слово — перевод')
    await wrapper.get('[data-testid=format-csv]').trigger('click')
    expect(wrapper.get('[data-testid=hint]').text()).toContain('word,translation_ru')

    await wrapper.get('[data-testid=text-input]').setValue('word,translation_ru\nplum,слива')
    await wrapper.get('[data-testid=add-btn]').trigger('click')
    await flushPromises()

    expect(api.decks.addWords).toHaveBeenCalledWith('deck-1-abc', 'word,translation_ru\nplum,слива', 'csv')
    expect(wrapper.get('[data-testid=report-summary]').text()).toBe(
      'Добавлено: 2 · вернулось: 0 · уже были: 1 · пропущено строк: 2',
    )
    const rejected = wrapper.get('[data-testid=report-rejected]').text()
    expect(rejected).toContain('Строка 3: не похоже на слово')
    expect(rejected).toContain('Строка 5: нет перевода')
    expect((wrapper.get('[data-testid=text-input]').element as HTMLTextAreaElement).value).toBe('')
  })

  it('does not send a text over 256 KB', async () => {
    const { wrapper } = await render()

    await wrapper.get('[data-testid=text-input]').setValue('a'.repeat(256 * 1024 + 1))
    await wrapper.get('[data-testid=add-btn]').trigger('click')
    await flushPromises()

    expect(api.decks.addWords).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid=size-error]').text()).toBe('Текст слишком большой: не больше 256 КБ.')
  })

  it('keeps the typed text and opens the paywall when the word limit refuses the import', async () => {
    vi.mocked(api.decks.addWords).mockRejectedValue(new ApiClientError('validation', 422, 'deck_words_limit_reached', 'x'))
    const { wrapper } = await render()

    await wrapper.get('[data-testid=text-input]').setValue('plum — слива')
    await wrapper.get('[data-testid=add-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=write-error]').text()).toBe('Достигнут лимит слов в колоде вашего тарифа. Ничего не добавлено.')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(true)
    expect((wrapper.get('[data-testid=text-input]').element as HTMLTextAreaElement).value).toBe('plum — слива')
    expect(wrapper.find('[data-testid=report]').exists()).toBe(false)
  })

  it('explains an import with no usable line', async () => {
    vi.mocked(api.decks.addWords).mockRejectedValue(new ApiClientError('validation', 422, 'deck_import_empty', 'x'))
    const { wrapper } = await render()

    await wrapper.get('[data-testid=text-input]').setValue('123')
    await wrapper.get('[data-testid=add-btn]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid=write-error]').text()).toBe('В тексте не нашлось ни одной подходящей строки.')
    expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
  })

  it('removes a word', async () => {
    vi.mocked(api.decks.removeWord).mockResolvedValue(undefined)
    const { wrapper } = await render()

    await wrapper.get('[data-testid=word-1] [data-testid=remove-word]').trigger('click')
    await flushPromises()

    expect(api.decks.removeWord).toHaveBeenCalledWith('deck-1-abc', 1)
    expect(wrapper.find('[data-testid=word-1]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=word-2]').exists()).toBe(true)
  })

  it('asks before deleting, archives and goes back to the list', async () => {
    vi.mocked(api.decks.remove).mockResolvedValue(undefined)
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid=delete-btn]').trigger('click')
    expect(wrapper.get('[data-testid=delete-confirm]').text()).toContain('Слова уйдут в архив, ваш прогресс сохранится.')
    expect(api.decks.remove).not.toHaveBeenCalled()

    await wrapper.get('[data-testid=delete-no]').trigger('click')
    expect(wrapper.find('[data-testid=delete-confirm]').exists()).toBe(false)

    await wrapper.get('[data-testid=delete-btn]').trigger('click')
    await wrapper.get('[data-testid=delete-yes]').trigger('click')
    await flushPromises()

    expect(api.decks.remove).toHaveBeenCalledWith('deck-1-abc')
    expect(router.currentRoute.value.fullPath).toBe('/decks')
  })

  it('shows the error state for a deck that is gone', async () => {
    vi.mocked(api.decks.get).mockRejectedValue(new ApiClientError('not_found', 404, 'deck_not_found', 'x'))
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/decks', name: 'decks', component: { template: '<div />' } }] })
    const wrapper = mount(DeckEditView, { props: { slug: 'gone' }, global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.get('[data-testid=error]').text()).toContain('Колода не найдена.')
    expect(wrapper.get('[data-testid=back-link]').attributes('href')).toBe('/decks')
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=add-btn]').text()).toBe("Qo'shish")
    expect(wrapper.get('[data-testid=delete-btn]').text()).toBe("To'plamni o'chirish")
  })
})
