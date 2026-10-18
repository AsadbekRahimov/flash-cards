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

function render(reviewed: number, nextInDays: number) {
  return mount(FinishedView, { props: { reviewed, nextInDays } })
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
})
