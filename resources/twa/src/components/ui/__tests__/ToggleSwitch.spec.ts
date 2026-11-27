import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ToggleSwitch from '../ToggleSwitch.vue'

describe('ToggleSwitch', () => {
  it('is a switch button with the state and the label for screen readers', () => {
    const w = mount(ToggleSwitch, { props: { modelValue: true, label: 'Перевод → слово' } })

    expect(w.element.tagName).toBe('BUTTON')
    expect(w.attributes('role')).toBe('switch')
    expect(w.attributes('type')).toBe('button')
    expect(w.attributes('aria-checked')).toBe('true')
    expect(w.attributes('aria-label')).toBe('Перевод → слово')
  })

  it('is the 48 x 28 switch of the kit and takes the press on 44 px (tap-target)', () => {
    const w = mount(ToggleSwitch, { props: { modelValue: true, label: 'x' } })

    expect(w.classes()).toEqual(expect.arrayContaining(['tap-target', 'h-7', 'w-12']))
    expect(w.get('span').classes()).toContain('size-[22px]')
  })

  it('reports off', () => {
    const w = mount(ToggleSwitch, { props: { modelValue: false, label: 'x' } })

    expect(w.attributes('aria-checked')).toBe('false')
  })

  it('uses the success colour when on (kit Switch) and the neutral track when off', async () => {
    const w = mount(ToggleSwitch, { props: { modelValue: true, label: 'x' } })
    expect(w.classes()).toContain('bg-success')

    await w.setProps({ modelValue: false })
    expect(w.classes()).toContain('bg-track')
    expect(w.classes()).not.toContain('bg-success')
  })

  it('draws the knob in palette tokens, flat: the white knob token when on, the secondary text colour when off', async () => {
    const w = mount(ToggleSwitch, { props: { modelValue: true, label: 'x' } })
    const knob = () => w.get('span').classes()

    expect(knob()).toContain('bg-knob')
    expect(knob()).not.toContain('bg-white')
    expect(knob().join(' ')).not.toContain('shadow')

    await w.setProps({ modelValue: false })
    expect(knob()).toContain('bg-muted')
    expect(knob()).not.toContain('bg-knob')
  })

  it('emits the opposite value on click', async () => {
    const on = mount(ToggleSwitch, { props: { modelValue: true, label: 'x' } })
    await on.trigger('click')
    expect(on.emitted('update:modelValue')).toEqual([[false]])

    const off = mount(ToggleSwitch, { props: { modelValue: false, label: 'x' } })
    await off.trigger('click')
    expect(off.emitted('update:modelValue')).toEqual([[true]])
  })

  it('does nothing while disabled', async () => {
    const w = mount(ToggleSwitch, { props: { modelValue: false, label: 'x', disabled: true } })

    expect(w.attributes('disabled')).toBeDefined()
    await w.trigger('click')

    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('passes the test id to the button', () => {
    const w = mount(ToggleSwitch, { props: { modelValue: false, label: 'x' }, attrs: { 'data-testid': 'exercise-audio' } })

    expect(w.attributes('data-testid')).toBe('exercise-audio')
  })
})
