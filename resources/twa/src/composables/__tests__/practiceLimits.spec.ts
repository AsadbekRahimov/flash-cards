import { afterEach, describe, expect, it } from 'vitest'
import { limitHitFrom, liveLimit, nextReset, reachedLimit, resetWhen, shownLimit } from '../practiceLimits'
import { setLocale } from '@/i18n'
import { counter, freeLimits } from '@/api/__tests__/practiceFixtures'

afterEach(() => setLocale('ru'))

/** 2026-11-02, 15:00 in Tashkent (UTC+5): the learner's "today" ends in nine hours, at 00:00 of the 3rd. */
const NOW = Date.parse('2026-11-02T10:00:00Z')
const MIDNIGHT = '2026-11-02T19:00:00+00:00'
const TASHKENT = 'Asia/Tashkent'

describe('limitHitFrom (details of 403 practice_limit_reached)', () => {
  const details = { kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT }

  it('reads the kind, the numbers and the moment of the reset', () => {
    expect(limitHitFrom(details)).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT })
    expect(limitHitFrom({ ...details, kind: 'battles', limit: 1, used: 1 })).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: MIDNIGHT })
  })

  it('takes only what it knows: a field the server adds later changes nothing', () => {
    expect(limitHitFrom({ ...details, remaining: 0, plan: 'free' })).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT })
  })

  it.each([
    ['no details at all', null],
    ['an unknown kind', { ...details, kind: 'exams' }],
    ['no kind', { limit: 2, used: 2, resets_at: MIDNIGHT }],
    ['a limit that is not a number', { ...details, limit: '2' }],
    ['a negative limit', { ...details, limit: -1 }],
    ['a used that is not a finite number', { ...details, used: Number.POSITIVE_INFINITY }],
    ['no used', { kind: 'sessions', limit: 2, resets_at: MIDNIGHT }],
    ['a reset that is not a string', { ...details, resets_at: 1762110000 }],
    ['a reset that is not a date', { ...details, resets_at: 'tomorrow' }],
    ['no reset', { kind: 'sessions', limit: 2, used: 2 }],
  ])('is null for %s: the message of the code is told instead', (_, input) => {
    expect(limitHitFrom(input)).toBeNull()
  })
})

describe('liveLimit', () => {
  it('is the counter itself while its day lasts', () => {
    const limit = counter(1, 2, MIDNIGHT)

    expect(liveLimit(limit, NOW)).toBe(limit)
  })

  it('is nothing for a counter that was not told, or whose day is over: a new day has begun, the number says nothing', () => {
    expect(liveLimit(null, NOW)).toBeNull()
    expect(liveLimit(undefined, NOW)).toBeNull()
    expect(liveLimit(counter(2, 2, MIDNIGHT), Date.parse(MIDNIGHT) + 1)).toBeNull()
    expect(liveLimit(counter(2, 2, MIDNIGHT), Date.parse(MIDNIGHT))).toBeNull()
    expect(liveLimit(counter(2, 2, MIDNIGHT), Date.parse(MIDNIGHT) - 1)).not.toBeNull()
  })

  it('cannot judge a moment it cannot read, and keeps the counter', () => {
    const limit = counter(1, 2, 'soon')

    expect(liveLimit(limit, NOW)).toBe(limit)
  })

  it('reads the real clock when none is given', () => {
    expect(liveLimit(counter(0, 2, new Date(Date.now() + 60_000).toISOString()))).not.toBeNull()
    expect(liveLimit(counter(0, 2, new Date(Date.now() - 60_000).toISOString()))).toBeNull()
  })
})

describe('shownLimit (the counter that is drawn)', () => {
  it('is the live counter while something can be counted', () => {
    const limit = counter(1, 2, MIDNIGHT)

    expect(shownLimit(limit, NOW)).toBe(limit)
    expect(shownLimit(counter(0, 2, MIDNIGHT), NOW)).not.toBeNull()
  })

  it('is nothing for what liveLimit gives nothing: no counter, or a day that is over', () => {
    expect(shownLimit(null, NOW)).toBeNull()
    expect(shownLimit(undefined, NOW)).toBeNull()
    expect(shownLimit(counter(2, 2, MIDNIGHT), Date.parse(MIDNIGHT) + 1), 'the day of the counter is over').toBeNull()
  })

  it('is nothing for a limit of 0, "only Pro": "0 of 0 left" says nothing, so no line is drawn', () => {
    expect(liveLimit(counter(0, 0, MIDNIGHT), NOW), 'the counter is alive, it is only not worth showing').not.toBeNull()
    expect(shownLimit(counter(0, 0, MIDNIGHT), NOW)).toBeNull()
  })

  it('reads the real clock when none is given', () => {
    expect(shownLimit(counter(0, 2, new Date(Date.now() + 60_000).toISOString()))).not.toBeNull()
    expect(shownLimit(counter(0, 2, new Date(Date.now() - 60_000).toISOString()))).toBeNull()
  })
})

describe('nextReset (when a screen has to look at the counters again)', () => {
  const at = (iso: string) => Date.parse(iso)

  it('is the end of the day of the counters', () => {
    expect(nextReset(freeLimits(1, 0, MIDNIGHT), NOW)).toBe(at(MIDNIGHT))
  })

  it('is the nearest one when the counters end at different moments', () => {
    const limits = { sessions: counter(1, 2, '2026-11-02T19:00:00Z'), battles: counter(0, 1, '2026-11-02T14:00:00Z') }

    expect(nextReset(limits, NOW)).toBe(at('2026-11-02T14:00:00Z'))
    expect(nextReset({ sessions: limits.battles, battles: limits.sessions }, NOW)).toBe(at('2026-11-02T14:00:00Z'))
  })

  it('looks at the counter that is there: a kind without a limit gives no moment', () => {
    expect(nextReset({ sessions: null, battles: counter(0, 1, MIDNIGHT) }, NOW)).toBe(at(MIDNIGHT))
    expect(nextReset({ sessions: counter(0, 2, MIDNIGHT), battles: null }, NOW)).toBe(at(MIDNIGHT))
  })

  it('passes over a counter whose day is over, the other one still counts', () => {
    const limits = { sessions: counter(2, 2, '2026-11-02T09:00:00Z'), battles: counter(0, 1, MIDNIGHT) }

    expect(nextReset(limits, NOW)).toBe(at(MIDNIGHT))
  })

  it('is null when nothing is left to wait for: no counters, Pro, counters whose days are over, a moment that cannot be read', () => {
    expect(nextReset(null, NOW)).toBeNull()
    expect(nextReset(undefined, NOW)).toBeNull()
    expect(nextReset({ sessions: null, battles: null }, NOW)).toBeNull()
    expect(nextReset(freeLimits(2, 1, '2026-11-02T09:00:00Z'), NOW)).toBeNull()
    expect(nextReset(freeLimits(2, 1, new Date(NOW).toISOString()), NOW), 'the very moment is already the new day').toBeNull()
    expect(nextReset({ sessions: counter(1, 2, 'soon'), battles: null }, NOW), 'liveLimit keeps such a counter for good').toBeNull()
  })

  it('reads the real clock when none is given', () => {
    const soon = new Date(Date.now() + 60_000).toISOString()

    expect(nextReset(freeLimits(0, 0, soon))).toBe(Date.parse(soon))
    expect(nextReset(freeLimits(0, 0, new Date(Date.now() - 60_000).toISOString()))).toBeNull()
  })
})

describe('reachedLimit', () => {
  it('is the wall of a kind whose counter is spent, with the numbers the paywall quotes', () => {
    const limits = freeLimits(2, 0, MIDNIGHT)

    expect(reachedLimit(limits, 'sessions', NOW)).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT })
  })

  it('is nothing while something is left', () => {
    expect(reachedLimit(freeLimits(1, 0, MIDNIGHT), 'sessions', NOW)).toBeNull()
    expect(reachedLimit(freeLimits(0, 0, MIDNIGHT), 'battles', NOW)).toBeNull()
  })

  it('looks at the kind it is asked about: spent sessions do not stop a battle, and the other way round', () => {
    const limits = freeLimits(2, 0, MIDNIGHT)
    expect(reachedLimit(limits, 'battles', NOW)).toBeNull()

    const spentBattles = freeLimits(0, 1, MIDNIGHT)
    expect(reachedLimit(spentBattles, 'sessions', NOW)).toBeNull()
    expect(reachedLimit(spentBattles, 'battles', NOW)).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: MIDNIGHT })
  })

  it('is nothing for Pro, for a learner whose limits are not known, and for a counter whose day is over: the server decides', () => {
    expect(reachedLimit(null, 'sessions', NOW)).toBeNull()
    expect(reachedLimit(undefined, 'battles', NOW)).toBeNull()
    expect(reachedLimit(freeLimits(2, 1, MIDNIGHT), 'sessions', Date.parse(MIDNIGHT) + 60_000)).toBeNull()
  })

  it('treats a limit of nothing a day as spent from the start', () => {
    const limits = { ...freeLimits(0, 0, MIDNIGHT), battles: counter(0, 0, MIDNIGHT) }

    expect(reachedLimit(limits, 'battles', NOW)).toEqual({ kind: 'battles', limit: 0, used: 0, resets_at: MIDNIGHT })
  })

  // An empty env of the server means that kind has no limit: `limits` carries `null` for it, the other kind keeps its counter.
  describe('a kind without a limit (null inside the limits)', () => {
    it('is never stopped, however spent the other kind is', () => {
      const onlyBattles = { sessions: null, battles: counter(1, 1, MIDNIGHT) }
      const onlySessions = { sessions: counter(2, 2, MIDNIGHT), battles: null }

      expect(reachedLimit(onlyBattles, 'sessions', NOW)).toBeNull()
      expect(reachedLimit(onlySessions, 'battles', NOW)).toBeNull()
    })

    it('leaves the other kind as it is: its counter says what it says', () => {
      const onlyBattles = { sessions: null, battles: counter(1, 1, MIDNIGHT) }
      const onlySessions = { sessions: counter(2, 2, MIDNIGHT), battles: null }

      expect(reachedLimit(onlyBattles, 'battles', NOW)).toEqual({ kind: 'battles', limit: 1, used: 1, resets_at: MIDNIGHT })
      expect(reachedLimit(onlySessions, 'sessions', NOW)).toEqual({ kind: 'sessions', limit: 2, used: 2, resets_at: MIDNIGHT })
      expect(reachedLimit({ sessions: null, battles: counter(0, 1, MIDNIGHT) }, 'battles', NOW)).toBeNull()
    })

    it('both kinds without a limit stop nothing and say nothing', () => {
      const none = { sessions: null, battles: null }

      expect(reachedLimit(none, 'sessions', NOW)).toBeNull()
      expect(reachedLimit(none, 'battles', NOW)).toBeNull()
      expect(liveLimit(none.sessions, NOW)).toBeNull()
    })
  })
})

describe('resetWhen', () => {
  it('says "tomorrow at 00:00" for the end of the learner\'s today, in their zone', () => {
    expect(resetWhen(MIDNIGHT, 'ru', TASHKENT, NOW)).toBe('завтра в 00:00')
  })

  it('says it in Uzbek', () => {
    setLocale('uz')

    expect(resetWhen(MIDNIGHT, 'uz', TASHKENT, NOW)).toBe('ertaga soat 00:00 da')
  })

  it('shows the time in the zone of the learner, not of the device', () => {
    // The same moment is 19:00 in UTC. That is an hour of the day there, not its turn: no "tomorrow" (it would be "today at 19:00").
    expect(resetWhen(MIDNIGHT, 'ru', 'UTC', NOW)).toBe('в 19:00')
    expect(resetWhen(MIDNIGHT, 'ru', TASHKENT, NOW)).toBe('завтра в 00:00')
  })

  it('is "tomorrow" only at the turn of a day: a reset in the middle of one is told by its time', () => {
    // 21:00 in Tashkent on the same day as `NOW`: an hour of today, not the start of a new day
    expect(resetWhen('2026-11-02T16:00:00Z', 'ru', TASHKENT, NOW)).toBe('в 21:00')
  })

  it('is "tomorrow" however late in the learner\'s day it is asked, up to the very last minute', () => {
    expect(resetWhen(MIDNIGHT, 'ru', TASHKENT, Date.parse('2026-11-02T18:59:00Z'))).toBe('завтра в 00:00')
    // 00:30 of the 3rd in Tashkent: the next reset is the midnight of the 4th, still the start of the NEXT day
    expect(resetWhen('2026-11-03T19:00:00Z', 'ru', TASHKENT, Date.parse('2026-11-02T19:30:00Z'))).toBe('завтра в 00:00')
  })

  it('goes by calendar days, so a day of 25 hours (the clocks go back) is still "tomorrow"', () => {
    // Berlin, 25 October 2026: 03:00 summer time becomes 02:00. The 25th lasts 25 hours; its end is 00:00 on the 26th (UTC 23:00).
    expect(resetWhen('2026-10-25T23:00:00Z', 'ru', 'Europe/Berlin', Date.parse('2026-10-25T10:00:00Z'))).toBe('завтра в 00:00')
    // the day before it ends at 00:00 summer time on the 25th (UTC 22:00)
    expect(resetWhen('2026-10-24T22:00:00Z', 'ru', 'Europe/Berlin', Date.parse('2026-10-24T20:00:00Z'))).toBe('завтра в 00:00')
  })

  it('does not claim a day it cannot vouch for: a device clock that is far off gives the time alone', () => {
    expect(resetWhen(MIDNIGHT, 'ru', TASHKENT, Date.parse('2026-10-30T10:00:00Z'))).toBe('в 00:00')
    setLocale('uz')
    expect(resetWhen(MIDNIGHT, 'uz', TASHKENT, Date.parse('2026-11-05T10:00:00Z'))).toBe('soat 00:00 da')
  })

  it('uses the zone of the device when the learner\'s is not known or not a zone', () => {
    const device = resetWhen(MIDNIGHT, 'ru', undefined, NOW)

    expect(resetWhen(MIDNIGHT, 'ru', 'Mars/Olympus', NOW)).toBe(device)
    expect(resetWhen(MIDNIGHT, 'ru', '', NOW)).toBe(device)
    expect(device).toMatch(/^(завтра )?в \d{2}:\d{2}$/)
  })

  it('is empty for a value that is not a moment, and never throws', () => {
    expect(resetWhen('', 'ru', TASHKENT, NOW)).toBe('')
    expect(resetWhen('tomorrow', 'uz', TASHKENT, NOW)).toBe('')
  })

  it('reads the real clock when none is given', () => {
    // The end of today in the zone of the device, computed the way the server does it: the first moment of the next calendar day.
    const next = new Date()
    next.setHours(24, 0, 0, 0)

    expect(resetWhen(next.toISOString(), 'ru')).toMatch(/^завтра в \d{2}:\d{2}$/)
  })
})
