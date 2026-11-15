import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import IntroSheet from '../IntroSheet.vue'
import { setLocale } from '@/i18n'

describe('IntroSheet', () => {
  beforeEach(() => setLocale('ru'))
  afterEach(() => setLocale('ru'))

  async function toStep(w: ReturnType<typeof mount>, n: number): Promise<void> {
    for (let i = 1; i < n; i++) await w.get('[data-testid=intro-next]').trigger('click')
  }

  it('has four dots and tells about the ladder of exercises on the third step', async () => {
    const w = mount(IntroSheet, { attachTo: document.body })

    expect(w.get('[data-testid=intro-dots]').findAll('span')).toHaveLength(4)
    await toStep(w, 3)
    expect(w.get('[data-testid=intro-title]').text()).toBe('Задания становятся сложнее')
    expect(w.get('[data-testid=intro-text]').text()).toContain('потом пишете и вставляете в предложение')
    w.unmount()
  })

  it('shows "Start" only on the last step and closes from it', async () => {
    const w = mount(IntroSheet, { attachTo: document.body })

    await toStep(w, 3)
    expect(w.get('[data-testid=intro-next]').text()).toBe('Дальше')
    await w.get('[data-testid=intro-next]').trigger('click')
    expect(w.get('[data-testid=intro-title]').text()).toBe('Хватит 10 минут в день')
    expect(w.get('[data-testid=intro-next]').text()).toBe('Начать')
    await w.get('[data-testid=intro-next]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
    w.unmount()
  })

  it('speaks Uzbek too', async () => {
    setLocale('uz')
    const w = mount(IntroSheet, { attachTo: document.body })

    await toStep(w, 3)
    expect(w.get('[data-testid=intro-text]').text()).toContain("gapga qo'yasiz")
    w.unmount()
  })
})
