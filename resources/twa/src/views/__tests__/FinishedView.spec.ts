import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FinishedView from '../FinishedView.vue'
import { setLocale } from '@/i18n'

vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    close: vi.fn(),
    notificationHaptic: vi.fn(),
  }),
}))

function render(reviewed: number, nextInDays: number, reason: string | null = null) {
  return mount(FinishedView, { props: { reviewed, nextInDays, reason } })
}

describe('FinishedView', () => {
  afterEach(() => setLocale('ru'))

  it.each([
    [1, 'Вы повторили 1 слово.'],
    [2, 'Вы повторили 2 слова.'],
    [5, 'Вы повторили 5 слов.'],
    [21, 'Вы повторили 21 слово.'],
  ])('uses the correct Russian form for %i reviewed words', (reviewed, text) => {
    expect(render(reviewed, 0).get('[data-testid=reviewed]').text()).toBe(text)
  })

  it.each([
    [0, 'Сегодня'],
    [1, 'Через 1 день'],
    [3, 'Через 3 дня'],
    [5, 'Через 5 дней'],
    [21, 'Через 21 день'],
  ])('labels the next review in %i days in Russian', (days, label) => {
    expect(render(3, days).get('[data-testid=next-label]').text()).toBe(label)
  })

  it('renders Uzbek without noun inflection', () => {
    setLocale('uz')
    const w = render(5, 3)
    expect(w.get('[data-testid=reviewed]').text()).toBe("Siz 5 ta so'zni takrorladingiz.")
    expect(w.get('[data-testid=next-label]').text()).toBe('3 kundan keyin')
    expect(w.get('[data-testid=close-btn]').text()).toBe('Yopish')
    expect(render(3, 0).get('[data-testid=next-label]').text()).toBe('Bugun')
  })

  it.each([
    ['lesson_complete', 'Все слова урока пройдены.'],
    ['nothing_due', 'На сегодня повторять нечего.'],
    ['new_quota_exhausted', 'Лимит новых слов на сегодня исчерпан. Новые слова откроются завтра.'],
    ['session_cap', 'Это была длинная сессия — сделайте перерыв.'],
  ])('explains done_reason %s', (reason, text) => {
    expect(render(3, 1, reason).get('[data-testid=reason]').text()).toBe(text)
  })

  it('shows no reason line for an unknown or missing reason', () => {
    expect(render(3, 1, 'brand_new_reason').find('[data-testid=reason]').exists()).toBe(false)
    expect(render(3, 1).find('[data-testid=reason]').exists()).toBe(false)
  })

  it('hides the zero-words line when a reason explains the empty session', () => {
    const w = render(0, 0, 'nothing_due')
    expect(w.find('[data-testid=reviewed]').exists()).toBe(false)
    expect(w.find('[data-testid=reason]').exists()).toBe(true)
    expect(render(0, 0).find('[data-testid=reviewed]').exists()).toBe(true)
  })

  it('renders the reason in Uzbek', () => {
    setLocale('uz')
    expect(render(3, 1, 'new_quota_exhausted').get('[data-testid=reason]').text())
      .toBe("Bugungi yangi so'zlar limiti tugadi. Yangi so'zlar ertaga ochiladi.")
  })
})
