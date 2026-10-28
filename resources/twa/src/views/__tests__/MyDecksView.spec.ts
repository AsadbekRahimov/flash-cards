import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import MyDecksView from '../MyDecksView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useToast } from '@/composables/useToast'
import { useTodayStore } from '@/stores/today'
import type { Course, Deck, DecksResponse } from '@/api/types'

const tg = vi.hoisted(() => ({ showBack: vi.fn(), hideBack: vi.fn() }))

vi.mock('@/api', () => ({
  api: {
    me: { get: vi.fn().mockResolvedValue(null) },
    decks: { list: vi.fn(), create: vi.fn() },
    courses: { select: vi.fn() },
    training: { open: vi.fn() },
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ensure: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({ showBackButton: tg.showBack, webApp: { BackButton: { hide: tg.hideBack } } }),
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

const selected = (overrides: Partial<Course> = {}): Course => ({
  slug: 'deck-1-abc',
  title: 'Мои слова',
  description: null,
  level: null,
  exam_tag: null,
  words_count: 3,
  is_featured: false,
  is_active: true,
  progress: { done: 1, total: 3 },
  ...overrides,
})

function makeRouter() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/courses', name: 'courses', component: stub },
      { path: '/decks', name: 'decks', component: stub },
      { path: '/decks/:slug', name: 'deck', component: stub },
      { path: '/training/:sessionId(\\d+)', name: 'training', component: stub },
    ],
  })
}

async function render(data: DecksResponse = response()) {
  vi.mocked(api.decks.list).mockResolvedValue(data)
  const router = makeRouter()
  await router.push('/decks')
  const wrapper = mount(MyDecksView, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('MyDecksView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    useToast().hide()
  })
  afterEach(() => {
    useToast().hide()
    setLocale('ru')
  })

  describe('heading and switch', () => {
    it('has the large "Курсы" heading, the switch with "Мои колоды" active and the intro', async () => {
      const { wrapper } = await render()

      const heading = wrapper.get('h1')
      expect(heading.text()).toBe('Курсы')
      expect(heading.classes()).toContain('text-[28px]')

      const decks = wrapper.get('[data-testid=seg-decks]')
      expect(decks.text()).toBe('Мои колоды')
      expect(decks.attributes('aria-current')).toBe('page')
      const courses = wrapper.get('[data-testid=seg-courses]')
      expect(courses.text()).toBe('Курсы')
      expect(courses.attributes('href')).toBe('/courses')
      expect(courses.attributes('aria-current')).toBeUndefined()

      expect(wrapper.get('[data-testid=intro]').text()).toBe(
        'Создайте свою колоду и добавьте слова списком или из CSV. Колоду видите только вы.',
      )
    })

    it('goes to the courses from the switch', async () => {
      const { wrapper, router } = await render()

      await wrapper.get('[data-testid=seg-courses]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('courses')
    })
  })

  describe('create form', () => {
    it('keeps the field and the primary "Создать" button in one card and shows the limits under it', async () => {
      const { wrapper } = await render()

      const form = wrapper.get('[data-testid=create-form]')
      expect(form.classes()).toContain('card')
      const input = form.get('[data-testid=title-input]')
      expect(input.classes()).toContain('field')
      expect(input.attributes('placeholder')).toBe('Название колоды')
      const submit = form.get('[data-testid=create-btn]')
      expect(submit.text()).toBe('Создать')
      expect(submit.classes()).toContain('btn-primary')
      expect(submit.attributes('type')).toBe('submit')

      expect(wrapper.get('[data-testid=limits]').text()).toBe('Колод: 1 из 1 · до 50 слов в колоде')
    })

    it('has exactly one primary button, the create one', async () => {
      const { wrapper } = await render(response([deck(), deck({ slug: 'second', title: 'Вторая' })]))

      expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
      expect(wrapper.get('.btn-primary').attributes('data-testid')).toBe('create-btn')
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

      const alert = wrapper.get('[data-testid=write-error]')
      expect(alert.text()).toBe('Достигнут лимит колод вашего тарифа.')
      expect(alert.attributes('role')).toBe('alert')
      expect(wrapper.get('[data-testid=paywall-lead]').text()).toContain('1 колода до 50 слов')
      expect(router.currentRoute.value.fullPath).toBe('/decks')

      await wrapper.get('[data-testid=paywall-close]').trigger('click')
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
    })
  })

  describe('"Повторить слова" in the paywall', () => {
    /** The deck limit refuses a new deck while the Home tab knows of due cards: the sheet offers the review. */
    async function openPaywall() {
      useTodayStore().data = { due_count: 12 } as never
      vi.mocked(api.decks.create).mockRejectedValue(new ApiClientError('validation', 422, 'deck_limit_reached', 'x'))
      const context = await render()
      await context.wrapper.get('[data-testid=title-input]').setValue('Вторая')
      await context.wrapper.get('[data-testid=create-form]').trigger('submit')
      await flushPromises()
      return context
    }

    it('starts the daily review: the sheet closes and the training screen opens', async () => {
      vi.mocked(api.training.open).mockResolvedValue({ session_id: 61, mode: 'daily_review', lesson: null, total_words: 5 } as never)
      const { wrapper, router } = await openPaywall()
      expect(wrapper.get('[data-testid=paywall-review]').text()).toBe('Повторить слова')

      await wrapper.get('[data-testid=paywall-review]').trigger('click')
      await flushPromises()

      expect(api.training.open).toHaveBeenCalledWith({ mode: 'daily_review', source: 'self' })
      expect(wrapper.find('[data-testid=paywall]').exists()).toBe(false)
      expect(router.currentRoute.value.name).toBe('training')
      expect(router.currentRoute.value.params.sessionId).toBe('61')
    })

    it('tells in a toast why the review did not open and stays on the decks', async () => {
      vi.mocked(api.training.open).mockRejectedValue(new ApiClientError('network', 0, 'unknown', 'x'))
      const { wrapper, router } = await openPaywall()

      await wrapper.get('[data-testid=paywall-review]').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.name).toBe('decks')
      expect(useToast().toast.value?.text).toBe('Нет связи с сервером. Попробуйте ещё раз.')
    })
  })

  describe('deck cards', () => {
    it('lists the decks as cards with a words chip, progress and a link', async () => {
      const { wrapper } = await render()

      const card = wrapper.get('[data-testid="deck-deck-1-abc"]')
      expect(card.classes()).toContain('card')
      expect(card.get('h2').text()).toBe('Мои слова')
      const meta = card.get('[data-testid=meta]')
      expect(meta.text()).toBe('3 слова')
      expect(meta.get('.chip').classes()).toContain('chip-neutral')
      expect(card.get('[data-testid=progress-text]').text()).toBe('Изучено 1 из 3')
      expect(card.get('[data-testid=progress-bar]').attributes('aria-valuenow')).toBe('33')
      expect(card.get('[data-testid=progress-fill]').attributes('style')).toContain('width: 33%')

      const link = card.get('[data-testid=deck-link]')
      expect(link.element.tagName).toBe('A')
      expect(link.attributes('href')).toBe('/decks/deck-1-abc')
      expect(link.find('svg').exists()).toBe(true)
    })

    it('inflects the words chip by the count', async () => {
      const { wrapper } = await render(response([
        deck({ slug: 'one', words_count: 1 }),
        deck({ slug: 'five', words_count: 5 }),
        deck({ slug: 'zero', words_count: 0 }),
      ]))

      expect(wrapper.get('[data-testid=deck-one] [data-testid=meta]').text()).toBe('1 слово')
      expect(wrapper.get('[data-testid=deck-five] [data-testid=meta]').text()).toBe('5 слов')
      expect(wrapper.get('[data-testid=deck-zero] [data-testid=meta]').text()).toBe('0 слов')
    })

    it('offers "Сделать основной" as a tinted block button and a chip for the main deck', async () => {
      const { wrapper } = await render(response([deck({ is_active: true }), deck({ slug: 'other', title: 'Другая' })]))

      const choose = wrapper.get('[data-testid=deck-other] [data-testid=choose-btn]')
      expect(choose.text()).toBe('Сделать основной')
      expect(choose.classes()).toEqual(expect.arrayContaining(['btn', 'btn-tinted', 'btn-block']))

      const active = wrapper.get('[data-testid="deck-deck-1-abc"]')
      const badge = active.get('[data-testid=active-badge]')
      expect(badge.text()).toBe('Основная колода')
      expect(badge.classes()).toEqual(expect.arrayContaining(['chip', 'chip-success']))
      expect(badge.find('svg').exists()).toBe(true)
      expect(active.find('[data-testid=choose-btn]').exists()).toBe(false)
    })

    it('chooses a deck as the main one; an empty deck cannot be chosen', async () => {
      vi.mocked(api.courses.select).mockResolvedValue(selected())
      const { wrapper } = await render(response([deck(), deck({ slug: 'empty', words_count: 0 })], 1))

      expect(wrapper.get('[data-testid="deck-empty"] [data-testid=choose-btn]').attributes('disabled')).toBeDefined()

      await wrapper.get('[data-testid="deck-deck-1-abc"] [data-testid=choose-btn]').trigger('click')
      await flushPromises()

      expect(api.courses.select).toHaveBeenCalledWith('deck-1-abc')
      expect(wrapper.get('[data-testid="deck-deck-1-abc"] [data-testid=active-badge]').text()).toBe('Основная колода')
      expect(wrapper.find('[data-testid="deck-deck-1-abc"] [data-testid=choose-btn]').exists()).toBe(false)
    })

    it('disables the choose buttons while a write is in flight', async () => {
      vi.mocked(api.courses.select).mockReturnValue(new Promise(() => {}))
      const { wrapper } = await render(response([deck(), deck({ slug: 'second', title: 'Вторая' })]))
      const buttons = () => wrapper.findAll('[data-testid=choose-btn]')
      expect(buttons().map((b) => b.attributes('disabled'))).toEqual([undefined, undefined])

      await buttons()[0]?.trigger('click')

      expect(buttons().map((b) => b.attributes('disabled') !== undefined)).toEqual([true, true])
    })

    it('breaks a very long title instead of overflowing', async () => {
      const { wrapper } = await render(response([deck({ title: 'Очень-длинное-название-колоды-без-пробелов'.repeat(3) })]))

      expect(wrapper.get('[data-testid="deck-deck-1-abc"] h2').classes()).toContain('break-words')
    })
  })

  describe('states', () => {
    it('shows the empty state with an icon and keeps the form', async () => {
      const { wrapper } = await render(response([], 0))

      const empty = wrapper.get('[data-testid=empty]')
      expect(empty.text()).toBe('У вас пока нет колод.')
      expect(empty.find('svg').exists()).toBe(true)
      expect(wrapper.find('[data-testid^="deck-deck"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid=create-form]').exists()).toBe(true)
      expect(wrapper.get('[data-testid=limits]').text()).toBe('Колод: 0 из 1 · до 50 слов в колоде')
    })

    it('shows a skeleton while the decks load', async () => {
      vi.mocked(api.decks.list).mockReturnValue(new Promise(() => {}))
      const wrapper = mount(MyDecksView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      expect(wrapper.get('[data-testid=spinner]').attributes('role')).toBe('status')
      expect(wrapper.find('[data-testid=create-form]').exists()).toBe(false)
      expect(wrapper.find('h1').exists()).toBe(true)
    })

    it('retries after a failed load', async () => {
      vi.mocked(api.decks.list).mockRejectedValueOnce(new ApiClientError('network', 0, 'unknown', 'x'))
      const wrapper = mount(MyDecksView, { global: { plugins: [makeRouter()] } })
      await flushPromises()

      expect(wrapper.get('[data-testid=error]').text()).toContain('Нет связи с сервером')
      // The heading and the switch stay: the learner can still go to the courses.
      expect(wrapper.find('[data-testid=seg-courses]').exists()).toBe(true)

      vi.mocked(api.decks.list).mockResolvedValue(response())
      await wrapper.get('[data-testid=retry-btn]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid=error]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="deck-deck-1-abc"]').exists()).toBe(true)
    })
  })

  describe('navigation', () => {
    it('is a tab root: the system Back button is hidden, never shown', async () => {
      const { wrapper } = await render()

      expect(tg.hideBack).toHaveBeenCalledTimes(1)
      expect(tg.showBack).not.toHaveBeenCalled()
      expect(wrapper.find('[data-testid=back-link]').exists()).toBe(false)
    })
  })

  it('renders in Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render(response([deck(), deck({ slug: 'main', is_active: true })], 1))

    expect(wrapper.get('h1').text()).toBe('Kurslar')
    expect(wrapper.get('[data-testid=seg-decks]').text()).toBe("Mening to'plamlarim")
    expect(wrapper.get('[data-testid=create-btn]').text()).toBe('Yaratish')
    expect(wrapper.get('[data-testid=limits]').text()).toBe("To'plamlar: 1 tadan 1 tasi · to'plamda 50 tagacha so'z")
    expect(wrapper.get('[data-testid="deck-deck-1-abc"] [data-testid=choose-btn]').text()).toBe('Asosiy qilish')
    expect(wrapper.get('[data-testid=deck-main] [data-testid=active-badge]').text()).toBe("Asosiy to'plam")
  })
})
