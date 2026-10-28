import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import InlineAlert, { type AlertTone } from '../InlineAlert.vue'
import { ICONS, type IconName } from '../icons'

const TONES: { tone: AlertTone; role: 'alert' | 'status'; icon: IconName; classes: string[] }[] = [
  { tone: 'danger', role: 'alert', icon: 'exclamation-circle', classes: ['bg-danger-soft', 'text-danger-ink'] },
  { tone: 'warning', role: 'status', icon: 'exclamation-triangle', classes: ['bg-warning-soft', 'text-warning-ink'] },
  { tone: 'success', role: 'status', icon: 'check-circle', classes: ['bg-success-soft', 'text-success-ink'] },
  { tone: 'info', role: 'status', icon: 'information-circle', classes: ['bg-accent-soft', 'text-tg-text'] },
]

describe('InlineAlert', () => {
  it('shows the message', () => {
    const w = mount(InlineAlert, { props: { tone: 'danger', message: 'Курс не найден или недоступен.' } })

    expect(w.text()).toBe('Курс не найден или недоступен.')
  })

  it('takes the text from the default slot, which replaces the message', () => {
    const w = mount(InlineAlert, {
      props: { tone: 'info', message: 'from the prop' },
      slots: { default: 'Ссылка <b data-testid="bold">скопирована</b>' },
    })

    expect(w.text()).toBe('Ссылка скопирована')
    expect(w.get('[data-testid=bold]').text()).toBe('скопирована')
    expect(w.text()).not.toContain('from the prop')
  })

  it.each(TONES)('$tone: role $role, the $icon icon and the colours of its tone', ({ tone, role, icon, classes }) => {
    const w = mount(InlineAlert, { props: { tone, message: 'm' } })

    expect(w.attributes('role')).toBe(role)
    expect(w.classes()).toEqual(expect.arrayContaining(['flex', 'items-start', 'rounded-xl', ...classes]))
    expect(w.findAll('svg')).toHaveLength(1)
    expect(w.get('svg').attributes('aria-hidden')).toBe('true')
    expect(w.get('svg').attributes('width')).toBe('20')
    expect(w.get('path').attributes('d')).toBe(ICONS[icon].outline[0]?.d)
  })

  it('announces only an error at once: the others are polite status messages', () => {
    const roles = TONES.map(({ tone }) => mount(InlineAlert, { props: { tone, message: 'm' } }).attributes('role'))

    expect(roles).toEqual(['alert', 'status', 'status', 'status'])
  })

  it('lets the icon of the info tone carry the accent colour', () => {
    const info = mount(InlineAlert, { props: { tone: 'info', message: 'm' } })
    const danger = mount(InlineAlert, { props: { tone: 'danger', message: 'm' } })

    expect(info.get('svg').classes()).toContain('text-accent')
    expect(danger.get('svg').classes()).not.toContain('text-accent') // it takes the ink of the text
  })

  it('passes the test id, the spacing class and other attributes to the root', () => {
    const w = mount(InlineAlert, {
      props: { tone: 'danger', message: 'm' },
      attrs: { 'data-testid': 'select-error', class: 'mt-4 w-full', id: 'err' },
    })

    expect(w.attributes('data-testid')).toBe('select-error')
    expect(w.attributes('id')).toBe('err')
    expect(w.classes()).toEqual(expect.arrayContaining(['mt-4', 'w-full', 'bg-danger-soft']))
  })

  it('keeps long words inside the box and the text left-aligned in a centered parent', () => {
    const w = mount(InlineAlert, { props: { tone: 'warning', message: 'm' } })

    expect(w.classes()).toContain('text-left')
    expect(w.get('span').classes()).toEqual(expect.arrayContaining(['min-w-0', 'flex-1', 'break-words']))
  })

  it('follows the tone when it changes', async () => {
    const w = mount(InlineAlert, { props: { tone: 'warning', message: 'm' } })

    await w.setProps({ tone: 'success' })

    expect(w.attributes('role')).toBe('status')
    expect(w.classes()).toContain('bg-success-soft')
    expect(w.classes()).not.toContain('bg-warning-soft')
    expect(w.get('path').attributes('d')).toBe(ICONS['check-circle'].outline[0]?.d)
  })
})
