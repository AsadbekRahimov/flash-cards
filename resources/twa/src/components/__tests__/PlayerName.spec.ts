import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PlayerName from '../PlayerName.vue'

/* The name of a learner in a list with the emoji of Pro after it (docs/31 §5а). */
describe('PlayerName', () => {
  it('shows the emoji after the name, hidden from a screen reader', () => {
    const w = mount(PlayerName, { props: { name: 'Dilnoza', emoji: '🦁' } })

    expect(w.text()).toBe('Dilnoza🦁')
    const emoji = w.get('[data-testid=pro-emoji]')
    expect(emoji.attributes('aria-hidden')).toBe('true')
  })

  it.each([
    ['null', null],
    ['absent (an older server)', undefined],
    ['empty', ''],
    ['blank', '  '],
  ])('shows the name alone when the emoji is %s', (_case, emoji) => {
    const w = mount(PlayerName, { props: { name: 'Bek', emoji } })

    expect(w.text()).toBe('Bek')
    expect(w.find('[data-testid=pro-emoji]').exists()).toBe(false)
  })

  it('cuts a long name, never the emoji nor the suffix', () => {
    const w = mount(PlayerName, { props: { name: 'A very long name that does not fit in a row of 320 px', emoji: '🚀', suffix: '· вы' } })

    expect(w.classes()).toEqual(expect.arrayContaining(['flex', 'min-w-0']))
    expect(w.get('[data-testid=player-name-text]').classes()).toEqual(expect.arrayContaining(['truncate', 'min-w-0']))
    expect(w.get('[data-testid=pro-emoji]').classes()).toContain('shrink-0')
    const suffix = w.findAll('span').at(-1)
    expect(suffix?.text()).toBe('· вы')
    expect(suffix?.classes()).toContain('shrink-0')
    // name, emoji, then the suffix
    expect(w.text()).toBe('A very long name that does not fit in a row of 320 px🚀 · вы')
  })

  it('passes the classes of the caller to the root', () => {
    const w = mount(PlayerName, { props: { name: 'Bek' }, attrs: { class: 'flex-1 font-bold', 'data-testid': 'friend-name' } })

    expect(w.classes()).toEqual(expect.arrayContaining(['flex-1', 'font-bold']))
    expect(w.attributes('data-testid')).toBe('friend-name')
  })
})
