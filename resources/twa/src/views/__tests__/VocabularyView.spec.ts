import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import VocabularyView from '../VocabularyView.vue'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { KIT_WORDS, vocabularyItem, vocabularyPage } from '@/api/__tests__/vocabularyFixtures'
import { offlineContext } from '@/composables/useOnline'
import { setLocale } from '@/i18n'
import { SEARCH_DEBOUNCE_MS } from '@/stores/vocabulary'
import type { VocabularyPage } from '@/api/vocabularyTypes'

/*
 * "My dictionary" (docs/40 §2.3, kit F2-moy-slovar): the heading with the number of words, the search, the chips with their numbers, the
 * rows (the word, the translation, the dots, the speaker), the next page on scrolling, the states (skeleton, empty dictionary, nothing
 * found, error with "Retry"), the system "Back" to the course. The requests are counted: one to open, one per page, one per search
 * (after the pause) or filter, none when the screen is left.
 */

vi.mock('@/api', () => ({ api: { vocabulary: { list: vi.fn() } } }))

const auth = vi.hoisted(() => ({ ensure: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ensure: auth.ensure }) }))

const tg = vi.hoisted(() => ({ back: null as null | (() => void), selectionHaptic: vi.fn() }))
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    webApp: { BackButton: { hide: () => {} } },
    selectionHaptic: tg.selectionHaptic,
    showBackButton: (handler: () => void) => {
      tg.back = handler
      return () => {
        if (tg.back === handler) tg.back = null
      }
    },
  }),
}))

const tts = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({ useTts: () => ({ speak: tts.speak }) }))

/** A controllable `IntersectionObserver`: `scrollToEnd()` tells every observed element it is in view. */
const io = vi.hoisted(() => ({ observers: [] as { callback: IntersectionObserverCallback; targets: Element[] }[] }))
class FakeObserver {
  private entry: { callback: IntersectionObserverCallback; targets: Element[] }
  constructor(callback: IntersectionObserverCallback) {
    this.entry = { callback, targets: [] }
    io.observers.push(this.entry)
  }
  observe(target: Element): void {
    this.entry.targets.push(target)
  }
  disconnect(): void {
    this.entry.targets = []
  }
  unobserve(): void {}
  takeRecords(): IntersectionObserverEntry[] {
    return []
  }
}
function scrollToEnd(): void {
  for (const { callback, targets } of io.observers) {
    if (targets.length > 0) callback(targets.map((target) => ({ isIntersecting: true, target }) as IntersectionObserverEntry), {} as IntersectionObserver)
  }
}

const list = () => vi.mocked(api.vocabulary.list)
const stub = defineComponent({ render: () => h('div', { 'data-testid': 'course' }) })

async function render(path = '/vocabulary?from=english-b1', first: VocabularyPage | null = vocabularyPage(KIT_WORDS, 'c1')) {
  if (first !== null) list().mockResolvedValueOnce(first)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/course', name: 'my-course', component: stub },
      { path: '/courses/:slug', name: 'course', component: stub },
      { path: '/vocabulary', name: 'vocabulary', component: VocabularyView },
    ],
  })
  await router.push('/courses/english-b1')
  await router.push(path)
  const wrapper = mount(defineComponent({ render: () => h(RouterView) }), { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  setActivePinia(createPinia())
  list().mockReset()
  auth.ensure.mockReset().mockResolvedValue(undefined)
  tts.speak.mockClear()
  tg.back = null
  io.observers = []
  vi.stubGlobal('IntersectionObserver', FakeObserver)
})
afterEach(() => {
  setLocale('ru')
  vi.useRealTimers()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('VocabularyView', () => {
  it('opens with one request and draws the screen of the kit: heading, number, search, chips with numbers, rows', async () => {
    const { wrapper } = await render()

    expect(list()).toHaveBeenCalledTimes(1)
    expect(list().mock.calls[0]?.[0]).toEqual({ filter: 'all' })
    expect(wrapper.get('h1').text()).toBe('Мой словарь')
    expect(wrapper.get('[data-testid=vocabulary-total]').text()).toBe('120 слов')

    const search = wrapper.get('[data-testid=vocabulary-search]')
    expect(search.classes()).toContain('field')
    expect(search.attributes('placeholder')).toBe('Найти слово или перевод')
    expect(search.attributes('maxlength')).toBe('50')

    expect(wrapper.get('[data-testid=filter-all]').text()).toBe('Все · 120')
    expect(wrapper.get('[data-testid=filter-weak]').text()).toBe('Слабые · 18')
    expect(wrapper.get('[data-testid=filter-strong]').text()).toBe('Сильные · 64')
    expect(wrapper.get('[data-testid=filter-all]').classes()).toEqual(expect.arrayContaining(['chip-ink', 'h-8']))
    expect(wrapper.get('[data-testid=filter-all]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid=filter-weak]').attributes('aria-pressed')).toBe('false')

    const rows = wrapper.findAll('[data-testid=vocabulary-row]')
    expect(rows).toHaveLength(7)
    expect(rows[0]!.get('[data-testid=vocabulary-word]').text()).toBe('tea')
    expect(rows[0]!.get('[data-testid=vocabulary-word]').attributes('lang')).toBe('en')
    expect(rows[0]!.get('[data-testid=vocabulary-translation]').text()).toBe('чай')
    expect(rows[0]!.get('[data-testid=memory-dots]').attributes('aria-label')).toBe('Память: 5 из 5')
    // milk: strength 1 - the danger colour.
    expect(rows[4]!.findAll('[data-filled=true]').every((dot) => dot.classes('bg-danger'))).toBe(true)
    expect(rows[0]!.get('[data-testid=vocabulary-audio]').attributes('aria-label')).toBe('Прослушать: tea')
  })

  it('shows the skeleton of seven rows while the first page is on its way', async () => {
    let answer: (page: VocabularyPage) => void = () => {}
    list().mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)))
    const { wrapper } = await render('/vocabulary', null)

    expect(wrapper.get('[data-testid=spinner]').attributes('aria-busy')).toBe('true')
    expect(wrapper.findAll('[data-testid=skeleton-row]')).toHaveLength(7)

    answer(vocabularyPage(KIT_WORDS))
    await flushPromises()
    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
  })

  it('sounds the word with its audio_url', async () => {
    const { wrapper } = await render()
    await wrapper.findAll('[data-testid=vocabulary-audio]')[1]!.trigger('click')
    expect(tts.speak).toHaveBeenCalledWith('coffee', 'en-US', 'https://cdn.test/audio/coffee.mp3')
    expect(list()).toHaveBeenCalledTimes(1)
  })

  it('searches once per burst of typing, after the pause', async () => {
    const { wrapper } = await render()
    vi.useFakeTimers()
    list().mockResolvedValueOnce(vocabularyPage([KIT_WORDS[0]!, KIT_WORDS[5]!]))

    const field = wrapper.get('[data-testid=vocabulary-search]')
    for (const value of ['t', 'te', 'tea', 'tea ']) await field.setValue(value)
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS - 1)
    expect(list()).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'all', q: 'tea' })
    expect(wrapper.findAll('[data-testid=vocabulary-word]').map((w) => w.text())).toEqual(['tea', 'kettle'])
    // The numbers of the chips do not depend on the search.
    expect(wrapper.get('[data-testid=filter-all]').text()).toBe('Все · 120')
  })

  it('switches the filter with one request; the chosen chip is ink, and the same chip again sends nothing', async () => {
    const { wrapper } = await render()
    list().mockResolvedValueOnce(vocabularyPage([KIT_WORDS[2]!, KIT_WORDS[4]!]))

    await wrapper.get('[data-testid=filter-weak]').trigger('click')
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'weak' })
    expect(wrapper.get('[data-testid=filter-weak]').classes()).toContain('chip-ink')
    expect(wrapper.get('[data-testid=filter-weak]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid=filter-all]').classes()).toContain('chip-neutral')
    expect(wrapper.findAll('[data-testid=vocabulary-word]').map((w) => w.text())).toEqual(['juice', 'milk'])

    await wrapper.get('[data-testid=filter-weak]').trigger('click')
    await flushPromises()
    expect(list()).toHaveBeenCalledTimes(2)
  })

  it('loads the next page when the end of the list comes into view: one request per page, none while one is on its way, none after the last', async () => {
    const { wrapper } = await render('/vocabulary', vocabularyPage(KIT_WORDS.slice(0, 4), 'c1'))
    let answer: (page: VocabularyPage) => void = () => {}
    list().mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)))

    scrollToEnd()
    scrollToEnd()
    await flushPromises()
    expect(list()).toHaveBeenCalledTimes(2)
    expect(list().mock.calls[1]?.[0]).toEqual({ filter: 'all', after: 'c1' })
    expect(wrapper.get('[data-testid=more-btn]').attributes('disabled')).toBeDefined()

    answer(vocabularyPage([KIT_WORDS[3]!, ...KIT_WORDS.slice(4)], null, false))
    await flushPromises()
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
    expect(wrapper.find('[data-testid=vocabulary-more]').exists()).toBe(false)

    scrollToEnd()
    await flushPromises()
    expect(list()).toHaveBeenCalledTimes(2)
  })

  it('"Show more" loads the next page too (no IntersectionObserver)', async () => {
    vi.stubGlobal('IntersectionObserver', undefined)
    const { wrapper } = await render('/vocabulary', vocabularyPage(KIT_WORDS.slice(0, 2), 'c1'))
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(2), null, false))

    expect(wrapper.get('[data-testid=more-btn]').text()).toBe('Показать ещё')
    await wrapper.get('[data-testid=more-btn]').trigger('click')
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
  })

  it('an empty dictionary: the mascot reading and «Пройдите первый урок», no search and no chips', async () => {
    const { wrapper } = await render('/vocabulary', vocabularyPage([], null, true, { all: 0, weak: 0, strong: 0 }))

    const empty = wrapper.get('[data-testid=vocabulary-empty]')
    expect(empty.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('reading')
    expect(empty.text()).toContain('Пройдите первый урок — слова появятся здесь')
    expect(wrapper.find('[data-testid=vocabulary-search]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=vocabulary-filters]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=vocabulary-total]').exists()).toBe(false)
  })

  it('a search that found nothing says so and keeps the field', async () => {
    const { wrapper } = await render()
    vi.useFakeTimers()
    list().mockResolvedValueOnce(vocabularyPage([]))

    await wrapper.get('[data-testid=vocabulary-search]').setValue('qwerty')
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS)
    await flushPromises()

    expect(wrapper.get('[data-testid=vocabulary-not-found]').text()).toContain('Ничего не нашли')
    expect(wrapper.find('[data-testid=vocabulary-search]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=vocabulary-empty]').exists()).toBe(false)
  })

  it('a filter without words says the group is empty', async () => {
    const { wrapper } = await render()
    list().mockResolvedValueOnce(vocabularyPage([]))
    await wrapper.get('[data-testid=filter-strong]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid=vocabulary-not-found]').text()).toContain('Здесь пока нет слов')
  })

  it('a failed first page: the error with "Retry", one request per press', async () => {
    list().mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
    const { wrapper } = await render('/vocabulary', null)

    expect(wrapper.find('[data-testid=error]').exists()).toBe(true)
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS))
    await wrapper.get('[data-testid=retry-btn]').trigger('click')
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
  })

  it('a failed next page keeps the list; "Retry" asks for that page', async () => {
    const { wrapper } = await render('/vocabulary', vocabularyPage(KIT_WORDS.slice(0, 3), 'c1'))
    list().mockRejectedValueOnce(new ApiClientError('server', 500, 'server_error', 'boom'))
    scrollToEnd()
    await flushPromises()

    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(3)
    expect(wrapper.find('[data-testid=more-error]').exists()).toBe(true)
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(3), null, false))
    await wrapper.get('[data-testid=more-btn]').trigger('click')
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(3)
    expect(list().mock.calls[2]?.[0]).toEqual({ filter: 'all', after: 'c1' })
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
  })

  it('tells the screen "Нет соединения" what to retry: the lost page, not the whole screen', async () => {
    const { wrapper } = await render('/vocabulary', vocabularyPage(KIT_WORDS.slice(0, 3), 'c1'))
    list().mockRejectedValueOnce(new ApiClientError('network', 0, 'network', 'fetch failed'))
    scrollToEnd()
    await flushPromises()

    expect(offlineContext.value).not.toBeNull()
    expect(offlineContext.value?.pendingAnswer()).toBe(false)
    list().mockResolvedValueOnce(vocabularyPage(KIT_WORDS.slice(3), null, false))
    offlineContext.value?.retry()
    await flushPromises()

    expect(list()).toHaveBeenCalledTimes(3)
    expect(wrapper.findAll('[data-testid=vocabulary-row]')).toHaveLength(7)
    wrapper.unmount()
    expect(offlineContext.value).toBeNull()
  })

  it('the system "Back" returns to the course it came from (history back), and leaving sends nothing more', async () => {
    const { wrapper, router } = await render()
    vi.useFakeTimers()
    await wrapper.get('[data-testid=vocabulary-search]').setValue('tea')

    expect(tg.back).not.toBeNull()
    tg.back?.()
    await vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS * 2)
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/courses/english-b1')
    expect(list()).toHaveBeenCalledTimes(1)
  })

  it('without `from` "Back" leads to the Course tab', async () => {
    const { router } = await render('/vocabulary')
    tg.back?.()
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('my-course')
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const { wrapper } = await render('/vocabulary', vocabularyPage([vocabularyItem(1, 'tea', 'choy', 5)], null, true, { all: 1, weak: 0, strong: 1 }))

    expect(wrapper.get('h1').text()).toBe("Mening lug'atim")
    expect(wrapper.get('[data-testid=vocabulary-total]').text()).toBe("1 ta so'z")
    expect(wrapper.get('[data-testid=filter-weak]').text()).toBe('Zaif · 0')
    expect(wrapper.get('[data-testid=memory-dots]').attributes('aria-label')).toBe('Xotira: 5 dan 5')
  })
})
