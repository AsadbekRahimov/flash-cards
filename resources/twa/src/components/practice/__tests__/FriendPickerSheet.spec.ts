import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FriendPickerSheet from '../FriendPickerSheet.vue'
import { setLocale } from '@/i18n'
import type { FriendItem } from '@/api/types'

afterEach(() => setLocale('ru'))

const friend = (id: number, first_name: string | null, overrides: Partial<FriendItem> = {}): FriendItem => ({
  id,
  first_name,
  streak: 9,
  words_learned: 410,
  since: '2026-10-01',
  ...overrides,
})

const render = (props: Partial<InstanceType<typeof FriendPickerSheet>['$props']> = {}) =>
  mount(FriendPickerSheet, { props: { friends: [friend(31, 'Тимур'), friend(32, 'Камила', { streak: 0, words_learned: 95 })], status: 'ready', ...props }, attachTo: document.body })

describe('FriendPickerSheet — "Who do we play with?"', () => {
  it('lists the friends: a name and what the Friends screen says of them', () => {
    const wrapper = render()

    expect(wrapper.get('[role=dialog]').attributes('aria-label')).toBe('С кем играем?')
    const rows = wrapper.findAll('[data-testid^=friend-]').filter((row) => row.element.tagName === 'BUTTON')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Тимур')
    expect(rows[0]!.text()).toContain('🔥 9 · 410 слов')
    expect(rows[1]!.text()).toContain('Камила')
    expect(rows[1]!.text()).toContain('95 слов')
    wrapper.unmount()
  })

  it('every row is a button of the size of a row of a list, named by what it does', () => {
    const wrapper = render()

    const row = wrapper.get('[data-testid=friend-31]')
    expect(row.element.tagName).toBe('BUTTON')
    expect(row.attributes('type')).toBe('button')
    expect(row.classes()).toContain('list-row')
    expect(row.attributes('aria-label')).toBe('Играть с Тимур')
    wrapper.unmount()
  })

  it('says pick with the friend that was tapped', async () => {
    const wrapper = render()

    await wrapper.get('[data-testid=friend-32]').trigger('click')

    expect(wrapper.emitted('pick')).toEqual([[friend(32, 'Камила', { streak: 0, words_learned: 95 })]])
    wrapper.unmount()
  })

  it('a friend without a name is "Friend"', () => {
    const wrapper = render({ friends: [friend(33, null)] })

    expect(wrapper.get('[data-testid=friend-33]').text()).toContain('Друг')
    wrapper.unmount()
  })

  it('is a skeleton while the friends load', () => {
    const wrapper = render({ friends: [], status: 'loading' })

    expect(wrapper.find('[data-testid=spinner]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=friend-list]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('with no friends it says so and points to inviting one', async () => {
    const wrapper = render({ friends: [] })

    expect(wrapper.get('[data-testid=friends-empty]').text()).toContain('Пока нет друзей')
    await wrapper.get('[data-testid=friends-invite]').trigger('click')
    expect(wrapper.emitted('invite')).toHaveLength(1)
    wrapper.unmount()
  })

  it('a failure is told with "Retry"', async () => {
    const wrapper = render({ friends: [], status: 'error', message: 'Нет связи с сервером. Попробуйте ещё раз.' })

    expect(wrapper.get('[data-testid=friends-error]').text()).toContain('Нет связи с сервером')
    await wrapper.get('[data-testid=friends-retry]').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
    wrapper.unmount()
  })

  it('closes by the ✕', async () => {
    const wrapper = render()

    await wrapper.get('[data-testid=sheet-x]').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })
})
