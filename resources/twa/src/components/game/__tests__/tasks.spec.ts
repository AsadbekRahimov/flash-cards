import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import ChoiceTask from '../ChoiceTask.vue'
import GapTask from '../GapTask.vue'
import PracticeTask from '../PracticeTask.vue'
import SpellTask from '../SpellTask.vue'
import CollocationChoice from '@/components/practice/CollocationChoice.vue'
import DictationInput from '@/components/practice/DictationInput.vue'
import PairsBoard from '@/components/practice/PairsBoard.vue'
import SentenceBuilder from '@/components/practice/SentenceBuilder.vue'
import { taskComponentFor } from '../gameTasks'
import { isPracticeElement, practiceElementOf, removedOptions } from '../practiceAdapter'
import { setLocale } from '@/i18n'
import { collocation, dictation, gap, listen, pairs, sentence, spell, translateBack, translateFwd } from '@/api/__tests__/gameRunFixtures'

const audioBlob = vi.hoisted(() => vi.fn())
vi.mock('@/api', () => ({ api: { gameRuns: { audioBlob }, practice: { audioBlob } } }))

beforeEach(() => {
  setActivePinia(createPinia())
  audioBlob.mockReset()
  audioBlob.mockResolvedValue(new Blob(['x']))
  URL.createObjectURL = vi.fn(() => 'blob:clip')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => setLocale('ru'))

describe('ChoiceTask', () => {
  it.each([
    ['translate_fwd', translateFwd(), 'apple', 'en'],
    ['translate_back', translateBack(), 'хлеб', null],
  ] as const)('%s: the prompt, the title of the kind and four options of at least 44 px (the design system .option)', (_kind, element, prompt, lang) => {
    const wrapper = mount(ChoiceTask, { props: { element } })

    expect(wrapper.get('[data-testid=task-prompt]').text()).toBe(prompt)
    expect(wrapper.get('[data-testid=task-prompt]').attributes('lang')).toBe(lang ?? undefined)
    expect(wrapper.get('[data-testid=task-title]').text()).not.toBe('')
    const options = wrapper.findAll('.option')
    expect(options).toHaveLength(4)
    for (const option of options) expect(option.classes()).toContain('option')
  })

  it('a tap on an option answers with its id, once; the options are locked after it', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd() } })

    await wrapper.get('[data-testid=option-1]').trigger('click')
    await wrapper.get('[data-testid=option-2]').trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ option_id: 'o2' }]])
    expect(wrapper.get('[data-testid=option-1]').attributes('data-state')).toBe('selected')
    expect(wrapper.get('[data-testid=option-0]').attributes('disabled')).toBeDefined()
  })

  it('"I don\'t know" sends skip, once', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd() } })

    await wrapper.get('[data-testid=skip-btn]').trigger('click')
    await wrapper.get('[data-testid=skip-btn]').trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
  })

  it('does not answer while disabled (an answer is on its way)', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd(), disabled: true } })

    await wrapper.get('[data-testid=option-0]').trigger('click')

    expect(wrapper.emitted('answer')).toBeUndefined()
  })

  it('a hint of the bazaar dims the options it took away and they cannot be picked', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd({ hint: { options_removed: ['o3', 'o4'] } }) } })

    expect(wrapper.get('[data-testid=option-2]').attributes('data-removed')).toBe('true')
    expect(wrapper.get('[data-testid=option-2]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid=option-0]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid=option-2]').trigger('click')
    expect(wrapper.emitted('answer')).toBeUndefined()
  })

  it('a reply that does not come keeps the pick and offers to send the same again', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd() } })
    await wrapper.get('[data-testid=option-1]').trigger('click')

    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('[data-testid=resend-note]').exists()).toBe(true)
    expect(wrapper.find('[data-testid=skip-btn]').exists()).toBe(false)
    await wrapper.get('[data-testid=resend-btn]').trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ option_id: 'o2' }], [{ option_id: 'o2' }]])
  })

  it('a skip that does not get its reply is sent again as a skip', async () => {
    const wrapper = mount(ChoiceTask, { props: { element: translateFwd() } })
    await wrapper.get('[data-testid=skip-btn]').trigger('click')

    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })
    await wrapper.get('[data-testid=resend-btn]').trigger('click')

    expect(wrapper.emitted('skip')).toHaveLength(2)
  })

  describe('listen', () => {
    it('loads the clip of the element with the token, and "Listen" plays it from the start', async () => {
      const play = vi.fn().mockResolvedValue(undefined)
      const created: string[] = []
      vi.stubGlobal(
        'Audio',
        class {
          constructor(src: string) {
            created.push(src)
          }
          addEventListener() {}
          pause() {}
          play = play
        },
      )
      const wrapper = mount(ChoiceTask, { props: { element: listen() } })
      await vi.waitFor(() => expect(wrapper.get('[data-testid=audio-state]').attributes('data-state')).toBe('ready'))

      expect(audioBlob).toHaveBeenCalledWith('/api/twa/game/runs/9/audio/e3', expect.anything())
      await wrapper.get('[data-testid=listen-btn]').trigger('click')
      await wrapper.get('[data-testid=listen-btn]').trigger('click')

      expect(created).toEqual(['blob:clip', 'blob:clip'])
      expect(play).toHaveBeenCalledTimes(2)
      expect(wrapper.get('[data-testid=task-title]').text()).toBe('Послушайте и выберите слово')
      expect(wrapper.findAll('.option')).toHaveLength(4)
      vi.unstubAllGlobals()
    })

    it('a clip that does not load turns the button into "Retry", and a tap loads it again', async () => {
      audioBlob.mockRejectedValueOnce(new Error('down')).mockResolvedValue(new Blob(['x']))
      const wrapper = mount(ChoiceTask, { props: { element: listen({ id: 'e30', audio_url: '/api/twa/game/runs/9/audio/e30' }) } })
      await vi.waitFor(() => expect(wrapper.get('[data-testid=audio-state]').attributes('data-state')).toBe('failed'))
      expect(wrapper.get('[data-testid=listen-btn]').attributes('aria-label')).toBe('Повторить загрузку')

      await wrapper.get('[data-testid=listen-btn]').trigger('click')

      await vi.waitFor(() => expect(wrapper.get('[data-testid=audio-state]').attributes('data-state')).toBe('ready'))
      expect(audioBlob).toHaveBeenCalledTimes(2)
    })

    it('the other kinds have no listen button', () => {
      expect(mount(ChoiceTask, { props: { element: translateFwd() } }).find('[data-testid=listen-btn]').exists()).toBe(false)
    })
  })
})

describe.each([
  ['SpellTask', SpellTask, spell(), 'яблоко'],
  ['GapTask', GapTask, gap(), 'I ____ tea every morning.'],
] as const)('%s', (_name, component, element, prompt) => {
  const render = (props: Record<string, unknown> = {}) => mount(component as typeof SpellTask, { props: { element: element as never, ...props } })

  it('shows the prompt and a field that the keyboard cannot fix', () => {
    const wrapper = render()
    const input = wrapper.get('[data-testid=answer-input]')

    expect(wrapper.get('[data-testid=task-prompt]').text()).toBe(prompt)
    expect(input.attributes('autocapitalize')).toBe('off')
    expect(input.attributes('autocorrect')).toBe('off')
    expect(input.attributes('autocomplete')).toBe('off')
    expect(input.attributes('spellcheck')).toBe('false')
    expect(input.attributes('lang')).toBe('en')
  })

  it('"Check" is off until something is typed; then it sends the trimmed text, once', async () => {
    const wrapper = render()
    expect(wrapper.get('[data-testid=check-btn]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid=answer-input]').setValue('  apple ')
    await wrapper.get('[data-testid=check-btn]').trigger('click')
    await wrapper.get('[data-testid=check-btn]').trigger('click')

    expect(wrapper.emitted('answer')).toEqual([[{ text: 'apple' }]])
    expect(wrapper.get('[data-testid=answer-input]').attributes('readonly')).toBeDefined()
  })

  it('Enter checks', async () => {
    const wrapper = render()
    await wrapper.get('[data-testid=answer-input]').setValue('apple')
    await wrapper.get('[data-testid=answer-input]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('answer')).toEqual([[{ text: 'apple' }]])
  })

  it('"I don\'t know" sends skip, even with nothing typed', async () => {
    const wrapper = render()
    await wrapper.get('[data-testid=skip-btn]').trigger('click')
    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('answer')).toBeUndefined()
  })

  it('tells the first letters bought at the bazaar, and leaves the field empty', () => {
    const wrapper = render({ element: { ...element, hint: { prefix: 'ap' } } })
    expect(wrapper.get('[data-testid=hint-prefix]').text()).toBe('Начинается на «ap»')
    expect((wrapper.get('[data-testid=answer-input]').element as HTMLInputElement).value).toBe('')
  })

  it('a reply that does not come keeps the text read-only and sends the same again', async () => {
    const wrapper = render()
    await wrapper.get('[data-testid=answer-input]').setValue('apple')
    await wrapper.get('[data-testid=check-btn]').trigger('click')

    await wrapper.setProps({ disabled: true })
    await wrapper.setProps({ disabled: false })

    expect(wrapper.find('[data-testid=resend-note]').exists()).toBe(true)
    expect(wrapper.get('[data-testid=skip-btn]').attributes('disabled')).toBeDefined() // the answer that went is the one that is repeated
    await wrapper.get('[data-testid=check-btn]').trigger('click')
    expect(wrapper.emitted('answer')).toEqual([[{ text: 'apple' }], [{ text: 'apple' }]])
  })

  it('does nothing while an answer is on its way', async () => {
    const wrapper = render({ disabled: true })
    await wrapper.get('[data-testid=answer-input]').setValue('apple')
    await wrapper.get('[data-testid=check-btn]').trigger('click')
    expect(wrapper.emitted('answer')).toBeUndefined()
  })
})

describe('SpellTask — the length', () => {
  it('tells how many letters the word has', () => {
    expect(mount(SpellTask, { props: { element: spell() } }).get('[data-testid=answer-length]').text()).toBe('Букв: 5')
  })
})

describe('GapTask — the example', () => {
  it('shows the translation under the example', () => {
    expect(mount(GapTask, { props: { element: gap() } }).get('[data-testid=task-translation]').text()).toBe('пить')
  })
})

describe('the adapter to the components of the practice', () => {
  it('draws each practice kind with the component of the practice, not with a copy', () => {
    const cases = [
      [pairs(), PairsBoard],
      [collocation(), CollocationChoice],
      [sentence(), SentenceBuilder],
      [dictation(), DictationInput],
    ] as const

    for (const [element, component] of cases) {
      const wrapper = mount(PracticeTask, { props: { element } })
      expect(wrapper.findComponent(component).exists(), element.kind).toBe(true)
      expect(wrapper.findComponent(component).props('mode'), element.kind).toBe('solo')
    }
  })

  it('hands the component the element, `disabled`, and passes its events up', async () => {
    const wrapper = mount(PracticeTask, { props: { element: collocation(), disabled: true } })
    const inner = wrapper.findComponent(CollocationChoice)

    expect(inner.props('element').id).toBe('e7')
    expect(inner.props('disabled')).toBe(true)

    inner.vm.$emit('answer', { option_id: 'c1' })
    inner.vm.$emit('skip')
    inner.vm.$emit('silent')
    await nextTick()

    expect(wrapper.emitted('answer')).toEqual([[{ option_id: 'c1' }]])
    expect(wrapper.emitted('skip')).toHaveLength(1)
    expect(wrapper.emitted('silent')).toHaveLength(1)
  })

  it('a hint on a collocation takes the options away before the component sees them', () => {
    const wrapper = mount(PracticeTask, { props: { element: { ...collocation(), hint: { options_removed: ['c2', 'c3'] } } } })

    expect(wrapper.findAll('.option').map((option) => option.text())).toEqual(['have'])
    expect(wrapper.findComponent(CollocationChoice).props('element')).not.toHaveProperty('hint')
  })

  it('knows the practice kinds and which options a hint removed', () => {
    expect(isPracticeElement(pairs())).toBe(true)
    expect(isPracticeElement(dictation())).toBe(true)
    expect(isPracticeElement(spell())).toBe(false)
    expect(isPracticeElement(translateFwd())).toBe(false)
    expect([...removedOptions(translateFwd({ hint: { options_removed: ['o1'] } }))]).toEqual(['o1'])
    expect([...removedOptions(spell({ hint: { prefix: 'ap' } }))]).toEqual([])
    expect(practiceElementOf(pairs())).toEqual(pairs())
  })

  it('picks the component by the kind of the element of a run', () => {
    expect(taskComponentFor(translateFwd())).toBe(ChoiceTask)
    expect(taskComponentFor(translateBack())).toBe(ChoiceTask)
    expect(taskComponentFor(listen())).toBe(ChoiceTask)
    expect(taskComponentFor(spell())).toBe(SpellTask)
    expect(taskComponentFor(gap())).toBe(GapTask)
    for (const element of [pairs(), collocation(), sentence(), dictation()]) expect(taskComponentFor(element)).toBe(PracticeTask)
  })
})
