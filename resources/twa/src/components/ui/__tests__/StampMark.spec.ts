import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StampMark from '../StampMark.vue'

const impact = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useTelegram', () => ({ useTelegram: () => ({ haptic: impact }) }))

describe('StampMark', () => {
  it('is felt once when it appears, only when asked (`haptic`: the screens of a result, not Today)', async () => {
    impact.mockClear()
    const quiet = mount(StampMark, { props: { value: 12, unit: 'дней' } })
    expect(impact).not.toHaveBeenCalled()

    const felt = mount(StampMark, { props: { value: 3, unit: 'очка', haptic: true } })
    expect(impact).toHaveBeenCalledTimes(1)
    expect(impact).toHaveBeenCalledWith('medium')
    // a new value on the same stamp is no new press
    await felt.setProps({ value: 4 })
    expect(impact).toHaveBeenCalledTimes(1)
    quiet.unmount()
    felt.unmount()
  })

  it('shows the value and the unit, and is one image for a screen reader', () => {
    const w = mount(StampMark, { props: { value: 12, unit: 'дней' } })
    const stamp = w.get('[data-testid=stamp]')

    expect(stamp.attributes('role')).toBe('img')
    expect(stamp.attributes('aria-label')).toBe('12 дней')
    expect(w.get('[data-testid=stamp-value]').text()).toBe('12')
    expect(w.get('[data-testid=stamp-unit]').text()).toBe('дней')
    expect(w.get('[data-testid=stamp-value]').attributes('aria-hidden')).toBe('true')
  })

  it('is the 92 px stamp of the kit: two rings in the muhr colour, the outer 2.5 px, the inner 1 px dashed 2 / 3, turned by -8°', () => {
    const w = mount(StampMark, { props: { value: '7' } })
    const stamp = w.get('[data-testid=stamp]')

    expect(stamp.classes()).toEqual(expect.arrayContaining(['stamp', 'size-[92px]', 'text-muhr', 'rounded-full']))
    const outer = w.get('[data-ring=outer]')
    const inner = w.get('[data-ring=inner]')
    expect(outer.attributes('stroke-width')).toBe('2.5')
    expect(inner.attributes('stroke-width')).toBe('1')
    expect(inner.attributes('stroke-dasharray')).toBe('2 3')
    expect(w.get('svg').attributes('stroke')).toBe('currentColor')
    // the small unit takes the accent of text (4.5:1); the big value the muhr colour of the stamp
    expect(w.find('[data-testid=stamp-unit]').exists()).toBe(false)
    expect(stamp.attributes('aria-label')).toBe('7')
  })

  it('plays the stamp press when it appears, and can stand still', () => {
    expect(mount(StampMark, { props: { value: 1 } }).get('[data-testid=stamp]').classes()).toContain('stamp--enter')
    expect(mount(StampMark, { props: { value: 1, animate: false } }).get('[data-testid=stamp]').classes()).not.toContain('stamp--enter')
  })

  it('puts the small unit in the accent of text, not in the muhr colour', () => {
    const unit = mount(StampMark, { props: { value: 3, unit: 'kun' } }).get('[data-testid=stamp-unit]')
    expect(unit.classes()).toContain('text-accent')
  })
})
