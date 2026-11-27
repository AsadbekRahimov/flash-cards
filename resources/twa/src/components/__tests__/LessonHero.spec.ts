import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LessonHero from '../today/LessonHero.vue'

describe('LessonHero: the daftar of the kit Muhrla', () => {
  const render = (title: string, props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
    mount(LessonHero, { props: { step: '1', eyebrow: 'A1–A2 · урок 3', title, text: '5 новых слов', ...props }, slots })

  it('is a ruled page with the red margin: the surface, radius xl, a hairline frame, the text after the margin', () => {
    const w = render('Напитки')

    const hero = w.get('[data-testid=lesson-hero]')
    expect(hero.classes()).toEqual(expect.arrayContaining(['rounded-xl', 'border-hairline', 'bg-surface', 'pl-11']))
    // ruled every 28 px
    const lines = w.findAll('[data-testid=hero-lines] > span')
    expect(lines.length).toBeGreaterThan(10)
    expect(lines[0]?.attributes('style')).toContain('top: 27px')
    expect(lines[1]?.attributes('style')).toContain('top: 55px')
    const margin = w.get('[data-testid=hero-margin]')
    expect(margin.classes()).toEqual(expect.arrayContaining(['left-[30px]', 'w-[1.5px]', 'bg-muhr/55']))
    expect(margin.attributes('aria-hidden')).toBe('true')
  })

  it('puts the level and the lesson in a chip, the title in Display, the words under it', () => {
    const w = render('Напитки')

    const chip = w.get('[data-testid=hero-eyebrow]')
    expect(chip.classes()).toContain('chip')
    expect(chip.attributes('data-tone')).toBe('neutral')
    expect(chip.text()).toBe('A1–A2 · урок 3')
    expect(w.get('[data-testid=hero-title]').classes()).toContain('t-display-l')
    expect(w.get('[data-testid=hero-text]').text()).toBe('5 новых слов')
  })

  it('paints the chip "the lesson is done" green (kit 02)', () => {
    const w = render('Тренировка', { eyebrow: 'Урок пройден', eyebrowTone: 'success' })

    expect(w.get('[data-testid=hero-eyebrow]').attributes('data-tone')).toBe('success')
  })

  it('draws the topic of the module on the right, 112 px, and hides it on a phone narrower than 340 px', () => {
    const w = render('Напитки', { course: 'english-a1-a2', stage: 3 })

    const art = w.get('[data-testid=hero-art]')
    expect(art.classes()).toContain('max-[339px]:hidden')
    expect(art.get('img').attributes('width')).toBe('112')
    expect(art.get('img').attributes('src')).toContain('topics/english-a1-a2/03-')
  })

  it('has no picture and keeps the layout for a course without pictures, or without a module', () => {
    for (const props of [{ course: 'centre-course', stage: 3 }, { course: 'english-a1-a2', stage: null }, {}]) {
      const w = render('Напитки', props)

      expect(w.find('[data-testid=hero-art]').exists()).toBe(false)
      expect(w.get('[data-testid=hero-title]').text()).toBe('Напитки')
      expect(w.find('[data-testid=hero-eyebrow]').exists()).toBe(true)
    }
  })

  it('takes another picture from the slot `art` (the mascot of the start card)', () => {
    const w = render('Начнём!', { eyebrow: '' }, { art: '<i data-testid="custom-art" />' })

    expect(w.find('[data-testid=hero-art] [data-testid=custom-art]').exists()).toBe(true)
    expect(w.find('[data-testid=hero-eyebrow]').exists()).toBe(false)
  })

  it('keeps a long name of a lesson within three lines, wrapped anywhere', () => {
    const w = render('Путешествия, транспорт и ориентирование в незнакомом городе')

    const title = w.get('[data-testid=hero-title]')
    expect(title.classes()).toEqual(expect.arrayContaining(['line-clamp-3', '[overflow-wrap:anywhere]']))
    expect(title.text()).toBe('Путешествия, транспорт и ориентирование в незнакомом городе')
  })

  it('puts the actions of the default slot under the text', () => {
    const w = render('Напитки', {}, { default: '<button class="btn btn-primary">Начать урок</button>' })

    expect(w.get('.btn-primary').text()).toBe('Начать урок')
  })
})
