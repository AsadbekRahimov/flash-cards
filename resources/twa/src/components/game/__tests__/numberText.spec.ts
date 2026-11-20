import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NumberText from '../NumberText.vue'
import { NUMBER_MARK } from '../numberMark'

describe('NumberText', () => {
  it('sets only the number in Plex Mono, the words around it stay as they are', () => {
    const wrapper = mount(NumberText, { props: { text: `Лучший счёт: ${NUMBER_MARK}`, value: 120 } })

    expect(wrapper.text()).toBe('Лучший счёт: 120')
    expect(wrapper.findAll('.font-mono').map((node) => node.text())).toEqual(['120'])
  })

  it('takes several numbers in the order of the marks, wherever the dictionary puts them', () => {
    const wrapper = mount(NumberText, { props: { text: `${NUMBER_MARK} из ${NUMBER_MARK}`, value: [2, 10] } })

    expect(wrapper.text()).toBe('2 из 10')
    expect(wrapper.findAll('.font-mono').map((node) => node.text())).toEqual(['2', '10'])
  })

  it('writes a line without a mark as it is', () => {
    const wrapper = mount(NumberText, { props: { text: 'Готово', value: 3 } })

    expect(wrapper.text()).toBe('Готово')
    expect(wrapper.find('.font-mono').exists()).toBe(false)
  })
})
