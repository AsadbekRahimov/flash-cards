import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LeaveConfirm from '../LeaveConfirm.vue'
import { setLocale } from '@/i18n'

beforeEach(() => {
  document.body.innerHTML = ''
})
afterEach(() => {
  setLocale('ru')
  document.body.innerHTML = ''
})

const render = (props: { variant: 'match' | 'room'; invite?: boolean; busy?: boolean }) => mount(LeaveConfirm, { props, attachTo: document.body })

describe('LeaveConfirm — in the match', () => {
  it('asks “Surrender and leave?”: a danger button “Surrender” and a calm one “Stay”', async () => {
    const w = render({ variant: 'match' })

    expect(w.get('[role=dialog]').attributes('aria-label')).toBe('Сдаться и выйти?')
    expect(w.get('[data-testid=leave-title]').text()).toBe('Сдаться и выйти?')
    expect(w.get('[data-testid=leave-text]').text()).toBe('Вам засчитают поражение, а победа достанется сопернику.')
    expect(w.get('[data-testid=leave-yes]').text()).toBe('Сдаться')
    expect(w.get('[data-testid=leave-yes]').classes()).toContain('btn-danger')
    expect(w.get('[data-testid=leave-stay]').text()).toBe('Остаться')
    expect(w.get('[data-testid=leave-stay]').classes()).toContain('btn-secondary')
    expect(w.findAll('.btn-primary')).toHaveLength(0)

    await w.get('[data-testid=leave-yes]').trigger('click')
    expect(w.emitted('confirm')).toHaveLength(1)
    w.unmount()
  })

  it('is a bottom sheet (a dialog)', () => {
    const w = render({ variant: 'match' })

    expect(w.get('[role=dialog]').classes()).toContain('sheet')
    expect(w.get('[role=dialog]').attributes('aria-modal')).toBe('true')
    expect(w.get('[data-testid=leave-confirm]').attributes('data-variant')).toBe('match')
    w.unmount()
  })
})

describe('LeaveConfirm — in the room', () => {
  it('asks “Leave the room?” and says the battle is cancelled', () => {
    const w = render({ variant: 'room' })

    expect(w.get('[data-testid=leave-title]').text()).toBe('Выйти из комнаты?')
    expect(w.get('[data-testid=leave-text]').text()).toBe('Матч отменится, соперник узнает об этом.')
    expect(w.get('[data-testid=leave-yes]').text()).toBe('Выйти')
    w.unmount()
  })

  it('for an invitation that nobody has accepted yet it says it is withdrawn', () => {
    const w = render({ variant: 'room', invite: true })

    expect(w.get('[data-testid=leave-text]').text()).toBe('Приглашение будет отозвано.')
    w.unmount()
  })
})

describe('LeaveConfirm — staying', () => {
  it('“Stay”, the ✕, the Escape key and the dimmed page all mean: nothing happens', async () => {
    const w = render({ variant: 'match' })

    await w.get('[data-testid=leave-stay]').trigger('click')
    await w.get('[data-testid=sheet-x]').trigger('click')
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await w.get('[data-testid=sheet-backdrop]').trigger('click')

    expect(w.emitted('stay')).toHaveLength(4)
    expect(w.emitted('confirm')).toBeUndefined()
    w.unmount()
  })

  it('both buttons wait while the answer of the server is awaited', () => {
    const w = render({ variant: 'match', busy: true })

    expect(w.get('[data-testid=leave-yes]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-testid=leave-stay]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('speaks Uzbek', () => {
    setLocale('uz')
    const w = render({ variant: 'match' })

    expect(w.get('[data-testid=leave-title]').text()).toBe("Taslim bo'lib chiqasizmi?")
    expect(w.get('[data-testid=leave-yes]').text()).toBe("Taslim bo'lish")
    expect(w.get('[data-testid=leave-stay]').text()).toBe('Qolish')
    w.unmount()
  })
})
