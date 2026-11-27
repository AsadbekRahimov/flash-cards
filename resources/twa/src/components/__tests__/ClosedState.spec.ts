import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ClosedState from '../ClosedState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'

describe('ClosedState', () => {
  it('a closed session: the quiet icon, the title in Title/M of the kit, the text and the buttons', () => {
    const w = mount(ClosedState, {
      props: { icon: 'lock-closed', title: 'Занятие закрыто', text: 'Начните новое' },
      slots: { default: '<button class="btn btn-primary">OK</button>' },
    })

    expect(w.findComponent(AppIcon).props('name')).toBe('lock-closed')
    expect(w.find('[data-testid=mascot-art]').exists()).toBe(false)
    expect(w.get('[data-testid=closed-title]').text()).toBe('Занятие закрыто')
    expect(w.get('[data-testid=closed-title]').classes()).toContain('t-title-m')
    expect(w.text()).toContain('Начните новое')
    expect(w.find('.btn-primary').exists()).toBe(true)
  })

  it('the hero of a state: the mascot instead of the icon, smaller in a short window', () => {
    const w = mount(ClosedState, { props: { icon: 'lock-closed', mascot: 'hello', title: 'Пусто' } })

    const mascot = w.get('[data-testid=mascot-art]')
    expect(mascot.attributes('data-pose')).toBe('hello')
    expect(mascot.classes()).toContain('short:h-24')
    expect(w.findComponent(AppIcon).exists()).toBe(false)
  })

  it('has no title, no text and no row of buttons when none is given', () => {
    const w = mount(ClosedState, { props: { mascot: 'thinking' } })

    expect(w.find('[data-testid=closed-title]').exists()).toBe(false)
    expect(w.find('p').exists()).toBe(false)
    expect(w.find('.max-w-64').exists()).toBe(false)
  })
})
