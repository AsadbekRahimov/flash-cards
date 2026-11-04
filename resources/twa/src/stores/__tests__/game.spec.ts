import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { setLocale } from '@/i18n'
import { useBillingStore } from '@/stores/billing'
import { activeRunFrom, useGameStore } from '@/stores/game'
import { gameLimits, gameMap } from '@/api/__tests__/gameMapFixtures'

vi.mock('@/api', () => ({ api: { game: { map: vi.fn(), startRun: vi.fn(), cityShared: vi.fn() }, me: { get: vi.fn() } } }))

const apiError = (kind: ConstructorParameters<typeof ApiClientError>[0], status: number, code: string, details: Record<string, unknown> | null = null) =>
  new ApiClientError(kind, status, code, 'English message', undefined, details)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.resetAllMocks()
  setLocale('ru')
})

describe('game store: the map', () => {
  it('reads the map of the course and keeps it', async () => {
    vi.mocked(api.game.map).mockResolvedValue(gameMap())
    const game = useGameStore()

    const reading = game.loadMap('english-a1')
    expect(game.status).toBe('loading')
    await reading

    expect(api.game.map).toHaveBeenCalledWith('english-a1')
    expect(game.status).toBe('ready')
    expect(game.map?.cities).toHaveLength(2)
  })

  it('hands the limits of the map to the billing store, and never asks for /me', async () => {
    vi.mocked(api.game.map).mockResolvedValue(gameMap({ limits: gameLimits() }))
    const game = useGameStore()
    const billing = useBillingStore()

    await game.loadMap('english-a1')

    expect(billing.gameLimits?.tickets.balance).toBe(2)
    expect(api.me.get).not.toHaveBeenCalled()
  })

  it('403 game_unavailable is "unavailable", not an error', async () => {
    vi.mocked(api.game.map).mockRejectedValue(apiError('forbidden', 403, 'game_unavailable'))
    const game = useGameStore()

    await game.loadMap('english-a1')

    expect(game.status).toBe('unavailable')
    expect(game.map).toBeNull()
  })

  it('tells a failure in words of the code (404 course_not_found)', async () => {
    vi.mocked(api.game.map).mockRejectedValue(apiError('not_found', 404, 'course_not_found'))
    const game = useGameStore()

    await game.loadMap('nope')

    expect(game.status).toBe('error')
    expect(game.error).toBe('Курс не найден или недоступен.')
  })

  it('drops an older answer that comes late', async () => {
    let first!: (value: ReturnType<typeof gameMap>) => void
    vi.mocked(api.game.map)
      .mockReturnValueOnce(new Promise((resolve) => (first = resolve)))
      .mockResolvedValueOnce(gameMap({ course: { slug: 'ielts', title: 'IELTS' } }))
    const game = useGameStore()

    const slow = game.loadMap('english-a1')
    await game.loadMap('ielts')
    first(gameMap())
    await slow

    expect(game.map?.course.slug).toBe('ielts')
  })

  it('forgets the map when the screen is left, so that a late answer is not kept', async () => {
    let answer!: (value: ReturnType<typeof gameMap>) => void
    vi.mocked(api.game.map).mockReturnValue(new Promise((resolve) => (answer = resolve)))
    const game = useGameStore()

    const reading = game.loadMap('english-a1')
    game.reset()
    answer(gameMap())
    await reading

    expect(game.map).toBeNull()
    expect(game.status).toBe('idle')
  })
})

describe('game store: starting a stop', () => {
  async function loaded() {
    vi.mocked(api.game.map).mockResolvedValue(gameMap())
    const game = useGameStore()
    await game.loadMap('english-a1')
    return game
  }

  it('starts a run of the lesson in the course of the map and returns its id', async () => {
    const game = await loaded()
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 9 })

    const result = await game.startStop(11)

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'stop', course: 'english-a1', lesson_id: 11 })
    expect(result).toEqual({ ok: true, id: 9 })
    expect(game.starting).toBe(false)
  })

  it('sends replace_active only when it is asked for', async () => {
    const game = await loaded()
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 10 })

    await game.startStop(11, true)

    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'stop', course: 'english-a1', lesson_id: 11, replace_active: true })
  })

  it('returns a typed refusal with the details of the 409', async () => {
    const game = await loaded()
    const details = { run_id: 9, kind: 'stop', progress: { answered: 3, total: 9 } }
    vi.mocked(api.game.startRun).mockRejectedValue(apiError('conflict', 409, 'game_run_active', details))

    const result = await game.startStop(11)

    expect(result).toMatchObject({ ok: false, error: { code: 'game_run_active', kind: 'conflict', details } })
    expect(game.starting).toBe(false)
  })

  it.each([
    ['forbidden', 403, 'game_limit_reached', 'Бесплатные забеги на сегодня закончились.'],
    ['validation', 422, 'game_not_enough_material', 'Для этой стоянки пока не хватает слов.'],
    ['conflict', 409, 'stop_locked', 'Эта стоянка закрыта: начните урок в карточках.'],
  ] as const)('explains %s %i %s', async (kind, status, code, text) => {
    const game = await loaded()
    vi.mocked(api.game.startRun).mockRejectedValue(apiError(kind, status, code))

    const result = await game.startStop(11)

    expect(result).toMatchObject({ ok: false, error: { code, message: text } })
  })

  it('does not start a second run while one is on its way', async () => {
    const game = await loaded()
    let created!: (value: { id: number }) => void
    vi.mocked(api.game.startRun).mockReturnValue(new Promise((resolve) => (created = resolve)))

    const first = game.startStop(11)
    const second = await game.startStop(11)
    created({ id: 9 })

    expect(second).toMatchObject({ ok: false, error: { code: 'busy' } })
    expect(await first).toEqual({ ok: true, id: 9 })
    expect(api.game.startRun).toHaveBeenCalledTimes(1)
  })

  it('answers a network failure with its text', async () => {
    const game = await loaded()
    vi.mocked(api.game.startRun).mockRejectedValue(new ApiClientError('network', 0, 'network', 'fetch failed'))

    const result = await game.startStop(11)

    expect(result).toMatchObject({ ok: false, error: { kind: 'network' } })
  })
})

describe('game store: the caravan of the day', () => {
  it('starts it with the kind alone (no course, no lesson: the server knows the active course), and needs no map', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 12 })
    const game = useGameStore()

    expect(await game.startDaily()).toEqual({ ok: true, id: 12 })
    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'daily' })
    expect(game.starting).toBe(false)

    await game.startDaily(true)
    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'daily', replace_active: true })
  })

  it.each([
    ['conflict', 409, 'game_daily_done', { resets_at: '2026-11-06T19:00:00.000Z' }, 'Караван дня уже сыгран. Новый — завтра.'],
    ['validation', 422, 'game_not_enough_material', { available: 2, required: 8, reason: 'no_words' }, 'Для этой стоянки пока не хватает слов.'],
    ['conflict', 409, 'game_run_active', { run_id: 9, kind: 'stop', progress: { answered: 1, total: 9 } }, 'У вас уже есть идущий забег.'],
  ] as const)('returns %s %i %s typed, with its details', async (kind, status, code, details, message) => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError(kind, status, code, details))
    const game = useGameStore()

    expect(await game.startDaily()).toMatchObject({ ok: false, error: { code, details, message } })
  })

  it('shares the one-start-at-a-time guard with the stops', async () => {
    let created!: (value: { id: number }) => void
    vi.mocked(api.game.startRun).mockReturnValue(new Promise((resolve) => (created = resolve)))
    const game = useGameStore()

    const first = game.startDaily()
    expect(await game.startDaily()).toMatchObject({ ok: false, error: { code: 'busy' } })
    created({ id: 3 })
    expect(await first).toEqual({ ok: true, id: 3 })
    expect(api.game.startRun).toHaveBeenCalledTimes(1)
  })
})

describe('game store: what a start tells', () => {
  it('reads the run that is on from the 409 in one place: the details, else the fallback; the kind the server did not say is the caller\'s', () => {
    const failure = (details: Record<string, unknown> | null) => ({ code: 'game_run_active', kind: 'conflict' as const, details, message: '' })
    const fallback = { id: 1, kind: 'stop', progress: { answered: 0, total: 9 } }

    expect(activeRunFrom(failure({ run_id: 9, kind: 'boss', progress: { answered: 2, total: 12 } }))).toEqual({ id: 9, kind: 'boss', progress: { answered: 2, total: 12 } })
    expect(activeRunFrom(failure({ run_id: 9 }), null, 'daily')).toEqual({ id: 9, kind: 'daily', progress: { answered: 0, total: 0 } })
    expect(activeRunFrom(failure(null), fallback)).toBe(fallback)
    expect(activeRunFrom(failure({ run_id: 'x' }))).toBeNull()
  })

  it('takes the limits of the 201 into the billing store: what is left after this start', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 9, limits: gameLimits({ tickets: { balance: 1, max: 5 } }) })
    const game = useGameStore()

    await game.startStop(11).catch(() => undefined)
    await game.startDaily()

    expect(useBillingStore().gameLimits?.tickets.balance).toBe(1)
  })

  it('leaves the limits as they were when the 201 says nothing of them', async () => {
    useBillingStore().setGameLimits(gameLimits())
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 9 })

    await useGameStore().startDaily()

    expect(useBillingStore().gameLimits?.tickets.balance).toBe(2)
  })
})

describe('game store: the boss of a city (docs/05 §5b.8)', () => {
  it('starts the fight with the course and the stage of the map, replace_active only on request', async () => {
    vi.mocked(api.game.startRun).mockResolvedValue({ id: 21 })
    const game = useGameStore()

    expect(await game.startBoss('english-a1', 3)).toEqual({ ok: true, id: 21 })
    expect(api.game.startRun).toHaveBeenCalledWith({ kind: 'boss', course: 'english-a1', stage_id: 3 })

    await game.startBoss('english-a1', 3, true)
    expect(api.game.startRun).toHaveBeenLastCalledWith({ kind: 'boss', course: 'english-a1', stage_id: 3, replace_active: true })
  })

  it.each([
    ['forbidden', 403, 'game_pro_required', 'Это доступно в Pro.'],
    ['forbidden', 403, 'game_limit_reached', 'Бесплатные забеги на сегодня закончились.'],
    ['conflict', 409, 'boss_not_ready', 'Босс пока не готов: пройдите больше стоянок.'],
    ['not_found', 404, 'stage_not_found', null],
  ] as const)('returns %s %i %s typed, with the text of the code', async (kind, status, code, text) => {
    vi.mocked(api.game.startRun).mockRejectedValue(apiError(kind, status, code))
    const game = useGameStore()

    const result = await game.startBoss('english-a1', 3)

    expect(result).toMatchObject({ ok: false, error: { code, kind } })
    if (text !== null && !result.ok) expect(result.error.message).toBe(text)
  })

  it('marks the card of the city as shared, and never throws: a refusal is silent', async () => {
    vi.mocked(api.game.cityShared).mockResolvedValueOnce({ stage_id: 3, shared_at: '2026-11-05T10:00:00.000Z' })
    const game = useGameStore()

    await expect(game.markCityShared(3)).resolves.toBeUndefined()
    expect(api.game.cityShared).toHaveBeenCalledWith(3)

    vi.mocked(api.game.cityShared).mockRejectedValueOnce(apiError('not_found', 404, 'stage_not_found'))
    await expect(game.markCityShared(4)).resolves.toBeUndefined()
  })
})

describe('billing store: the game block of /me', () => {
  const meWith = (game: unknown) =>
    ({
      entitlement: { plan: 'free', source: 'free', until: null, new_words_per_day: 20 },
      paywall: { mode: 'coming_soon', contact: null },
      ...(game ? { game } : {}),
    }) as Parameters<ReturnType<typeof useBillingStore>['apply']>[0]

  it('reads "no game" until /me says otherwise', () => {
    const billing = useBillingStore()

    expect(billing.gameAccess.available).toBe(false)
    billing.apply(meWith(undefined))
    expect(billing.gameAccess.available).toBe(false)
  })

  it('keeps access and limits apart', () => {
    const billing = useBillingStore()

    billing.apply(meWith({ available: true, limits: gameLimits(), pro_promise: 'full', coins: 5, tickets: 2 }))

    expect(billing.gameAccess).toEqual({ available: true, pro_promise: 'full', coins: 5, tickets: 2 })
    expect(billing.gameLimits?.runs?.limit).toBe(1)
  })

  it('keeps a null limits block (before G2.8) as null, and a map without the word on limits untouched', () => {
    const billing = useBillingStore()
    billing.apply(meWith({ available: true, limits: null, pro_promise: 'none', coins: 0, tickets: 0 }))
    expect(billing.gameLimits).toBeNull()

    billing.setGameLimits(gameLimits())
    billing.setGameLimits(undefined)
    expect(billing.gameLimits?.tickets.balance).toBe(2)
  })
})
