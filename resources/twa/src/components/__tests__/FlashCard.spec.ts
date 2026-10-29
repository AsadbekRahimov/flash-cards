import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FlashCard from '../FlashCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { Card } from '@/api/types'
import { setLocale } from '@/i18n'

// Stub Telegram SDK and TTS to avoid DOM/Telegram dependencies in unit tests.
vi.mock('@/composables/useTelegram', () => ({
  useTelegram: () => ({
    haptic: vi.fn(),
    selectionHaptic: vi.fn(),
  }),
}))
const { speak } = vi.hoisted(() => ({ speak: vi.fn().mockResolvedValue(undefined) }))
vi.mock('@/composables/useTts', () => ({
  useTts: () => ({ supported: false, speak }),
}))

const card: Card = {
  word_id: 1,
  word: 'Resilient',
  translation: 'Устойчивый',
  translation_fallback: false,
  example: 'She is a resilient girl.',
  transcription: 'rɪˈzɪl.i.ənt',
  card_kind: 'new',
  serve_token: null,
}

describe('FlashCard', () => {
  afterEach(() => {
    setLocale('ru')
    speak.mockClear()
  })

  it('hands the pre-generated audio_url to the speaker', async () => {
    const w = mount(FlashCard, {
      props: { card: { ...card, audio_url: 'https://x.test/storage/audio/a.mp3' }, showTranslation: false },
    })
    await w.get('button[aria-label]').trigger('click')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', 'https://x.test/storage/audio/a.mp3')

    speak.mockClear()
    await mount(FlashCard, { props: { card, showTranslation: false } }).get('button[aria-label]').trigger('click')
    expect(speak).toHaveBeenCalledWith('Resilient', 'en-US', undefined)
  })

  it('has a round speaker button next to the type chip', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    const speaker = w.get('[data-testid=speak-btn]')

    expect(speaker.attributes('aria-label')).toBe('Произнести')
    expect(speaker.classes()).toEqual(expect.arrayContaining(['btn-tinted', 'rounded-full']))
    expect(speaker.findComponent(AppIcon).props('name')).toBe('speaker-wave')
    expect(w.get('header').find('[data-testid=card-kind]').exists()).toBe(true)
  })

  it('shows the word, transcription and hides translation by default', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    expect(w.get('[data-testid=word]').text()).toBe('Resilient')
    expect(w.get('[data-testid=transcription]').text()).toBe('rɪˈzɪl.i.ənt')
    expect(w.find('[data-testid=translation]').exists()).toBe(false)
    expect(w.find('[data-testid=reveal-btn]').exists()).toBe(true)
    // Before the reveal the only action is "Show translation": no question, no grades.
    expect(w.find('[data-testid=grade-prompt]').exists()).toBe(false)
    expect(w.find('[data-testid=grade-hard]').exists()).toBe(false)
  })

  it('has one big primary button before the reveal, in the sticky block', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    const reveal = w.get('[data-testid=reveal-btn]')

    expect(reveal.text()).toBe('Показать перевод')
    expect(reveal.classes()).toEqual(expect.arrayContaining(['btn', 'btn-primary', 'btn-lg', 'btn-block']))
    expect(w.get('[data-testid=actions]').find('[data-testid=reveal-btn]').exists()).toBe(true)
  })

  it('emits reveal when "Show translation" is clicked', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: false } })
    await w.get('[data-testid=reveal-btn]').trigger('click')
    expect(w.emitted('reveal')).toHaveLength(1)
  })

  it('renders translation and 3 grade buttons when revealed', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(w.get('[data-testid=translation]').text()).toBe('Устойчивый')
    expect(w.get('[data-testid=example]').text()).toContain('resilient')
    expect(w.find('[data-testid=grade-hard]').exists()).toBe(true)
    expect(w.find('[data-testid=grade-medium]').exists()).toBe(true)
    expect(w.find('[data-testid=grade-easy]').exists()).toBe(true)
    expect(w.find('[data-testid=reveal-btn]').exists()).toBe(false)
  })

  it('asks how well the word is remembered and labels the grades with what happens next', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })

    expect(w.get('[data-testid=grade-prompt]').text()).toBe('Насколько хорошо вы помните слово?')
    const group = w.get('[role=group]')
    expect(group.attributes('aria-label')).toBe('Оценка')
    expect(group.findAll('button')).toHaveLength(3)

    expect(w.get('[data-testid=grade-hard]').text()).toBe('Не помнюповторим сегодня')
    expect(w.get('[data-testid=grade-medium]').text()).toBe('Помнюпо расписанию')
    expect(w.get('[data-testid=grade-easy]').text()).toBe('Легкопокажем реже')
    // "Hard" used to mean "failed": the old labels are gone.
    expect(w.text()).not.toContain('Трудно')
    expect(w.text()).not.toContain('Средне')
  })

  it('colours the grades danger / accent / success, without emoji', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })

    expect(w.get('[data-testid=grade-hard]').classes()).toContain('btn-danger')
    expect(w.get('[data-testid=grade-medium]').classes()).toContain('btn-tinted')
    expect(w.get('[data-testid=grade-easy]').classes()).toEqual(
      expect.arrayContaining(['bg-success-soft', 'text-success-ink']),
    )
    for (const id of ['grade-hard', 'grade-medium', 'grade-easy']) {
      expect(w.get(`[data-testid=${id}]`).text()).not.toMatch(/\p{Extended_Pictographic}/u)
    }
  })

  it('writes the hints in a colour of their own, not faded with opacity (12 px text must read in a light theme)', () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    const hints = w.findAll('[data-testid=grade-hint]')

    expect(hints.map((hint) => hint.text())).toEqual(['повторим сегодня', 'по расписанию', 'покажем реже'])
    for (const hint of hints) {
      expect(hint.classes().filter((name) => name.startsWith('opacity-'))).toEqual([])
      // The colour of the button mixed with the theme text colour (docs/14 §4.1: 4.5:1 and better on the soft fills).
      expect(hint.classes().some((name) => name.startsWith('text-[color-mix('))).toBe(true)
    }
  })

  it('notes that the translation is a Russian fallback only when the server says so', () => {
    const plain = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(plain.find('[data-testid=translation-fallback]').exists()).toBe(false)

    const fallback = mount(FlashCard, {
      props: { card: { ...card, translation_fallback: true }, showTranslation: true },
    })
    expect(fallback.get('[data-testid=translation]').text()).toBe('Устойчивый')
    expect(fallback.get('[data-testid=translation-fallback]').text()).toBe(
      'Перевода на язык занятия пока нет',
    )

    setLocale('uz')
    const uz = mount(FlashCard, {
      props: { card: { ...card, translation_fallback: true }, showTranslation: true },
    })
    expect(uz.get('[data-testid=translation-fallback]').text()).toBe("Mashg'ulot tilida tarjima hali yo'q")

    // Hidden translation: no note either.
    const hidden = mount(FlashCard, {
      props: { card: { ...card, translation_fallback: true }, showTranslation: false },
    })
    expect(hidden.find('[data-testid=translation-fallback]').exists()).toBe(false)
  })

  it('emits grade event with quality 2 / 4 / 5 (Again / Good / Easy) per button', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true } })
    await w.get('[data-testid=grade-hard]').trigger('click')
    await w.get('[data-testid=grade-medium]').trigger('click')
    await w.get('[data-testid=grade-easy]').trigger('click')
    const events = w.emitted('grade') as unknown[][] | undefined
    expect(events).toHaveLength(3)
    expect(events?.[0]).toEqual([2])
    expect(events?.[1]).toEqual([4])
    expect(events?.[2]).toEqual([5])
  })

  it('labels the card by its kind: new (accent), learning-step (warning), due (neutral)', () => {
    const chip = (card_kind: Card['card_kind']) => {
      const w = mount(FlashCard, { props: { card: { ...card, card_kind }, showTranslation: false } })
      return { text: w.get('[data-testid=card-kind]').text(), classes: w.get('[data-testid=card-kind]').classes() }
    }

    expect(chip('new')).toMatchObject({ text: 'Новое слово' })
    expect(chip('new').classes).toEqual(expect.arrayContaining(['chip', 'chip-accent']))
    expect(chip('learning')).toMatchObject({ text: 'Закрепление' })
    expect(chip('learning').classes).toEqual(expect.arrayContaining(['chip', 'chip-warning']))
    expect(chip('due')).toMatchObject({ text: 'Повторение' })
    expect(chip('due').classes).toEqual(expect.arrayContaining(['chip', 'chip-neutral']))
  })

  it('does not emit grade when disabled', async () => {
    const w = mount(FlashCard, { props: { card, showTranslation: true, disabled: true } })
    await w.get('[data-testid=grade-easy]').trigger('click')
    expect(w.emitted('grade')).toBeUndefined()
  })

  it('renders the Uzbek interface (kind labels, reveal and grade buttons) when locale is uz', async () => {
    setLocale('uz')
    const kinds: [Card['card_kind'], string][] = [
      ['new', "Yangi so'z"],
      ['learning', 'Mustahkamlash'],
      ['due', 'Takrorlash'],
    ]
    for (const [card_kind, label] of kinds) {
      expect(mount(FlashCard, { props: { card: { ...card, card_kind }, showTranslation: false } }).text()).toContain(label)
    }

    const hidden = mount(FlashCard, { props: { card, showTranslation: false } })
    expect(hidden.get('[data-testid=reveal-btn]').text()).toBe("Tarjimani ko'rsatish")

    const revealed = mount(FlashCard, { props: { card, showTranslation: true } })
    expect(revealed.get('[data-testid=grade-prompt]').text()).toBe("So'zni qanchalik yaxshi eslaysiz?")
    expect(revealed.get('[data-testid=grade-hard]').text()).toContain('Eslay olmadim')
    expect(revealed.get('[data-testid=grade-hard]').text()).toContain('bugun takrorlaymiz')
    expect(revealed.get('[data-testid=grade-medium]').text()).toContain('Esladim')
    expect(revealed.get('[data-testid=grade-medium]').text()).toContain("jadval bo'yicha")
    expect(revealed.get('[data-testid=grade-easy]').text()).toContain('Oson')
    expect(revealed.get('[data-testid=grade-easy]').text()).toContain("kamroq ko'rsatamiz")
  })
})
