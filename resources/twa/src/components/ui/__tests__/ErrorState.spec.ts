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

  it('draws the warning icon in a soft danger circle', () => {
    const w = mount(ErrorState, { props: { message: 'm' } })

    expect(w.find('span.bg-danger-soft svg').exists()).toBe(true)
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

    expect(w.get('h2').text()).toBe('Xatolik')
    expect(w.get('[data-testid=retry-btn]').text()).toBe('Qayta urinish')
  })

  it('lets a screen replace the root test id but keeps the retry one', () => {
    const w = mount(ErrorState, { props: { message: 'm' }, attrs: { 'data-testid': 'course-error' } })

    expect(w.attributes('data-testid')).toBe('course-error')
    expect(w.find('[data-testid=retry-btn]').exists()).toBe(true)
  })
})
