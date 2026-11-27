import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import OfflineScreen from '../OfflineScreen.vue'
import { setLocale } from '@/i18n'

function render(props: { chip: string | null; pendingAnswer: boolean }) {
  return mount(OfflineScreen, { props, attachTo: document.body })
}

describe('OfflineScreen (F5 «Нет сети»)', () => {
  afterEach(() => {
    setLocale('ru')
    document.body.innerHTML = ''
  })

  it('the thinking mascot, the title, the text and one "Retry"; no offline review button (docs/40 §3, В7)', async () => {
    const w = render({ chip: null, pendingAnswer: false })
    await flushPromises()

    expect(w.get('[data-testid=mascot-art]').attributes('data-pose')).toBe('thinking')
    expect(w.get('[data-testid=offline-title]').text()).toBe('Нет соединения')
    expect(w.get('[data-testid=offline-text]').text()).toContain('Как только сеть вернётся')
    expect(w.findAll('button')).toHaveLength(1)
    expect(w.get('[data-testid=offline-retry]').text()).toBe('Повторить')
    expect(w.text()).not.toContain('офлайн')
    w.unmount()
  })

  it('puts the focus on "Retry" and lets a screen reader hear what happened', async () => {
    const w = render({ chip: null, pendingAnswer: false })
    await flushPromises()

    const button = w.get('[data-testid=offline-retry]')
    expect(document.activeElement).toBe(button.element)
    const region = w.get('[role=status]')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(button.attributes('aria-describedby')).toBe(region.attributes('id'))
    w.unmount()
  })

  it('"Retry" emits retry', async () => {
    const w = render({ chip: null, pendingAnswer: false })
    await w.get('[data-testid=offline-retry]').trigger('click')

    expect(w.emitted('retry')).toHaveLength(1)
    w.unmount()
  })

  it('the chip only when there is something true to say', () => {
    const without = render({ chip: null, pendingAnswer: false })
    expect(without.find('[data-testid=offline-chip]').exists()).toBe(false)
    without.unmount()

    const withChip = render({ chip: 'Урок 3 · сохранено 4 из 8', pendingAnswer: false })
    expect(withChip.get('[data-testid=offline-chip]').text()).toBe('Урок 3 · сохранено 4 из 8')
    withChip.unmount()
  })

  it('says that the waiting answer will be sent', () => {
    const w = render({ chip: null, pendingAnswer: true })

    expect(w.get('[data-testid=offline-text]').text()).toContain('Последний ответ сохранён на телефоне')
    w.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render({ chip: null, pendingAnswer: false })

    expect(w.get('[data-testid=offline-title]').text()).toBe("Aloqa yo'q")
    expect(w.get('[data-testid=offline-retry]').text()).toBe("Qayta urinib ko'rish")
    w.unmount()
  })
})
