import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import DeckEditView from '../DeckEditView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import type { Course, DeckDetail, DeckImportResult } from '@/api/types'

const tg = vi.hoisted(() => ({
  handler: null as (() => void) | null,
  release: vi.fn(),
  showBack: vi.fn(),
  hideBack: vi.fn(),
}))

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
  useTelegram: () => ({ showBackButton: tg.showBack, webApp: { BackButton: { hide: tg.hideBack } } }),
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

const importResult = (overrides: Partial<DeckImportResult> = {}): DeckImportResult => ({
  added: 2,
  reactivated: 0,
  duplicates: 1,
  rejected: [
    { line: 3, reason: 'junk' },
    { line: 5, reason: 'empty_translation' },
  ],
  deck: detail(),
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
    tg.handler = null
    tg.showBack.mockImplementation((handler: () => void) => {
      tg.handler = handler
      return tg.release
    })
  })
  afterEach(() => setLocale('ru'))

  describe('heading and word list', () => {
    it('shows the deck title as the heading and the words with their translations', async () => {
      const { wrapper } = await render()

      const heading = wrapper.get('h1')
      expect(heading.text()).toBe('Мои слова')
      expect(heading.classes()).not.toContain('text-[28px]')
      expect((wrapper.get('[data-testid=rename-input]').element as HTMLInputElement).value).toBe('Мои слова')
      expect(wrapper.get('[data-testid=word-1]').text()).toContain('apple')
      expect(wrapper.get('[data-testid=word-1]').text()).toContain('яблоко')
      expect(wrapper.findAll('[data-testid^=word-]')).toHaveLength(2)
    })

    it('labels the list "Слова (N)" and draws it as a grouped list', async () => {
      const { wrapper } = await render()

      const words = wrapper.get('[data-testid=words]')
      expect(words.get('.section-label').text()).toBe('Слова (2)')
      expect(words.find('.list').exists()).toBe(true)
      expect(words.findAll('.list-row')).toHaveLength(2)
      // A word is the title of its row, the translation is the subtitle under it.
      const row = wrapper.get('[data-testid=word-2]')
      expect(row.findAll('p').map((p) => p.text())).toEqual(['pear', 'груша'])
    })

    it('removes a word with an icon-only button that has an accessible name', async () => {
      vi.mocked(api.decks.removeWord).mockResolvedValue(undefined)
      const { wrapper } = await render()

      const button = wrapper.get('[data-testid=word-1] [data-testid=remove-word]')
      expect(button.attributes('aria-label')).toBe('Убрать')
      expect(button.text()).toBe('')
      expect(button.find('svg').exists()).toBe(true)

      await button.trigger('click')
      await flushPromises()

      expect(api.decks.removeWord).toHaveBeenCalledWith('deck-1-abc', 1)
      expect(wrapper.find('[data-testid=word-1]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=word-2]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=words] .section-label').text()).toBe('Слова (1)')
    })

    it('shows an empty-deck hint under "Слова (0)"', async () => {
      const { wrapper } = await render(detail({ words: [], words_count: 0 }))

      expect(wrapper.get('[data-testid=no-words]').text()).toBe('В колоде пока нет слов.')
      expect(wrapper.find('[data-testid=words]').exists()).toBe(false)
      expect(wrapper.get('.section-label').text()).toBe('Слова (0)')
    })

    it('disables the remove buttons while a write is in flight', async () => {
      vi.mocked(api.decks.removeWord).mockReturnValue(new Promise(() => {}))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=word-1] [data-testid=remove-word]').trigger('click')

      expect(wrapper.findAll('[data-testid=remove-word]').map((b) => b.attributes('disabled') !== undefined)).toEqual([true, true])
    })
  })

  describe('rename', () => {
    it('renames only when the title changed', async () => {
      vi.mocked(api.decks.rename).mockResolvedValue({ ...detail(), title: 'Новое' })
      const { wrapper } = await render()

      expect(wrapper.get('[data-testid=rename-btn]').attributes('disabled')).toBeDefined()
      await wrapper.get('[data-testid=rename-input]').setValue('Новое')
      expect(wrapper.get('[data-testid=rename-btn]').attributes('disabled')).toBeUndefined()
      await wrapper.get('[data-testid=rename-form]').trigger('submit')
      await flushPromises()

      expect(api.decks.rename).toHaveBeenCalledWith('deck-1-abc', 'Новое')
      // The heading follows the new name.
      expect(wrapper.get('h1').text()).toBe('Новое')
    })

    it('puts the field and a secondary "Сохранить" button in a card with the "Название" label', async () => {
      const { wrapper } = await render()

      const form = wrapper.get('[data-testid=rename-form]')
      expect(form.classes()).toContain('card')
      expect(form.get('label').text()).toBe('Название')
      expect(form.get('label').attributes('for')).toBe(form.get('[data-testid=rename-input]').attributes('id'))
      expect(form.get('[data-testid=rename-input]').classes()).toContain('field')
      const save = form.get('[data-testid=rename-btn]')
      expect(save.text()).toBe('Сохранить')
      expect(save.classes()).toContain('btn-secondary')
    })

    it('does not rename to an empty title', async () => {
      const { wrapper } = await render()

      await wrapper.get('[data-testid=rename-input]').setValue('   ')
      await wrapper.get('[data-testid=rename-form]').trigger('submit')

      expect(api.decks.rename).not.toHaveBeenCalled()
      expect(wrapper.get('[data-testid=rename-btn]').attributes('disabled')).toBeDefined()
    })
  })

  describe('main deck', () => {
    it('offers "Сделать основной" as the primary block button', async () => {
      const { wrapper } = await render()

      const choose = wrapper.get('[data-testid=choose-btn]')
      expect(choose.text()).toBe('Сделать основной')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-block']))
      expect(wrapper.find('[data-testid=active-badge]').exists()).toBe(false)
      // The one primary button of the screen.
      expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    })

    it('cannot choose an empty deck', async () => {
      const { wrapper } = await render(detail({ words: [], words_count: 0 }))

      expect(wrapper.get('[data-testid=choose-btn]').attributes('disabled')).toBeDefined()
    })

    it('chooses the deck through the course selection and shows the chip instead of the button', async () => {
      vi.mocked(api.courses.select).mockResolvedValue({
        slug: 'deck-1-abc', title: 'Мои слова', description: null, level: null, exam_tag: null,
        words_count: 2, is_featured: false, is_active: true, progress: { done: 0, total: 2 },
      } satisfies Course)
      const { wrapper } = await render()

      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      expect(api.courses.select).toHaveBeenCalledWith('deck-1-abc')
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=active-badge]').text()).toBe('Основная колода')
    })

    it('shows a chip, not a button, for the main deck', async () => {
      const { wrapper } = await render(detail({ is_active: true }))

      const badge = wrapper.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Основная колода')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(badge.find('svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid=choose-btn]').exists()).toBe(false)
      expect(wrapper.find('.btn-primary').exists()).toBe(false)
    })

    it('explains a refused write in an alert', async () => {
      vi.mocked(api.courses.select).mockRejectedValue(new ApiClientError('not_found', 404, 'course_not_found', 'x'))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=choose-btn]').trigger('click')
      await flushPromises()

      const alert = wrapper.get('[data-testid=write-error]')
      expect(alert.text()).toBe('Курс не найден или недоступен.')
      expect(alert.attributes('role')).toBe('alert')
    })
  })

  describe('adding words', () => {
    it('switches the format with the segments and shows the matching hint', async () => {
      const { wrapper } = await render()

      const list = wrapper.get('[data-testid=format-list]')
      const csv = wrapper.get('[data-testid=format-csv]')
      expect(list.text()).toBe('Списком')
      expect(csv.text()).toBe('CSV')
      expect(list.attributes('aria-checked')).toBe('true')
      expect(csv.attributes('aria-checked')).toBe('false')
      expect(wrapper.get('[data-testid=import] [role=radiogroup]').attributes('aria-label')).toBe('Формат')
      expect(wrapper.get('[data-testid=hint]').text()).toContain('слово — перевод')

      await csv.trigger('click')

      expect(csv.attributes('aria-checked')).toBe('true')
      expect(list.attributes('aria-checked')).toBe('false')
      expect(wrapper.get('[data-testid=hint]').text()).toContain('word,translation_ru')

      await list.trigger('click')
      expect(wrapper.get('[data-testid=hint]').text()).toContain('слово — перевод')
    })

    it('has a monospace field and a tinted block "Добавить" button inside the card', async () => {
      const { wrapper } = await render()

      const card = wrapper.get('[data-testid=import]')
      expect(card.classes()).toContain('card')
      expect(card.get('h2').text()).toBe('Добавить слова')
      const input = card.get('[data-testid=text-input]')
      expect(input.element.tagName).toBe('TEXTAREA')
      expect(input.classes()).toEqual(expect.arrayContaining(['field', 'font-mono']))
      const add = card.get('[data-testid=add-btn]')
      expect(add.text()).toBe('Добавить')
      expect(add.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))
      expect(add.attributes('disabled')).toBeDefined()
    })

    it('sends the typed list with the chosen format and shows the report', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult())
      const { wrapper } = await render()

      await wrapper.get('[data-testid=format-csv]').trigger('click')
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

    it('sends the list format by default', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult())
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('plum — слива')
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      expect(api.decks.addWords).toHaveBeenCalledWith('deck-1-abc', 'plum — слива', 'list')
    })

    it('marks the report with a success icon and every dropped line with a warning icon', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult())
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('plum — слива')
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      const report = wrapper.get('[data-testid=report]')
      expect(report.get('[data-testid=report-summary] svg').classes()).toContain('text-success-ink')
      const lines = report.get('[data-testid=report-rejected]').findAll('li')
      expect(lines).toHaveLength(2)
      for (const line of lines) expect(line.get('svg').classes()).toContain('text-warning-ink')
    })

    it('leaves out the list of dropped lines when nothing was dropped', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult({ rejected: [] }))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('plum — слива')
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=report-summary]').text()).toBe('Добавлено: 2 · вернулось: 0 · уже были: 1 · пропущено строк: 0')
      expect(wrapper.find('[data-testid=report-rejected]').exists()).toBe(false)
    })

    it('does not celebrate a report in which nothing went into the deck', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult({ added: 0, reactivated: 0, duplicates: 3, rejected: [] }))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('apple — яблоко')
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      const icon = wrapper.get('[data-testid=report-summary] svg')
      expect(icon.classes()).not.toContain('text-success-ink')
      expect(icon.classes()).toContain('text-tg-hint')
    })

    it('counts restored words as added to the deck', async () => {
      vi.mocked(api.decks.addWords).mockResolvedValue(importResult({ added: 0, reactivated: 1, duplicates: 0, rejected: [] }))
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('apple — яблоко')
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-testid=report-summary] svg').classes()).toContain('text-success-ink')
    })

    it('does not send a text over 256 KB and says so in an alert', async () => {
      const { wrapper } = await render()

      await wrapper.get('[data-testid=text-input]').setValue('a'.repeat(256 * 1024 + 1))
      await wrapper.get('[data-testid=add-btn]').trigger('click')
      await flushPromises()

      expect(api.decks.addWords).not.toHaveBeenCalled()
      const alert = wrapper.get('[data-testid=size-error]')
      expect(alert.text()).toBe('Текст слишком большой: не больше 256 КБ.')
      expect(alert.attributes('role')).toBe('alert')
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
  })

  describe('deleting the deck', () => {
    it('asks before deleting, archives and goes back to the list', async () => {
      vi.mocked(api.decks.remove).mockResolvedValue(undefined)
      const { wrapper, router } = await render()

      await wrapper.get('[data-testid=delete-btn]').trigger('click')
      expect(wrapper.get('[data-testid=delete-confirm]').text()).toContain('Слова уйдут в архив, ваш прогресс сохранится.')
      expect(wrapper.find('[data-testid=delete-btn]').exists()).toBe(false)
      expect(api.decks.remove).not.toHaveBeenCalled()

      await wrapper.get('[data-testid=delete-no]').trigger('click')
      expect(wrapper.find('[data-testid=delete-confirm]').exists()).toBe(false)

      await wrapper.get('[data-testid=delete-btn]').trigger('click')
      await wrapper.get('[data-testid=delete-yes]').trigger('click')
      await flushPromises()

      expect(api.decks.remove).toHaveBeenCalledWith('deck-1-abc')
      expect(router.currentRoute.value.fullPath).toBe('/decks')
    })

    it('draws the buttons as designed: a danger block button, then "Да, удалить" and "Отмена" in a card', async () => {
      const { wrapper } = await render()

      const del = wrapper.get('[data-testid=delete-btn]')
      expect(del.text()).toBe('Удалить колоду')
      expect(del.classes()).toEqual(expect.arrayContaining(['btn', 'btn-danger', 'btn-block']))

      await del.trigger('click')

      const confirm = wrapper.get('[data-testid=delete-confirm]')
      expect(confirm.classes()).toContain('card')
      expect(confirm.text()).toContain('Удалить колоду «Мои слова»?')
      const yes = confirm.get('[data-testid=delete-yes]')
      expect(yes.text()).toBe('Да, удалить')
      expect(yes.classes()).toContain('btn-danger')
      const no = confirm.get('[data-testid=delete-no]')
      expect(no.text()).toBe('Отмена')
      expect(no.classes()).toContain('btn-secondary')
    })

    it('closes the question and stays when the archive request fails', async () => {
      vi.mocked(api.decks.remove).mockRejectedValue(new ApiClientError('not_found', 404, 'deck_not_found', 'x'))
      const { wrapper, router } = await render()

      await wrapper.get('[data-testid=delete-btn]').trigger('click')
      await wrapper.get('[data-testid=delete-yes]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.fullPath).toBe('/decks/deck-1-abc')
      expect(wrapper.find('[data-testid=delete-confirm]').exists()).toBe(false)
      expect(wrapper.get('[data-testid=write-error]').text()).toBe('Колода не найдена.')
    })
  })

  describe('states', () => {
    it('shows a skeleton while the deck loads', async () => {
      vi.mocked(api.decks.get).mockReturnValue(new Promise(() => {}))
      const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/decks', name: 'decks', component: { template: '<div />' } }] })
      const wrapper = mount(DeckEditView, { props: { slug: 'deck-1-abc' }, global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.get('[data-testid=spinner]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-testid=rename-form]').exists()).toBe(false)
    })

    it('shows the error state for a deck that is gone, with a way back to the list and a retry', async () => {
      vi.mocked(api.decks.get)
        .mockRejectedValueOnce(new ApiClientError('not_found', 404, 'deck_not_found', 'x'))
        .mockResolvedValueOnce(detail())
      const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/decks', name: 'decks', component: { template: '<div />' } }] })
      const wrapper = mount(DeckEditView, { props: { slug: 'gone' }, global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.get('[data-testid=error]').text()).toContain('Колода не найдена.')
      const back = wrapper.get('[data-testid=back-link]')
      expect(back.attributes('href')).toBe('/decks')
      expect(back.text()).toBe('К колодам')
      expect(back.classes()).toContain('btn-tinted')

      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.get('h1').text()).toBe('Мои слова')
    })
  })

  describe('navigation', () => {
    it('has no text link back: "Назад" is the system button, and it leads to my decks', async () => {
      const { wrapper, router } = await render()

      expect(wrapper.find('[data-testid=back-link]').exists()).toBe(false)
      expect(tg.showBack).toHaveBeenCalledTimes(1)
      expect(tg.hideBack).not.toHaveBeenCalled()

      tg.handler?.()
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('decks')
    })

    it('releases the system button when the screen is left', async () => {
      const { wrapper } = await render()

      wrapper.unmount()

      expect(tg.release).toHaveBeenCalledTimes(1)
    })
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    vi.mocked(api.decks.addWords).mockResolvedValue(importResult())
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid=add-btn]').text()).toBe("Qo'shish")
    expect(wrapper.get('[data-testid=delete-btn]').text()).toBe("To'plamni o'chirish")
    expect(wrapper.get('[data-testid=choose-btn]').text()).toBe('Asosiy qilish')
    expect(wrapper.get('[data-testid=words] .section-label').text()).toBe("So'zlar (2)")
    expect(wrapper.get('[data-testid=import] [role=radiogroup]').attributes('aria-label')).toBe('Format')
    expect(wrapper.get('[data-testid=remove-word]').attributes('aria-label')).toBe('Olib tashlash')
  })

  it('renders the main deck chip in Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(detail({ is_active: true }))

    expect(wrapper.get('[data-testid=active-badge]').text()).toBe("Asosiy to'plam")
  })
})
