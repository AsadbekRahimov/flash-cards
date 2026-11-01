import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import AbsentStrip from '../AbsentStrip.vue'
import { setLocale } from '@/i18n'

/*
 * "The opponent lost connection. If they do not come back in N s, the victory is yours" (docs/16 §8.4): N is what is left of the wait of
 * the SERVER - `opponent_absent_since + presence_grace_seconds − server now` - so a screen opened in the middle of the wait shows the same
 * number as one that has been here all along, and it never goes below 0.
 */

/** Now, by the clock of the page and of the (equal) server. */
const NOW = Date.parse('2026-10-30T10:00:20.000Z')

let wrapper: VueWrapper | null = null

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
  vi.setSystemTime(NOW)
})
afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  vi.useRealTimers()
  setLocale('ru')
})

function strip(props: Partial<{ since: number; graceSeconds: number; offsetMs: number; playing: boolean }> = {}) {
  wrapper = mount(AbsentStrip, { props: { since: NOW - 12_000, graceSeconds: 30, offsetMs: 0, playing: true, ...props } })
  return wrapper
}

const hint = (w: VueWrapper) => w.get('[data-testid=absent-hint]').text()
const live = (w: VueWrapper) => w.get('[data-testid=absent-live]').text()

describe('AbsentStrip — the number', () => {
  it('is what is left of the wait of the server: the last presence + the grace − now', () => {
    const w = strip() // gone 12 s ago, the server waits 30 s

    expect(w.get('[data-testid=absent-text]').text()).toBe('Соперник потерял связь.')
    expect(hint(w)).toBe('Если не вернётся за 18 с, победа ваша.')
    expect(w.get('[data-testid=absent-strip]').attributes('data-seconds')).toBe('18')
  })

  it('is the same on a screen that was opened a moment ago and on one that has been here all along', async () => {
    const early = strip({ since: NOW - 12_000 })
    const before = hint(early)
    early.unmount()
    await vi.advanceTimersByTimeAsync(5000)
    const watching = strip({ since: NOW - 12_000 }) // the very moment, 5 s later: a reload

    expect(before).toBe('Если не вернётся за 18 с, победа ваша.')
    expect(hint(watching)).toBe('Если не вернётся за 13 с, победа ваша.')
  })

  it('counts down with the clock', async () => {
    const w = strip()

    await vi.advanceTimersByTimeAsync(5000)

    expect(hint(w)).toBe('Если не вернётся за 13 с, победа ваша.')
  })

  it('shows a second until it is over: a part of a second is a second', () => {
    const w = strip({ since: NOW - 12_200 }) // 17.8 s left

    expect(hint(w)).toBe('Если не вернётся за 18 с, победа ваша.')
  })

  it('never goes below 0: the wait is over, the server decides', async () => {
    const w = strip({ since: NOW - 29_000 })

    await vi.advanceTimersByTimeAsync(10_000)
    expect(w.get('[data-testid=absent-strip]').attributes('data-seconds')).toBe('0')
    await vi.advanceTimersByTimeAsync(60_000)
    expect(w.get('[data-testid=absent-strip]').attributes('data-seconds')).toBe('0')
    expect(strip({ since: NOW - 100_000 }).get('[data-testid=absent-strip]').attributes('data-seconds')).toBe('0')
  })

  // Re-review М-7: "if they do not come back in 0 s" says nothing; the server is deciding that very moment.
  it('does not say “in 0 s”: the last second is “in 1 s”, and at 0 the second line is not drawn, the first stays', async () => {
    const w = strip({ since: NOW - 29_000 }) // 1 s left
    expect(hint(w)).toBe('Если не вернётся за 1 с, победа ваша.')

    await vi.advanceTimersByTimeAsync(1000)

    expect(w.find('[data-testid=absent-hint]').exists()).toBe(false)
    expect(w.get('[data-testid=absent-text]').text()).toBe('Соперник потерял связь.')
    expect(w.text()).not.toContain('0 с')
    expect(w.get('[data-testid=absent-strip]').attributes('data-seconds')).toBe('0')
  })

  it('a strip that is drawn past the wait (a reload) has the first line only, in the room and in the match, in Uzbek too', () => {
    for (const playing of [true, false]) {
      const w = strip({ since: NOW - 100_000, playing })
      expect(w.find('[data-testid=absent-hint]').exists()).toBe(false)
      expect(w.get('[data-testid=absent-text]').text()).toBe('Соперник потерял связь.')
      w.unmount()
    }
    setLocale('uz')
    const uz = strip({ since: NOW - 100_000 })
    expect(uz.find('[data-testid=absent-hint]').exists()).toBe(false)
    expect(uz.get('[data-testid=absent-text]').text()).toBe('Raqibning aloqasi uzildi.')
  })

  it('counts by the clock of the SERVER, not by the clock of the device', () => {
    // the device is 5 s behind the server: the opponent has been gone 5 s longer than the device alone would say
    const w = strip({ offsetMs: 5000 })

    expect(hint(w)).toBe('Если не вернётся за 13 с, победа ваша.')
  })

  it('takes the wait from the server too (it is not always 30 s)', () => {
    expect(hint(strip({ graceSeconds: 45 }))).toBe('Если не вернётся за 33 с, победа ваша.')
  })
})

describe('AbsentStrip — what happens next', () => {
  it('in a match the learner wins', () => {
    expect(hint(strip({ playing: true }))).toContain('победа ваша')
  })

  it('before the match (the room, the countdown) the match is canceled', () => {
    const w = strip({ playing: false })

    expect(hint(w)).toBe('Если не вернётся за 18 с, матч отменится.')
    expect(hint(w)).not.toContain('победа')
  })

  it('speaks Uzbek', () => {
    setLocale('uz')

    expect(hint(strip({ playing: true }))).toBe("Agar u 18 soniya ichida qaytmasa, g'alaba sizga o'tadi.")
    wrapper?.unmount()
    expect(hint(strip({ playing: false }))).toBe("Agar u 18 soniya ichida qaytmasa, o'yin bekor qilinadi.")
    expect(wrapper?.get('[data-testid=absent-text]').text()).toBe('Raqibning aloqasi uzildi.')
  })
})

describe('AbsentStrip — a11y: a warning that is read, not a number that chatters', () => {
  it('is a polite status with a symbol and words (not a colour alone), and the ticking lines are hidden from a screen reader', () => {
    const w = strip()
    const root = w.get('[data-testid=absent-strip]')

    expect(root.attributes('role')).toBe('status')
    expect(root.find('svg').exists()).toBe(true)
    expect(w.get('[data-testid=absent-text]').attributes('aria-hidden')).toBe('true')
    expect(w.get('[data-testid=absent-hint]').attributes('aria-hidden')).toBe('true')
  })

  it('says the whole sentence when it appears, not before the next moment worth a word', async () => {
    const w = strip()
    expect(live(w)).toBe('Соперник потерял связь. Если не вернётся за 18 с, победа ваша.')

    await vi.advanceTimersByTimeAsync(3000) // 15 s: nothing new to say
    expect(live(w)).toBe('Соперник потерял связь. Если не вернётся за 18 с, победа ваша.')
    expect(hint(w)).toBe('Если не вернётся за 15 с, победа ваша.')
  })

  it('says no “0 s” to a screen reader either: at 0 the live region keeps the sentence of the last second, and a strip drawn at 0 says the first line', async () => {
    const w = strip({ since: NOW - 29_000 }) // 1 s left
    expect(live(w)).toBe('Соперник потерял связь. Если не вернётся за 1 с, победа ваша.')

    await vi.advanceTimersByTimeAsync(3000)
    expect(live(w)).toBe('Соперник потерял связь. Если не вернётся за 1 с, победа ваша.')
    expect(live(w)).not.toContain('0 с')

    expect(live(strip({ since: NOW - 100_000 }))).toBe('Соперник потерял связь.')
  })

  it('says it again every ten seconds, and every second of the last five', async () => {
    const w = strip({ since: NOW - 12_000 }) // 18 s left

    await vi.advanceTimersByTimeAsync(8000) // 10 s
    expect(live(w)).toContain('за 10 с')
    await vi.advanceTimersByTimeAsync(4000) // 6 s
    expect(live(w)).toContain('за 10 с')
    await vi.advanceTimersByTimeAsync(1000) // 5 s
    expect(live(w)).toContain('за 5 с')
    await vi.advanceTimersByTimeAsync(1000)
    expect(live(w)).toContain('за 4 с')
  })
})
