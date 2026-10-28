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
})
