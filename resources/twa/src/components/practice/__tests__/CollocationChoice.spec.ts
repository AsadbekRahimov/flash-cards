import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CollocationChoice from '../CollocationChoice.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { setLocale } from '@/i18n'
import { collocationElement } from '@/api/__tests__/practiceFixtures'
import type { CollocationElement } from '@/api/types'

afterEach(() => setLocale('ru'))

function render(props: { element?: CollocationElement; mode?: 'solo' | 'battle'; disabled?: boolean } = {}) {
  return mount(CollocationChoice, { props: { element: collocationElement(), mode: 'solo', ...props } })
}

type Wrapper = ReturnType<typeof render>

const option = (wrapper: Wrapper, index: number) => wrapper.get(`[data-testid=option-${index}]`)
const answers = (wrapper: Wrapper) => wrapper.emitted('answer') ?? []

describe('CollocationChoice — the phrase and the options', () => {
  it('writes the phrase with the blank drawn as a box of its own, not as underscores in the text', () => {
    const wrapper = render()

    const text = wrapper.get('[data-testid=collocation-text]')
    const blank = wrapper.get('[data-testid=collocation-blank]')
    expect(text.text()).not.toContain('___')
    expect(text.text()).not.toContain('_')
    expect(blank.element.tagName).toBe('SPAN')
    expect(blank.classes()).toEqual(expect.arrayContaining(['inline-block', 'border-2', 'border-dashed', 'rounded-lg']))
    expect(blank.attributes('data-tone')).toBe('empty')
    expect(text.element.children).toHaveLength(3) // the words before, the box, the words after
    expect(text.element.children[0]?.textContent).toBe("Let's ")
    expect(text.element.children[2]?.textContent).toBe(' a meeting on Monday.')
  })

  it('says to a screen reader that there is a blank, and reads the phrase as English', () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid=collocation-blank] .sr-only').text()).toBe('пропуск')
    expect(wrapper.get('[data-testid=collocation-text]').attributes('lang')).toBe('en')
    expect(wrapper.get('[data-testid=option-0] [lang=en]').text()).toBe('make')
  })

  it('tells what to do, in a line above the phrase', () => {
    expect(render().get('[data-testid=collocation-instruction]').text()).toBe('Выберите подходящее слово')
  })

  it('draws every option as a button of the design system, in the order of the element, all of them idle', () => {
    const wrapper = render()

    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(3)
    expect(buttons.map((button) => button.text())).toEqual(['make', 'have', 'do'])
    for (const button of buttons) {
      expect(button.attributes('type')).toBe('button')
      expect(button.classes()).toContain('option')
      expect(button.attributes('data-state')).toBe('idle')
      expect(button.attributes('disabled')).toBeUndefined()
      expect(button.attributes('aria-pressed')).toBeUndefined()
    }
    expect(wrapper.get('[role=group]').attributes('aria-label')).toBe('Выберите подходящее слово')
  })

  it('has no "Check" and no "Skip": the tap is the answer', () => {
    const wrapper = render()

    expect(wrapper.find('[data-testid=check-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=skip-btn]').exists()).toBe(false)
    expect(wrapper.find('.btn').exists()).toBe(false)
  })

  it('takes the type down a size for a long phrase, and one more for a very long one, so that the card stays short at 320 px', () => {
    const middle = collocationElement({ text: 'Every morning my grandmother and I ___ a long walk in the park.' })
    const long = collocationElement({ text: `${'Every morning my grandmother and I '.repeat(2)}___ a very long walk in the old park near our house.` })

    expect(render().get('[data-testid=collocation-text]').classes()).toContain('text-2xl')
    expect(render({ element: middle }).get('[data-testid=collocation-text]').classes()).toContain('text-xl')
    expect(render({ element: long }).get('[data-testid=collocation-text]').classes()).toContain('text-lg')
  })

  it('shows what the server sent as text, never as markup', () => {
    const wrapper = render({
      element: collocationElement({ text: 'A <b>bold</b> ___.', options: [{ id: 'oA1', text: '<i>make</i>' }, { id: 'oB2', text: 'have' }, { id: 'oC3', text: 'do' }] }),
    })

    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.find('i').exists()).toBe(false)
    expect(wrapper.get('[data-testid=collocation-text]').text()).toContain('A <b>bold</b>')
    expect(option(wrapper, 0).text()).toBe('<i>make</i>')
  })

  it('still shows a text that has no blank (it should not happen), and the options work', async () => {
    const wrapper = render({ element: collocationElement({ text: 'No blank here.' }) })

    expect(wrapper.find('[data-testid=collocation-blank]').exists()).toBe(false)
    expect(wrapper.get('[data-testid=collocation-text]').text()).toBe('No blank here.')
    await option(wrapper, 1).trigger('click')
    expect(answers(wrapper)).toEqual([[{ option_id: 'oB2' }]])
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    const wrapper = render()

    expect(wrapper.get('[data-testid=collocation-instruction]').text()).toBe("Bo'sh joyga mos so'zni tanlang")
    expect(wrapper.get('[data-testid=collocation-blank] .sr-only').text()).toBe("bo'sh joy")
    expect(wrapper.get('[data-testid=collocation-footnote]').text()).toBe('Javob bosilganda darhol tekshiriladi.')
  })
})

describe('CollocationChoice — a tap is the answer, once', () => {
  it('sends the answer at the first tap: the id of the option, nothing else', async () => {
    const wrapper = render()

    await option(wrapper, 1).trigger('click')

    expect(answers(wrapper)).toEqual([[{ option_id: 'oB2' }]])
  })

  it('sends one request for a double tap on the same option', async () => {
    const wrapper = render()

    await option(wrapper, 0).trigger('click')
    await option(wrapper, 0).trigger('click')
    await option(wrapper, 0).trigger('dblclick')

    expect(answers(wrapper)).toHaveLength(1)
  })

  it('sends one request when a second option is tapped at once (two fingers, a nervous hand)', async () => {
    const wrapper = render()

    // Both clicks land before the screen has re-rendered.
    void option(wrapper, 0).trigger('click')
    void option(wrapper, 2).trigger('click')
    await wrapper.vm.$nextTick()

    expect(answers(wrapper)).toEqual([[{ option_id: 'oA1' }]])
  })

  it('writes the picked word into the blank at once and marks the pick, with a symbol and a caption besides the colour', async () => {
    const wrapper = render()

    await option(wrapper, 1).trigger('click')

    const blank = wrapper.get('[data-testid=collocation-blank]')
    expect(blank.text()).toBe('have')
    expect(blank.attributes('data-tone')).toBe('picked')
    expect(blank.classes()).not.toContain('border-dashed')
    expect(wrapper.get('[data-testid=collocation-text]').text()).toBe("Let's have a meeting on Monday.")

    const picked = option(wrapper, 1)
    expect(picked.attributes('data-state')).toBe('selected')
    expect(picked.attributes('aria-pressed')).toBe('true')
    expect(picked.findComponent(AppIcon).props('name')).toBe('check-circle')
    expect(wrapper.get('[data-testid=option-caption-1]').text()).toBe('ваш ответ')
    expect(wrapper.get('[data-testid=option-caption-1]').classes()).toContain('first-letter:uppercase')
  })

  it('locks the other options after the pick, and dims them', async () => {
    const wrapper = render()

    await option(wrapper, 1).trigger('click')

    for (const index of [0, 1, 2]) expect(option(wrapper, index).attributes('disabled')).toBeDefined()
    expect(option(wrapper, 0).attributes('data-state')).toBe('idle')
    expect(option(wrapper, 0).classes()).toContain('opacity-60')
    expect(option(wrapper, 1).classes()).not.toContain('opacity-60')
    expect(wrapper.find('[data-testid=collocation-footnote]').exists()).toBe(false)
  })
})

describe('CollocationChoice — while the request is on its way', () => {
  it('locks every option and ignores a tap when the screen says an answer is on its way', async () => {
    const wrapper = render({ disabled: true })

    for (const index of [0, 1, 2]) expect(option(wrapper, index).attributes('disabled')).toBeDefined()
    await option(wrapper, 0).trigger('click')

    expect(answers(wrapper)).toHaveLength(0)
    expect(option(wrapper, 0).attributes('data-state')).toBe('idle')
  })

  it('keeps the pick marked while the request is on its way', async () => {
    const wrapper = render()
    await option(wrapper, 2).trigger('click')

    await wrapper.setProps({ disabled: true })

    expect(option(wrapper, 2).attributes('data-state')).toBe('selected')
    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('do')
    expect(answers(wrapper)).toHaveLength(1)
  })

  it('does not release a pick that was never sent on its way (the screen did not lock)', async () => {
    const wrapper = render()
    await option(wrapper, 0).trigger('click')

    await wrapper.setProps({ disabled: false }) // not a change at all

    expect(option(wrapper, 0).attributes('data-state')).toBe('selected')
    await option(wrapper, 1).trigger('click')
    expect(answers(wrapper)).toHaveLength(1)
    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false)
  })
})

// A tap is ONE answer per element (docs/16 §11.4). When its request gets no reply the server may have counted it, so the pick is not
// taken back: a different option would be a second answer, refused as "already accepted" and shown as the verdict of the first.
describe('CollocationChoice — a pick whose request got no reply (solo)', () => {
  /** The learner taps `index`, the screen locks the component for the request, and the request comes back with nothing. */
  async function lostReply(index = 0) {
    const wrapper = render()
    await option(wrapper, index).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })
    return wrapper
  }

  it('keeps the pick: marked in the options and written in the blank, as it was while the request was on its way', async () => {
    const wrapper = await lostReply(0)

    expect(option(wrapper, 0).attributes('data-state')).toBe('selected')
    expect(option(wrapper, 0).attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid=option-caption-0]').text()).toBe('ваш ответ')
    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('make')
    expect(wrapper.get('[data-testid=collocation-blank]').attributes('data-tone')).toBe('picked')
  })

  it('does not let the learner change it: every option stays locked, and a tap on another one sends nothing', async () => {
    const wrapper = await lostReply(0)

    for (const index of [0, 1, 2]) expect(option(wrapper, index).attributes('disabled')).toBeDefined()
    await option(wrapper, 2).trigger('click')
    ;(option(wrapper, 1).element as HTMLButtonElement).click() // not through the disabled attribute either

    expect(answers(wrapper)).toEqual([[{ option_id: 'oA1' }]])
    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('make')
  })

  it('says so, and offers one thing: "Retry" - in the sticky block, the one primary button of the screen', async () => {
    const wrapper = await lostReply()

    expect(wrapper.get('[data-testid=collocation-resend-note]').text()).toBe('Ответа от сервера нет. Свой ответ уже не изменить — отправьте его ещё раз.')
    expect(wrapper.get('[data-testid=collocation-resend-note]').attributes('role')).toBe('status')
    const retry = wrapper.get('[data-testid=actions] [data-testid=resend-btn]')
    expect(retry.text()).toBe('Повторить')
    expect(retry.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg']))
    expect(wrapper.findAll('.btn-primary')).toHaveLength(1)
    expect(wrapper.find('[data-testid=collocation-footnote]').exists()).toBe(false) // "checked at once" is not the news any more
  })

  it('"Retry" sends the very same option, again', async () => {
    const wrapper = await lostReply(2)

    await wrapper.get('[data-testid=resend-btn]').trigger('click')

    expect(answers(wrapper)).toEqual([[{ option_id: 'oC3' }], [{ option_id: 'oC3' }]])
  })

  it('"Retry" waits while the repeat is on its way, and is there again when that has no reply either', async () => {
    const wrapper = await lostReply()
    await wrapper.get('[data-testid=resend-btn]').trigger('click')

    await wrapper.setProps({ disabled: true })
    expect(wrapper.get('[data-testid=resend-btn]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=resend-btn]').attributes('aria-busy')).toBe('true')
    await wrapper.get('[data-testid=resend-btn]').trigger('click')
    expect(answers(wrapper)).toHaveLength(2)

    await wrapper.setProps({ disabled: false })
    expect(wrapper.get('[data-testid=resend-btn]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid=resend-btn]').trigger('click')
    expect(answers(wrapper)).toEqual([[{ option_id: 'oA1' }], [{ option_id: 'oA1' }], [{ option_id: 'oA1' }]])
    expect(option(wrapper, 0).attributes('data-state')).toBe('selected') // still the pick of the first tap
  })

  it('has none of it before: no note, no button, until a request has failed', async () => {
    const wrapper = render()
    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=collocation-resend-note]').exists()).toBe(false)

    await option(wrapper, 0).trigger('click')
    await wrapper.setProps({ disabled: true })

    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false) // on its way is not "no reply"
    expect(wrapper.findAll('.btn-primary')).toHaveLength(0)
  })

  it('a lock that was not for this component\'s tap (nothing picked) is no failure of an answer', async () => {
    const wrapper = render()

    await wrapper.setProps({ disabled: true }) // e.g. the screen is busy with something else
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false)
    await option(wrapper, 1).trigger('click')
    expect(answers(wrapper)).toEqual([[{ option_id: 'oB2' }]])
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')

    const wrapper = await lostReply()

    expect(wrapper.get('[data-testid=resend-btn]').text()).toBe('Qayta urinish')
    expect(wrapper.get('[data-testid=collocation-resend-note]').text()).toBe("Serverdan javob kelmadi. Javobingizni endi o'zgartirib bo'lmaydi — uni yana yuboring.")
  })

  it('in a battle there is no such thing: the battle screen has its own retry, and the component stays as the final answer', async () => {
    const wrapper = render({ mode: 'battle' })
    await option(wrapper, 1).trigger('click')
    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('[data-testid=resend-btn]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=collocation-resend-note]').exists()).toBe(false)
    expect(wrapper.find('[data-testid=actions]').exists()).toBe(false)
  })
})

describe('CollocationChoice — a battle', () => {
  it('says that the answer is final, before it is given', () => {
    const wrapper = render({ mode: 'battle' })

    expect(wrapper.get('[data-testid=collocation-choice]').attributes('data-mode')).toBe('battle')
    expect(wrapper.get('[data-testid=collocation-footnote]').text()).toBe('Ответ окончательный: изменить нельзя.')
    expect(render({ mode: 'solo' }).get('[data-testid=collocation-footnote]').text()).toBe('Ответ проверяется сразу после нажатия.')
  })

  it('sends one answer with one tap, the same as in a solo session', async () => {
    const wrapper = render({ mode: 'battle' })

    await option(wrapper, 2).trigger('click')
    await option(wrapper, 2).trigger('click')
    await option(wrapper, 0).trigger('click')

    expect(answers(wrapper)).toEqual([[{ option_id: 'oC3' }]])
  })

  it('stays locked for good after the tap, even when the screen lets go of `disabled`: the answer is not taken back', async () => {
    const wrapper = render({ mode: 'battle' })
    await option(wrapper, 1).trigger('click')
    await wrapper.setProps({ disabled: true })

    await wrapper.setProps({ disabled: false })
    await option(wrapper, 0).trigger('click')

    expect(answers(wrapper)).toEqual([[{ option_id: 'oB2' }]])
    expect(option(wrapper, 1).attributes('data-state')).toBe('selected')
    expect(option(wrapper, 1).attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('have')
  })

  it('shows the answer that was given while it waits for the rival: the word in the blank, the marked option, no verdict', async () => {
    const wrapper = render({ mode: 'battle' })

    await option(wrapper, 0).trigger('click')
    await wrapper.setProps({ disabled: true })

    expect(wrapper.get('[data-testid=collocation-blank]').text()).toBe('make')
    expect(option(wrapper, 0).attributes('data-state')).toBe('selected')
    expect(wrapper.get('[data-testid=option-caption-0]').text()).toBe('ваш ответ')
    // Neither the right option nor a verdict: the server shows them after the round (docs/15 §3.5).
    expect(wrapper.findAll('[data-state=correct], [data-state=incorrect], [data-state=reveal]')).toHaveLength(0)
    expect(wrapper.find('[data-testid=collocation-verdict]').exists()).toBe(false)
  })

  it('does not answer at all while the battle screen keeps it locked (the round is not open yet, the answer was given)', async () => {
    const wrapper = render({ mode: 'battle', disabled: true })

    await option(wrapper, 0).trigger('click')

    expect(answers(wrapper)).toHaveLength(0)
  })
})

describe('CollocationChoice — a new element is a new component', () => {
  it('carries nothing over when it is mounted for the next element (the screen keys it)', async () => {
    const first = render()
    await option(first, 0).trigger('click')

    const next = render({ element: collocationElement({ id: 'eColl00002', text: 'She wants to ___ a break.', options: [{ id: 'oD4', text: 'take' }, { id: 'oE5', text: 'make' }, { id: 'oF6', text: 'do' }] }) })

    expect(next.get('[data-testid=collocation-blank]').attributes('data-tone')).toBe('empty')
    expect(next.findAll('button').map((button) => button.attributes('data-state'))).toEqual(['idle', 'idle', 'idle'])
    await option(next, 0).trigger('click')
    expect(answers(next)).toEqual([[{ option_id: 'oD4' }]])
  })
})
