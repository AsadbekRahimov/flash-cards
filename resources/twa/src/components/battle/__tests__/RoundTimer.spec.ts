import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import RoundTimer from '../RoundTimer.vue'
import { SERVER_NOW, at } from '@/api/__tests__/battleFixtures'
import { setLocale } from '@/i18n'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => {
  vi.useRealTimers()
  setLocale('ru')
})

function render(props: Partial<{ deadline: string | null; offsetMs: number; totalSeconds: number }> = {}) {
  return mount(RoundTimer, { props: { deadline: at(32), offsetMs: 0, totalSeconds: 45, ...props } })
}

type Wrapper = ReturnType<typeof render>
const text = (w: Wrapper) => w.get('[data-testid=timer-text]').text()
const live = (w: Wrapper) => w.get('[data-testid=timer-live]').text()

/** Moves the clock and lets the component redraw. */
async function pass(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await nextTick()
}

describe('RoundTimer', () => {
  it('is a timer with a name, and shows the minutes and seconds to the deadline of the server', () => {
    const w = render()

    expect(w.get('[role=timer]').attributes('aria-label')).toBe('Время раунда')
    expect(text(w)).toBe('0:32')
    expect(w.get('[role=timer]').attributes('data-state')).toBe('ok')
  })

  it('counts down with the clock', async () => {
    const w = render()

    await pass(1000)
    expect(text(w)).toBe('0:31')
    await pass(4000)
    expect(text(w)).toBe('0:27')
  })

  it('counts to the deadline of the SERVER: a device clock that is behind does not give more time', () => {
    // the server is 5 s ahead of the device, so the deadline is 5 s closer than the device clock thinks
    const w = render({ offsetMs: 5000 })

    expect(text(w)).toBe('0:27')
  })

  it('shows a whole second until the very last moment (rounded up), and a minute as 1:00', async () => {
    const w = render({ deadline: at(60) })
    expect(text(w)).toBe('1:00')

    await pass(59_500)
    expect(text(w)).toBe('0:01')
  })

  it('draws the share of the round that is left as a bar', async () => {
    const w = render({ deadline: at(45), totalSeconds: 45 })
    expect(w.get('[data-testid=timer-fill]').attributes('style')).toContain('width: 100%')

    await pass(22_500)
    expect(w.get('[data-testid=timer-fill]').attributes('style')).toContain('width: 50%')
  })

  it('turns warm in the last ten seconds: the warning colour with a symbol, never the red of an error', async () => {
    const w = render({ deadline: at(11) })
    expect(w.get('[role=timer]').attributes('data-state')).toBe('ok')

    await pass(1000)
    const timer = w.get('[role=timer]')
    expect(timer.attributes('data-state')).toBe('low')
    expect(w.get('[data-testid=timer-text]').classes()).toContain('text-warning-ink')
    expect(w.get('[data-testid=timer-fill]').classes()).toContain('bg-warning')
    expect(timer.find('svg').exists()).toBe(true)
    expect(timer.html()).not.toMatch(/danger|red-/)
  })

  it('says “Time is up” at 0 and waits for the server: it does not go below 0 or hide', async () => {
    const w = render({ deadline: at(2) })

    await pass(2000)
    expect(text(w)).toBe('Время вышло')
    expect(w.get('[role=timer]').attributes('data-state')).toBe('over')
    expect(w.get('[data-testid=timer-fill]').attributes('style')).toContain('width: 0%')

    await pass(5000)
    expect(text(w)).toBe('Время вышло')
    expect(text(w)).not.toMatch(/-/)
  })

  it('hides the changing digits from a screen reader and tells the time left in a polite live region', () => {
    const w = render()

    expect(w.get('[data-testid=timer-text]').attributes('aria-hidden')).toBe('true')
    const region = w.get('[data-testid=timer-live]')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(region.classes()).toContain('sr-only')
  })

  it('announces every ten seconds and every second of the last five, and “Time is up” at 0 - not every second', async () => {
    const w = render({ deadline: at(32) })
    const heard: string[] = []
    const listen = () => {
      const now = live(w)
      if (now !== '' && heard[heard.length - 1] !== now) heard.push(now)
    }

    expect(live(w)).toBe('') // 32 s: nothing worth a word yet
    for (let i = 0; i < 34 * 4; i++) {
      await pass(250)
      listen()
    }

    expect(heard).toEqual([
      'Осталось 30 с',
      'Осталось 20 с',
      'Осталось 10 с',
      'Осталось 5 с',
      'Осталось 4 с',
      'Осталось 3 с',
      'Осталось 2 с',
      'Осталось 1 с',
      'Время вышло',
    ])
  })

  it('announces at the start too when the round begins on a round number of seconds', () => {
    const w = render({ deadline: at(30) })

    expect(live(w)).toBe('Осталось 30 с')
  })

  it('speaks Uzbek', async () => {
    setLocale('uz')
    const w = render({ deadline: at(1) })

    expect(w.get('[role=timer]').attributes('aria-label')).toBe('Raund vaqti')
    expect(live(w)).toBe('1 soniya qoldi')
    await pass(1000)
    expect(text(w)).toBe('Vaqt tugadi')
  })

  it('without a deadline shows a full bar and the length of the round, and does not fail', () => {
    const w = render({ deadline: null, totalSeconds: 45 })

    expect(text(w)).toBe('0:45')
    expect(w.get('[data-testid=timer-fill]').attributes('style')).toContain('width: 100%')
    expect(w.get('[role=timer]').attributes('data-state')).toBe('ok')
  })

  it('follows a new deadline (the next round)', async () => {
    const w = render({ deadline: at(5) })
    await pass(4000)
    expect(text(w)).toBe('0:01')

    // four seconds have passed since the start of the spec: a deadline 49 s after it is 45 s away
    await w.setProps({ deadline: at(49) })
    expect(text(w)).toBe('0:45')
    expect(w.get('[role=timer]').attributes('data-state')).toBe('ok')
  })

  it('stops its clock when it leaves the screen', () => {
    const w = render()
    expect(vi.getTimerCount()).toBeGreaterThan(0)

    w.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})
