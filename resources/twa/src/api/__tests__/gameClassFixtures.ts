import type { GameClass, GameClassMember } from '../gameClassTypes'

/** Answers of `GET /game/class/{id}` for the specs of the caravan of a class (docs/04 A.18). */

export const classMember = (overrides: Partial<GameClassMember> = {}): GameClassMember => ({
  name: 'Dilnoza',
  stars: 9,
  runs: 4,
  best_score: 140,
  boss_won: false,
  is_me: false,
  ...overrides,
})

export const gameClass = (overrides: Partial<GameClass> = {}): GameClass => ({
  id: 5,
  group_title: '7-B',
  course: { slug: 'english-a1', title: 'Английский A1' },
  status: 'active',
  starts_at: '2026-11-02T04:00:00.000Z',
  ends_at: '2026-11-06T13:00:00.000Z',
  stars_total: 12,
  target_stars: 40,
  runs_total: 9,
  boss: { unlocked: false, defeated: false, my_attempt: null },
  members: [classMember(), classMember({ name: 'Aziz', stars: 3, runs: 2, best_score: 80, is_me: true })],
  my: { stars: 3, runs: 2, best_score: 80, boss_won: false },
  summary: null,
  ...overrides,
})
