import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EmptyState from '../EmptyState.vue'

describe('EmptyState', () => {
  it('shows the hello mascot of the kit and the text when nothing else is given', () => {
    const w = mount(EmptyState, { props: { text: 'У вас пока нет колод.' } })

    expect(w.attributes('data-testid')).toBe('empty')
    expect(w.get('p').text()).toBe('У вас пока нет колод.')
    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('hello')
    expect(w.find('h2').exists()).toBe(false)
    expect(w.find('svg').exists()).toBe(false)
    expect(w.find('button').exists()).toBe(false)
  })

  it('takes another pose, and the mascot wins over the icon', () => {
    const w = mount(EmptyState, { props: { text: 't', mascot: 'trophy', icon: 'users' } })

    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('trophy')
    expect(w.find('svg').exists()).toBe(false)
  })

  it('shows nothing above the text without the mascot and the icon', () => {
    const w = mount(EmptyState, { props: { text: 't', mascot: false } })

    expect(w.find('[data-testid=mascot-art]').exists()).toBe(false)
    expect(w.find('svg').exists()).toBe(false)
  })

  it('keeps the icon the caller chose instead of the default mascot', () => {
    const w = mount(EmptyState, { props: { icon: 'lock-closed', text: 't' } })

    expect(w.find('[data-testid=mascot-art]').exists()).toBe(false)
    expect(w.find('svg').exists()).toBe(true)
  })

  it('shows the icon without the mascot, plain in the secondary colour (no circle), and the title', () => {
    const w = mount(EmptyState, { props: { icon: 'rectangle-stack', title: 'Колод нет', text: 'Создайте первую.', mascot: false } })

    expect(w.find('[data-testid=mascot-art]').exists()).toBe(false)
    expect(w.get('svg').classes()).toContain('text-muted')
    expect(w.find('span svg').exists()).toBe(false)
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
