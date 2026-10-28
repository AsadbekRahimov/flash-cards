import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SegmentedControl from '../SegmentedControl.vue'

const OPTIONS = [
  { value: 10, label: '10', testid: 'goal-10' },
  { value: 20, label: '20', testid: 'goal-20' },
  { value: 30, label: '30' },
]

const mountControl = (props: Partial<InstanceType<typeof SegmentedControl>['$props']> = {}) =>
  mount(SegmentedControl, { props: { modelValue: 20, options: OPTIONS, ariaLabel: 'Цель на день', ...props } })

describe('SegmentedControl', () => {
  it('is a radio group named by `ariaLabel`, one radio per option', () => {
    const w = mountControl()

    expect(w.attributes('role')).toBe('radiogroup')
    expect(w.attributes('aria-label')).toBe('Цель на день')
    const radios = w.findAll('[role=radio]')
    expect(radios.map((r) => r.text())).toEqual(['10', '20', '30'])
    expect(radios.every((r) => r.element.tagName === 'BUTTON' && r.attributes('type') === 'button')).toBe(true)
  })

  it('marks only the selected option as checked', () => {
    const w = mountControl({ modelValue: 20 })

    expect(w.findAll('[role=radio]').map((r) => r.attributes('aria-checked'))).toEqual(['false', 'true', 'false'])
  })

  it('emits update:modelValue with the option value, keeping its type', async () => {
    const w = mountControl()

    await w.get('[data-testid=goal-10]').trigger('click')

    expect(w.emitted('update:modelValue')).toEqual([[10]])
  })

  it('works with string values', async () => {
    const w = mountControl({
      modelValue: 'month',
      options: [
        { value: 'month', label: 'Месяц' },
        { value: 'year', label: 'Год' },
      ],
    })

    await w.findAll('[role=radio]')[1]?.trigger('click')

    expect(w.emitted('update:modelValue')).toEqual([['year']])
  })

  it('does not emit for the option that is already selected', async () => {
    const w = mountControl()

    await w.get('[data-testid=goal-20]').trigger('click')

    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('does nothing while disabled', async () => {
    const w = mountControl({ disabled: true })

    expect(w.findAll('[role=radio]').every((r) => r.attributes('disabled') !== undefined)).toBe(true)
    await w.get('[data-testid=goal-10]').trigger('click')

    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('puts the option test id on its button, and none when the option has none', () => {
    const w = mountControl()

    expect(w.get('[data-testid=goal-10]').text()).toBe('10')
    expect(w.findAll('[role=radio]')[2]?.attributes('data-testid')).toBeUndefined()
  })

  it('keeps the look of a 36 px segment and takes the press on 44 px (tap-target)', () => {
    const w = mountControl()

    for (const radio of w.findAll('[role=radio]')) {
      expect(radio.classes()).toEqual(expect.arrayContaining(['tap-target', 'min-h-9']))
    }
  })

  it('highlights the selected segment', () => {
    const w = mountControl({ modelValue: 30 })

    const [first, , third] = w.findAll('[role=radio]')
    expect(third?.classes()).toContain('bg-surface')
    expect(first?.classes()).not.toContain('bg-surface')
  })
})
