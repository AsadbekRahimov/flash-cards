import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { errorMessage } from '@/i18n/errors'
import { useBillingStore } from '@/stores/billing'
import type { GameMapResponse, GameStartRequest } from '@/api/types'

/** idle → loading (the map is being read) → ready; unavailable (403 `game_unavailable`) and error end it. */
export type GameMapStatus = 'idle' | 'loading' | 'ready' | 'unavailable' | 'error'

/** Why a start was refused: what the screen decides by (`code`, `details`) and a localized text for a toast. */
export interface GameStartError {
  code: string
  kind: ApiClientError['kind'] | 'unexpected'
  details: Record<string, unknown> | null
  message: string
}

export type GameStartResult = { ok: true; id: number } | { ok: false; error: GameStartError }

const busy = (): GameStartResult => ({ ok: false, error: { code: 'busy', kind: 'unexpected', details: null, message: '' } })

interface GameState {
  /** The map being shown and the slug it was read for. */
  map: GameMapResponse | null
  course: string | null
  status: GameMapStatus
  /** Localized text of the last failed reading. */
  error: string
  /** Number of the newest map request: an older answer that comes late is dropped. */
  seq: number
  /** A start is on its way: a second press waits. */
  starting: boolean
}

/**
 * The Karvon map (docs/05 §5b.2, docs/18 §12.9): the map of a course and the start of a run of one stop. `me.game` (access, limits) is
 * part of `/me` and lives in the billing store - this store never asks for `/me`. The run itself (`gameRun`) is another store.
 */
export const useGameStore = defineStore('game', {
  state: (): GameState => ({
    map: null,
    course: null,
    status: 'idle',
    error: '',
    seq: 0,
    starting: false,
  }),

  actions: {
    /** Reads the map of `course` (the server needs a slug). A stale screen stays visible while the same course is read again. */
    async loadMap(course: string): Promise<void> {
      const seq = ++this.seq
      if (this.course !== course) this.map = null
      this.course = course
      this.status = this.map === null ? 'loading' : this.status
      this.error = ''
      try {
        const map = await api.game.map(course)
        if (seq !== this.seq) return
        this.map = map
        this.status = 'ready'
        useBillingStore().setGameLimits(map.limits)
      } catch (err) {
        if (seq !== this.seq) return
        this.map = null
        if (err instanceof ApiClientError && err.code === 'game_unavailable') {
          this.status = 'unavailable'
          return
        }
        this.status = 'error'
        this.error = errorMessage(err, 'game')
      }
    },

    /**
     * Starts a run of the stop (a lesson of the loaded map's course). 201 → the id of the run; a refusal comes back typed, never thrown:
     * 403 `game_limit_reached` / `game_pro_required` (the paywall), 409 `game_run_active` (`details`: the run that is on), 409 `stop_locked`,
     * 422 `game_not_enough_material`, and the rest as a text for a toast.
     */
    async startStop(lessonId: number, replaceActive = false): Promise<GameStartResult> {
      const course = this.map?.course.slug ?? this.course
      if (course === null) return busy()
      return this.start({ kind: 'stop', course, lesson_id: lessonId, ...(replaceActive ? { replace_active: true } : {}) })
    },

    /**
     * Starts the caravan of the day (docs/05 §5b.4): `POST /game/runs {kind: 'daily'}`, the course is the active one on the server. The
     * refusals are typed like a stop's; two matter here: 409 `game_daily_done {resets_at}` (the day is spent, whoever spent it) and
     * 422 `game_not_enough_material`.
     */
    async startDaily(replaceActive = false): Promise<GameStartResult> {
      return this.start({ kind: 'daily', ...(replaceActive ? { replace_active: true } : {}) })
    },

    /** Starts the fight with the boss of a city of the course (docs/05 §5b.8); 403 `game_pro_required` / `game_limit_reached` are the paywall, 409 `boss_not_ready`. */
    async startBoss(course: string, stageId: number, replaceActive = false): Promise<GameStartResult> {
      return this.start({ kind: 'boss', course, stage_id: stageId, ...(replaceActive ? { replace_active: true } : {}) })
    },

    /** The learner opened the share dialog of the card of the city: the mark. A refusal is silent - the dialog is open already, a repeat is harmless. */
    async markCityShared(stageId: number): Promise<void> {
      try {
        await api.game.cityShared(stageId)
      } catch {
        // nothing to tell the learner
      }
    },

    /** One start at a time: a second press waits. */
    async start(body: GameStartRequest): Promise<GameStartResult> {
      if (this.starting) return busy()
      this.starting = true
      try {
        const run = await api.game.startRun(body)
        return { ok: true, id: run.id }
      } catch (err) {
        const message = errorMessage(err, 'game')
        if (err instanceof ApiClientError) return { ok: false, error: { code: err.code, kind: err.kind, details: err.details, message } }
        return { ok: false, error: { code: 'unexpected', kind: 'unexpected', details: null, message } }
      } finally {
        this.starting = false
      }
    },

    /** The screen is left: an answer that comes after it is dropped. */
    reset(): void {
      this.seq++
      this.map = null
      this.course = null
      this.status = 'idle'
      this.error = ''
      this.starting = false
    },
  },
})
