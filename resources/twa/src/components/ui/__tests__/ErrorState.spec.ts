import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ErrorState from '../ErrorState.vue'
import { setLocale } from '@/i18n'

describe('ErrorState', () => {
  afterEach(() => setLocale('ru'))

  it('shows the message under the default title, with the error test id', () => {
    const w = mount(ErrorState, { props: { message: 'Нет связи с сервером.' } })

    expect(w.attributes('data-testid')).toBe('error')
    expect(w.attributes('role')).toBe('alert')
    expect(w.get('h2').text()).toBe('Ошибка')
    expect(w.get('p').text()).toBe('Нет связи с сервером.')
  })

  it('takes a custom title', () => {
    const w = mount(ErrorState, { props: { message: 'm', title: 'Не загрузилось' } })

    expect(w.get('h2').text()).toBe('Не загрузилось')
  })

  it('shows the thinking mascot of the kit above the title by default, no warning icon', () => {
    const w = mount(ErrorState, { props: { message: 'm' } })

    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('thinking')
    expect(w.get('[data-testid=mascot-art]').attributes('alt')).toBe('')
    expect(w.find('svg').exists()).toBe(false)
  })

  it('takes another pose', () => {
    const w = mount(ErrorState, { props: { message: 'm', mascot: 'hello' } })

    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('hello')
  })

  it('draws the warning icon plain, in the danger colour (no circle), without the mascot', () => {
    const w = mount(ErrorState, { props: { message: 'm', mascot: false } })

    expect(w.find('[data-testid=mascot-art]').exists()).toBe(false)
    expect(w.get('svg').classes()).toContain('text-danger')
    expect(w.find('span svg').exists()).toBe(false)
    expect(w.findAll('path')).toHaveLength(1)
  })

  it('emits retry from the button', async () => {
    const w = mount(ErrorState, { props: { message: 'm' } })
    const button = w.get('[data-testid=retry-btn]')

    expect(button.text()).toBe('Повторить')
    await button.trigger('click')
    await button.trigger('click')

    expect(w.emitted('retry')).toHaveLength(2)
  })

  it('renders extra buttons from the slot under "Retry"', () => {
    const w = mount(ErrorState, {
      props: { message: 'm' },
      slots: { default: '<a class="btn btn-plain" data-testid="back">К курсам</a>' },
    })

    expect(w.get('[data-testid=back]').text()).toBe('К курсам')
    const labels = w.findAll('button, a').map((el) => el.attributes('data-testid'))
    expect(labels).toEqual(['retry-btn', 'back'])
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = mount(ErrorState, { props: { message: 'm' } })

    expect(w.get('h2').text()).toBe('Xato yuz berdi')
    expect(w.get('[data-testid=retry-btn]').text()).toBe("Qayta urinib ko'rish")
  })

  it('lets a screen replace the root test id but keeps the retry one', () => {
    const w = mount(ErrorState, { props: { message: 'm' }, attrs: { 'data-testid': 'course-error' } })

    expect(w.attributes('data-testid')).toBe('course-error')
    expect(w.find('[data-testid=retry-btn]').exists()).toBe(true)
  })
})
