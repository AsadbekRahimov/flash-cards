import type { GameLimits, GameMapBoss, GameMapCity, GameMapDaily, GameMapResponse, GameMapStop } from '../types'

/** `daily` of the map: the caravan of the day is waiting with 14 due words. */
export const mapDaily = (overrides: Partial<GameMapDaily> = {}): GameMapDaily => ({
  available: true,
  done_today: false,
  due_count: 14,
  resets_at: '2026-11-06T19:00:00.000Z',
  ...overrides,
})

/** Answers of `GET /game/map` for the specs of the store and the map screen (docs/04 A.18). */

export const mapStop = (overrides: Partial<GameMapStop> = {}): GameMapStop => ({
  lesson_id: 11,
  number: 1,
  title: 'Фрукты',
  unlocked: true,
  stars: 2,
  best_score: 120,
  runs: 3,
  ...overrides,
})

export const mapBoss = (overrides: Partial<GameMapBoss> = {}): GameMapBoss => ({
  state: 'not_ready',
  defeated_at: null,
  introduced: 5,
  required: 8,
  cleared_stops: 1,
  required_stops: 3,
  ...overrides,
})

export const mapCity = (overrides: Partial<GameMapCity> = {}): GameMapCity => ({
  stage_id: 3,
  number: 1,
  city: 'Самарканд',
  title: 'Еда',
  unlocked: true,
  stops: [mapStop(), mapStop({ lesson_id: 12, number: 2, title: 'Овощи', stars: 0, best_score: 0, runs: 0, unlocked: false })],
  boss: mapBoss(),
  ...overrides,
})

export const gameLimits = (overrides: Partial<GameLimits> = {}): GameLimits => ({
  runs: { limit: 1, used: 0, remaining: 1, resets_at: '2999-01-01T19:00:00.000Z' },
  tickets: { balance: 2, max: 5 },
  boosters: { limit: 1, used: 0, remaining: 1, resets_at: '2999-01-05T19:00:00.000Z' },
  boss_stages: 1,
  ...overrides,
})

export const gameMap = (overrides: Partial<GameMapResponse> = {}): GameMapResponse => ({
  course: { slug: 'english-a1', title: 'Английский A1' },
  cities: [
    mapCity(),
    mapCity({
      stage_id: 4,
      number: 2,
      city: 'Бухара',
      title: null,
      unlocked: false,
      stops: [mapStop({ lesson_id: 21, number: 1, title: null, unlocked: false, stars: 0, best_score: 0, runs: 0 })],
      boss: mapBoss({ state: 'locked_by_plan' }),
    }),
  ],
  me: { stops_cleared: 4, cities_cleared: 1, coins: 35, tickets: 2 },
  friends: [{ friendship_id: 11, name: 'Dilnoza', city_number: 1, stops_cleared: 7, camel_asset: null }],
  active_run: null,
  limits: null,
  ...overrides,
})
