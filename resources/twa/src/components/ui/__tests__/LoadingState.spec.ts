import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LoadingState from '../LoadingState.vue'
import { setLocale } from '@/i18n'

describe('LoadingState', () => {
  afterEach(() => setLocale('ru'))

  it('is a status region with the hidden "Loading…" text and the spinner test id', () => {
    const w = mount(LoadingState)

    expect(w.attributes('role')).toBe('status')
    expect(w.attributes('aria-busy')).toBe('true')
    expect(w.attributes('data-testid')).toBe('spinner')
    expect(w.get('.sr-only').text()).toBe('Загрузка…')
  })

  it('says it in Uzbek too', () => {
    setLocale('uz')

    expect(mount(LoadingState).get('.sr-only').text()).toBe('Yuklanmoqda…')
  })

  it('draws skeleton cards by default', () => {
    const w = mount(LoadingState)

    expect(w.findAll('.card')).toHaveLength(3)
    expect(w.findAll('.skeleton').length).toBeGreaterThan(3)
    expect(w.find('.list').exists()).toBe(false)
  })

  it('draws a skeleton list for variant "list"', () => {
    const w = mount(LoadingState, { props: { variant: 'list' } })

    expect(w.findAll('.list')).toHaveLength(1)
    expect(w.findAll('.list-row')).toHaveLength(5)
    expect(w.find('.card').exists()).toBe(false)
  })

  it('draws a centred spinner for variant "center"', () => {
    const w = mount(LoadingState, { props: { variant: 'center' } })

    expect(w.classes()).toEqual(expect.arrayContaining(['flex-1', 'items-center', 'justify-center']))
    expect(w.find('.animate-spin').exists()).toBe(true)
    expect(w.find('.skeleton').exists()).toBe(false)
  })

  it('hides the decoration from assistive technology', () => {
    const w = mount(LoadingState, { props: { variant: 'list' } })

    expect(w.get('.list').attributes('aria-hidden')).toBe('true')
  })

  it('lets a screen replace the test id', () => {
    const w = mount(LoadingState, { attrs: { 'data-testid': 'courses-loading' } })

    expect(w.attributes('data-testid')).toBe('courses-loading')
  })
})
