import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ListGroup from '../ListGroup.vue'

describe('ListGroup', () => {
  it('wraps the rows in a rounded list', () => {
    const w = mount(ListGroup, { slots: { default: '<div class="list-row" data-testid="row">A</div>' } })

    expect(w.get('.list').find('[data-testid=row]').exists()).toBe(true)
    expect(w.find('.section-label').exists()).toBe(false)
    expect(w.find('p').exists()).toBe(false)
  })

  it('shows the header label above and the footnote below', () => {
    const w = mount(ListGroup, { props: { header: 'Обучение', footer: 'Время — по вашему часовому поясу.' } })

    const html = w.html()
    expect(w.get('h2.section-label').text()).toBe('Обучение')
    expect(w.get('p').text()).toBe('Время — по вашему часовому поясу.')
    expect(html.indexOf('Обучение')).toBeLessThan(html.indexOf('class="list"'))
    expect(html.indexOf('class="list"')).toBeLessThan(html.indexOf('Время'))
  })

  it('highlight: the list lies on a tinted plate with the label inside it, 18 px extra bold (Profile mock-up)', () => {
    const w = mount(ListGroup, {
      props: { header: 'Мой аккаунт', highlight: true },
      slots: { default: '<div class="list-row" data-testid="row">A</div>' },
    })

    const plate = w.get('section')
    expect(plate.classes()).toEqual(expect.arrayContaining(['bg-highlight', 'rounded-hero']))
    expect(plate.get('h2').text()).toBe('Мой аккаунт')
    expect(plate.get('h2').classes()).toEqual(expect.arrayContaining(['text-lg', 'font-extrabold']))
    expect(plate.find('.section-label').exists()).toBe(false)
    expect(plate.get('.list').find('[data-testid=row]').exists()).toBe(true)
  })

  it('is the plain group by default', () => {
    const w = mount(ListGroup, { props: { header: 'Обучение' } })

    expect(w.get('section').classes()).not.toContain('bg-highlight')
  })
})
