import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LessonHero from '../today/LessonHero.vue'

describe('LessonHero', () => {
  const render = (title: string) => mount(LessonHero, { props: { step: '1', eyebrow: 'A1–A2 · урок 3', title, text: '5 новых слов' } })

  it('hides the picture on a phone narrower than 360 px and gives the text the width there', () => {
    const w = render('Напитки')

    expect(w.get('[data-testid=hero-art]').classes()).toContain('max-[359px]:hidden')
    expect(w.get('[data-testid=hero-copy]').classes()).toContain('max-[359px]:max-w-none')
  })

  it('keeps a long name of a lesson off the picture: a column short of it, wrapped, at most three lines', () => {
    const w = render('Путешествия, транспорт и ориентирование в незнакомом городе')

    expect(w.get('[data-testid=hero-copy]').classes()).toContain('max-w-[min(200px,calc(100%_-_140px))]')
    const title = w.get('[data-testid=hero-title]')
    expect(title.classes()).toEqual(expect.arrayContaining(['line-clamp-3', '[overflow-wrap:anywhere]']))
    expect(title.text()).toBe('Путешествия, транспорт и ориентирование в незнакомом городе')
  })
})
