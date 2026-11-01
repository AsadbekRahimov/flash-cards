import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppIcon from '../AppIcon.vue'
import { ICONS, type IconName } from '../icons'

describe('AppIcon', () => {
  it('draws the outline style by default: stroke, no fill, 20px', () => {
    const w = mount(AppIcon, { props: { name: 'home' } })
    const svg = w.get('svg')

    expect(svg.attributes('viewBox')).toBe('0 0 24 24')
    expect(svg.attributes('width')).toBe('20')
    expect(svg.attributes('height')).toBe('20')
    expect(svg.attributes('aria-hidden')).toBe('true')
    expect(svg.attributes('fill')).toBe('none')
    expect(svg.attributes('stroke')).toBe('currentColor')
    expect(svg.attributes('stroke-width')).toBe('1.5')
    expect(svg.attributes('stroke-linecap')).toBe('round')
    expect(svg.attributes('stroke-linejoin')).toBe('round')
    expect(w.findAll('path').map((p) => p.attributes('d'))).toEqual(ICONS.home.outline.map((p) => p.d))
  })

  it('draws the solid style filled, with the fill / clip rules of the source', () => {
    const w = mount(AppIcon, { props: { name: 'check-circle', solid: true, size: 28 } })
    const svg = w.get('svg')

    expect(svg.attributes('width')).toBe('28')
    expect(svg.attributes('fill')).toBe('currentColor')
    expect(svg.attributes('stroke')).toBeUndefined()
    const path = w.get('path')
    expect(path.attributes('d')).toBe(ICONS['check-circle'].solid[0]?.d)
    expect(path.attributes('fill-rule')).toBe('evenodd')
    expect(path.attributes('clip-rule')).toBe('evenodd')
  })

  it('renders every path of a multi-path icon', () => {
    const w = mount(AppIcon, { props: { name: 'cog-6-tooth' } })

    expect(w.findAll('path')).toHaveLength(2)
    expect(w.findAll('path').every((p) => p.attributes('fill-rule') === undefined)).toBe(true)
  })

  it('passes class and data attributes to the svg', () => {
    const w = mount(AppIcon, { props: { name: 'trophy' }, attrs: { class: 'text-warning', 'data-testid': 'ico' } })

    expect(w.get('svg').classes()).toContain('text-warning')
    expect(w.find('[data-testid=ico]').exists()).toBe(true)
  })

  it('switches the style when `solid` changes', async () => {
    const w = mount(AppIcon, { props: { name: 'star', solid: false } })
    const outline = w.get('path').attributes('d')

    await w.setProps({ solid: true })

    expect(w.get('path').attributes('d')).not.toBe(outline)
    expect(w.get('svg').attributes('fill')).toBe('currentColor')
  })
})

describe('icons data', () => {
  const names = Object.keys(ICONS) as IconName[]

  it('has the 50 icons of docs/14 and the 3 of the practice modes, each in both styles', () => {
    expect(names).toHaveLength(53)
    for (const name of names) {
      expect(ICONS[name].outline.length, `${name} outline`).toBeGreaterThan(0)
      expect(ICONS[name].solid.length, `${name} solid`).toBeGreaterThan(0)
    }
  })

  it('every path has data and only a known rule', () => {
    for (const name of names) {
      for (const path of [...ICONS[name].outline, ...ICONS[name].solid]) {
        expect(path.d, name).toMatch(/^[Mm][-\d.,\sMmLlHhVvCcSsQqTtAaZz]+$/)
      }
      // Outline icons are strokes: a fill rule only exists on the solid ones.
      for (const path of ICONS[name].outline) {
        expect('fillRule' in path || 'clipRule' in path, `${name} outline`).toBe(false)
      }
    }
  })
})
