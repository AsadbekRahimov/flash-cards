import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ModeCard from '../ModeCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { setLocale } from '@/i18n'
import { activeSession } from '@/api/__tests__/practiceFixtures'
import type { PracticeMode, PracticeModeInfo } from '@/api/types'

const info = (mode: PracticeMode, overrides: Partial<PracticeModeInfo> = {}): PracticeModeInfo => ({
  mode,
  state: 'available',
  available: 14,
  required: 3,
  reason: null,
  estimate_seconds: 120,
  ...overrides,
})

const render = (props: InstanceType<typeof ModeCard>['$props']) => mount(ModeCard, { props })

afterEach(() => setLocale('ru'))

describe('ModeCard — a card that can be played', () => {
  it('is one button, named by what it is: the title, the example and the length', () => {
    const wrapper = render({ info: info('pairs') })

    const card = wrapper.get('[data-testid=mode-card-pairs]')
    expect(card.element.tagName).toBe('BUTTON')
    expect(card.attributes('type')).toBe('button')
    expect(card.attributes('aria-label')).toBe('Найди пары. mother ↔ мама. ≈ 2 мин')
    expect(wrapper.get('[data-testid=mode-title]').text()).toBe('Найди пары')
    expect(wrapper.get('[data-testid=mode-example]').text()).toBe('mother ↔ мама')
    expect(wrapper.get('[data-testid=mode-estimate]').text()).toBe('≈ 2 мин')
  })

  it('has no button inside the button, in any state', () => {
    for (const state of ['available', 'not_enough', 'disabled', 'pro_required'] as const) {
      const wrapper = render({ info: info('sentence', { state, reason: state === 'not_enough' ? 'no_items' : null }), learn: 'lesson' })
      expect(wrapper.find('button button').exists(), state).toBe(false)
    }
  })

  it('says select with its mode when pressed', async () => {
    const wrapper = render({ info: info('dictation') })

    await wrapper.get('[data-testid=mode-card-dictation]').trigger('click')

    expect(wrapper.emitted('select')).toEqual([['dictation']])
  })

  it('rounds the length to whole minutes, never under one', () => {
    expect(render({ info: info('pairs', { estimate_seconds: 30 }) }).get('[data-testid=mode-estimate]').text()).toBe('≈ 1 мин')
    expect(render({ info: info('pairs', { estimate_seconds: 90 }) }).get('[data-testid=mode-estimate]').text()).toBe('≈ 2 мин')
    expect(render({ info: info('pairs', { estimate_seconds: 300 }) }).get('[data-testid=mode-estimate]').text()).toBe('≈ 5 мин')
  })

  it('draws the icon tile of its mode', () => {
    const tiles: Record<PracticeMode, string> = { pairs: 'tile-blue', collocation: 'tile-purple', sentence: 'tile-orange', dictation: 'tile-teal' }

    for (const mode of Object.keys(tiles) as PracticeMode[]) {
      const tile = render({ info: info(mode) }).get('.icon-tile')
      expect(tile.classes(), mode).toContain(tiles[mode])
      expect(tile.findComponent(AppIcon).exists()).toBe(true)
    }
  })

  it('has the name of the mode in the language of the learner', () => {
    setLocale('uz')

    const wrapper = render({ info: info('collocation') })

    expect(wrapper.get('[data-testid=mode-title]').text()).toBe("So'z birikmasini tanlang")
    expect(wrapper.get('[data-testid=mode-estimate]').text()).toBe('≈ 2 daq.')
  })
})

describe('ModeCard — "Continue"', () => {
  it('says so, in the chip and for a screen reader, with the progress', () => {
    const wrapper = render({ info: info('pairs'), resume: activeSession({ progress: { done: 1, total: 3 } }) })

    expect(wrapper.get('[data-testid=mode-chip]').text()).toBe('Продолжить')
    expect(wrapper.get('[data-testid=mode-card-pairs]').attributes('aria-label')).toBe('Продолжить: Найди пары, выполнено 1 из 3')
    expect(wrapper.get('[data-testid=mode-card-pairs]').attributes('data-state')).toBe('resume')
  })

  it('stays pressable even when the mode is short of material now', async () => {
    const wrapper = render({ info: info('pairs', { state: 'not_enough', reason: 'no_words' }), resume: activeSession() })

    expect(wrapper.get('[data-testid=mode-card-pairs]').element.tagName).toBe('BUTTON')
    await wrapper.get('[data-testid=mode-card-pairs]').trigger('click')
    expect(wrapper.emitted('select')).toEqual([['pairs']])
    expect(wrapper.find('[data-testid=mode-reason]').exists()).toBe(false)
  })
})

describe('ModeCard — behind Pro', () => {
  it('is a button with a Pro chip: the screen answers with the paywall', async () => {
    const wrapper = render({ info: info('dictation', { state: 'pro_required' }) })

    const card = wrapper.get('[data-testid=mode-card-dictation]')
    expect(card.element.tagName).toBe('BUTTON')
    expect(card.attributes('aria-label')).toBe('Слушай и пиши. Доступно в Pro')
    expect(wrapper.get('[data-testid=mode-chip]').text()).toBe('Pro')

    await card.trigger('click')
    expect(wrapper.emitted('select')).toEqual([['dictation']])
  })
})

describe('ModeCard — short of material', () => {
  it('is a block, not a button, and says what is missing in numbers', async () => {
    const wrapper = render({ info: info('pairs', { state: 'not_enough', available: 1, required: 3, reason: 'no_words' }), learn: 'lesson' })

    const card = wrapper.get('[data-testid=mode-card-pairs]')
    expect(card.element.tagName).toBe('DIV')
    expect(card.attributes('aria-label')).toBeUndefined()
    expect(wrapper.get('[data-testid=mode-chip]').text()).toBe('Мало материала')
    expect(wrapper.get('[data-testid=mode-reason]').text()).toBe('Изучено слов: 1 из 3. Пройдите урок, и режим откроется.')

    await card.trigger('click')
    expect(wrapper.emitted('select')).toBeUndefined()
  })

  it.each([
    ['no_words', 'Изучено слов: 2 из 3. Пройдите урок, и режим откроется.'],
    ['ambiguous', 'Подходящих слов: 2 из 3. Выучите новые слова, и режим откроется.'],
    ['no_items', 'Для этой темы заданий пока нет.'],
    ['items_locked', 'Выучите слова урока — задания откроются.'],
    ['audio_pending', 'Записи для заданий ещё готовятся. Загляните позже.'],
    ['no_audio_words', 'Для ваших слов пока нет записей. Загляните позже.'],
  ] as const)('explains %s', (reason, text) => {
    const wrapper = render({ info: info('pairs', { state: 'not_enough', available: 2, required: 3, reason }) })

    expect(wrapper.get('[data-testid=mode-reason]').text()).toBe(text)
  })

  // The server tells "there are no tasks for this topic" (`no_items`) from "there are tasks, and they open when the words of the lesson
  // are met" (`items_locked`). The first promises nothing and offers nothing to learn; the second says what to do and offers it.
  describe('no tasks at all, and tasks that wait for the words', () => {
    it('no_items says that there are none, with no promise, no number and no "Learn the lesson"', () => {
      for (const learn of ['lesson', 'course'] as const) {
        const wrapper = render({ info: info('collocation', { state: 'not_enough', available: 0, required: 3, reason: 'no_items' }), learn })

        expect(wrapper.get('[data-testid=mode-reason]').text()).toBe('Для этой темы заданий пока нет.')
        expect(wrapper.get('[data-testid=mode-reason]').text()).not.toMatch(/\d|появятся|выучите/i)
        expect(wrapper.find('[data-testid=mode-learn-collocation]').exists()).toBe(false)
        expect(wrapper.find('button').exists()).toBe(false) // a block with nothing to press
      }
    })

    it('items_locked says what to do, and offers it: "Learn the lesson" in a lesson, "To the lessons" in a course', async () => {
      const lesson = render({ info: info('sentence', { state: 'not_enough', available: 0, required: 3, reason: 'items_locked' }), learn: 'lesson' })
      const course = render({ info: info('sentence', { state: 'not_enough', available: 0, required: 3, reason: 'items_locked' }), learn: 'course' })

      expect(lesson.get('[data-testid=mode-reason]').text()).toBe('Выучите слова урока — задания откроются.')
      expect(lesson.get('[data-testid=mode-learn-sentence]').text()).toBe('Учить урок')
      expect(course.get('[data-testid=mode-learn-sentence]').text()).toBe('К урокам')
      await lesson.get('[data-testid=mode-learn-sentence]').trigger('click') // the same action as for `no_words`
      expect(lesson.emitted('learn')).toHaveLength(1)
    })

    it('says the same in Uzbek', () => {
      setLocale('uz')
      const none = render({ info: info('collocation', { state: 'not_enough', reason: 'no_items' }), learn: 'lesson' })
      const locked = render({ info: info('collocation', { state: 'not_enough', reason: 'items_locked' }), learn: 'lesson' })

      expect(none.get('[data-testid=mode-reason]').text()).toBe("Bu mavzu bo'yicha topshiriqlar hozircha yo'q.")
      expect(none.find('[data-testid=mode-learn-collocation]').exists()).toBe(false)
      expect(locked.get('[data-testid=mode-reason]').text()).toBe("Dars so'zlarini o'rganing — topshiriqlar ochiladi.")
      expect(locked.get('[data-testid=mode-learn-collocation]').text()).toBe("Darsni o'rganish")
    })
  })

  it('has a plain sentence when the server named no reason', () => {
    const wrapper = render({ info: info('pairs', { state: 'not_enough', reason: null }) })

    expect(wrapper.get('[data-testid=mode-reason]').text()).toBe('Пока не хватает материала.')
  })

  it('offers "Learn the lesson" in a lesson and "To the lessons" in a course; the button says learn', async () => {
    const lesson = render({ info: info('pairs', { state: 'not_enough', reason: 'no_words' }), learn: 'lesson' })
    const course = render({ info: info('pairs', { state: 'not_enough', reason: 'no_words' }), learn: 'course' })

    expect(lesson.get('[data-testid=mode-learn-pairs]').text()).toBe('Учить урок')
    expect(course.get('[data-testid=mode-learn-pairs]').text()).toBe('К урокам')
    await lesson.get('[data-testid=mode-learn-pairs]').trigger('click')
    expect(lesson.emitted('learn')).toHaveLength(1)
  })

  it('offers no learning when learning would not help (the recordings are still being made, or there are no tasks at all)', () => {
    for (const reason of ['audio_pending', 'no_audio_words', 'no_items'] as const) {
      const wrapper = render({ info: info('dictation', { state: 'not_enough', reason }), learn: 'lesson' })
      expect(wrapper.find('[data-testid=mode-learn-dictation]').exists(), reason).toBe(false)
    }
  })

  it('offers nothing to learn when the screen has no place to send to', () => {
    const wrapper = render({ info: info('pairs', { state: 'not_enough', reason: 'no_words' }), learn: null })

    expect(wrapper.find('[data-testid=mode-learn-pairs]').exists()).toBe(false)
  })
})

describe('ModeCard — switched off and busy', () => {
  it('a mode that is off is a dimmed block with a quiet chip', async () => {
    const wrapper = render({ info: info('sentence', { state: 'disabled' }) })

    const card = wrapper.get('[data-testid=mode-card-sentence]')
    expect(card.element.tagName).toBe('DIV')
    expect(card.classes()).toContain('opacity-60')
    expect(wrapper.get('[data-testid=mode-chip]').text()).toBe('Выключено')
    await card.trigger('click')
    expect(wrapper.emitted('select')).toBeUndefined()
  })

  it('while its session is being opened: a spinner, busy, and a press does nothing', async () => {
    const wrapper = render({ info: info('pairs'), busy: true })

    const card = wrapper.get('[data-testid=mode-card-pairs]')
    expect(wrapper.find('[data-testid=mode-busy]').exists()).toBe(true)
    expect(card.attributes('aria-busy')).toBe('true')
    expect(card.attributes('disabled')).toBeDefined()
    await card.trigger('click')
    expect(wrapper.emitted('select')).toBeUndefined()
  })
})
