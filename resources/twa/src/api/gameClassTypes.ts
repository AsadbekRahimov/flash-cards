/**
 * The caravan of a class (docs/18 §12.8, §12.9, §12.11; docs/04 A.18 "Караван класса"): `GET /game/class`, `GET /game/class/{id}`, the card
 * `class_caravan` of the map and of `/today.game`, and the start of the boss of the class.
 */

/** `class_caravan` of `GET /game/map` and `/today.game`: the caravan of the class that is on its way (`null`: none). */
export interface GameClassCard {
  id: number
  stars_total: number
  target_stars: number
  ends_at: string
}

/**
 * The boss of a class: `unlocked` once the caravan took its target, `defeated` once enough of the class won. `my_attempt`: my one fight
 * (`won` / `lost`), `null` while it has not happened or is on.
 */
export interface GameClassBoss {
  unlocked: boolean
  defeated: boolean
  my_attempt: 'won' | 'lost' | null
}

/** `canceled` is not sent today (a canceled caravan is 404); the screen has a state for it all the same. */
export type GameClassStatus = 'active' | 'finished' | 'canceled'

/** A row of `GET /game/class` (and the head of the full caravan). */
export interface GameClassRow {
  id: number
  group_title: string
  course: { slug: string; title: string }
  status: GameClassStatus
  starts_at: string
  ends_at: string
  stars_total: number
  target_stars: number
  runs_total: number
  boss: GameClassBoss
}

export interface GameClassList {
  caravans: GameClassRow[]
}

/** A classmate: the first name only, no id; `is_me` marks my row. */
export interface GameClassMember {
  name: string
  stars: number
  runs: number
  best_score: number
  boss_won: boolean
  is_me: boolean
}

export interface GameClassMy {
  stars: number
  runs: number
  best_score: number
  boss_won: boolean
}

/** Only a finished caravan has it. */
export interface GameClassSummary {
  target_reached: boolean
  boss_defeated: boolean
  finished_at: string | null
}

/** `GET /game/class/{id}`. 404 `class_caravan_not_found`, 403 `game_unavailable`. */
export interface GameClass extends GameClassRow {
  members: GameClassMember[]
  my: GameClassMy | null
  summary: GameClassSummary | null
}

/** `POST /game/runs` for the boss of the class: no course, no lesson. */
export interface GameClassBossStartRequest {
  kind: 'class_boss'
  class_caravan_id: number
  replace_active?: boolean
}
