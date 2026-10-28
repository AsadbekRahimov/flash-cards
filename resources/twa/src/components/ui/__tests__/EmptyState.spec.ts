import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EmptyState from '../EmptyState.vue'

describe('EmptyState', () => {
  it('shows only the text when nothing else is given', () => {
    const w = mount(EmptyState, { props: { text: 'У вас пока нет колод.' } })

    expect(w.attributes('data-testid')).toBe('empty')
    expect(w.get('p').text()).toBe('У вас пока нет колод.')
    expect(w.find('h2').exists()).toBe(false)
    expect(w.find('svg').exists()).toBe(false)
    expect(w.find('button').exists()).toBe(false)
  })

  it('shows the icon in a circle and the title', () => {
    const w = mount(EmptyState, { props: { icon: 'rectangle-stack', title: 'Колод нет', text: 'Создайте первую.' } })

    expect(w.find('span svg').exists()).toBe(true)
    expect(w.get('h2').text()).toBe('Колод нет')
  })

  it('renders the action slot', async () => {
    const w = mount(EmptyState, {
      props: { text: 't' },
      slots: { action: '<button class="btn btn-tinted" data-testid="create">Выбрать курс</button>' },
    })

    expect(w.get('[data-testid=create]').text()).toBe('Выбрать курс')
  })

  it('lets a screen replace the test id', () => {
    const w = mount(EmptyState, { props: { text: 't' }, attrs: { 'data-testid': 'decks-empty' } })

    expect(w.attributes('data-testid')).toBe('decks-empty')
  })
})
