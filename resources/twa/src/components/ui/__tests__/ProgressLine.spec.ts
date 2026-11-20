import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProgressLine from '../ProgressLine.vue'

describe('ProgressLine', () => {
  it('draws the track and the fill at the share, in the accent by default', () => {
    const w = mount(ProgressLine, { props: { value: 35 } })

    expect(w.classes()).toContain('progress')
    const fill = w.get('.progress-fill')
    expect(fill.attributes('style')).toContain('width: 35%')
    expect(fill.classes()).toEqual(['progress-fill'])
  })

  it.each([
    ['primary', 'progress-primary'],
    ['success', 'progress-success'],
  ] as const)('takes the %s colour', (tone, cls) => {
    const w = mount(ProgressLine, { props: { value: 62, tone } })

    expect(w.get('.progress-fill').classes()).toContain(cls)
  })

  it.each([
    [-10, '0%'],
    [140, '100%'],
    [44.6, '45%'],
    [Number.NaN, '0%'],
    [Number.POSITIVE_INFINITY, '0%'],
  ])('clamps and rounds the share: %s -> %s', (value, width) => {
    const w = mount(ProgressLine, { props: { value } })

    expect(w.get('.progress-fill').attributes('style')).toContain(`width: ${width}`)
  })

  it('is a progress bar with its value when it has a label', () => {
    const w = mount(ProgressLine, { props: { value: 78.2, label: 'Цель дня' } })

    expect(w.attributes('role')).toBe('progressbar')
    expect(w.attributes('aria-label')).toBe('Цель дня')
    expect(w.attributes('aria-valuenow')).toBe('78')
    expect(w.attributes('aria-valuemin')).toBe('0')
    expect(w.attributes('aria-valuemax')).toBe('100')
    expect(w.attributes('aria-hidden')).toBeUndefined()
  })

  it('is decoration without a label: hidden from a screen reader, no progressbar role', () => {
    const w = mount(ProgressLine, { props: { value: 50 } })

    expect(w.attributes('aria-hidden')).toBe('true')
    expect(w.attributes('role')).toBeUndefined()
    expect(w.attributes('aria-valuenow')).toBeUndefined()
  })

  it('passes the class and the test id to the track', () => {
    const w = mount(ProgressLine, { props: { value: 45, tone: 'primary' }, attrs: { class: 'h-[3px] flex-1', 'data-testid': 'lesson-progress' } })

    expect(w.classes()).toEqual(expect.arrayContaining(['progress', 'h-[3px]', 'flex-1']))
    expect(w.attributes('data-testid')).toBe('lesson-progress')
  })
})
