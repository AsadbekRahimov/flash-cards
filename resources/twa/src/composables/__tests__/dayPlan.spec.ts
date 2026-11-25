import { describe, expect, it } from 'vitest'
import { dayPlanStepPosition, dayPlanSteps, gameStepExpected } from '../dayPlan'
import type { DayPlan } from '@/api/types'

const plan = (overrides: Partial<DayPlan> = {}): DayPlan => ({
  step: 1,
  done: false,
  lesson: { done: false, lesson_id: null, next_lesson_id: 41, words_left: 5 },
  training: null,
  game: null,
  ...overrides,
})

const lessonDone = { done: true, lesson_id: 40, next_lesson_id: 41, words_left: 6 }
const training = { lesson_id: 40, title: 'Напитки', words: 5, done: false, open_session_id: null }
const game = { lesson_id: 40, mode: 'pairs_timed', played: false } as const

describe('dayPlanSteps', () => {
  it('shows the review and, when it can come, the game before the lesson of the day', () => {
    expect(dayPlanSteps(plan(), true)).toEqual([
      { key: 'lesson', state: 'current' },
      { key: 'training', state: 'upcoming' },
      { key: 'game', state: 'upcoming' },
    ])
    expect(dayPlanSteps(plan(), false).map((s) => s.key)).toEqual(['lesson', 'training'])
  })

  it('leaves out a step the server switched off once the lesson is done', () => {
    expect(dayPlanSteps(plan({ step: 3, lesson: lessonDone, game }), true)).toEqual([
      { key: 'lesson', state: 'done' },
      { key: 'game', state: 'current' },
    ])
    expect(dayPlanSteps(plan({ step: 2, lesson: lessonDone, training }), true)).toEqual([
      { key: 'lesson', state: 'done' },
      { key: 'training', state: 'current' },
    ])
  })

  it('reads a step before the current one as done, and every step as done when the chain is complete', () => {
    expect(dayPlanSteps(plan({ step: 3, lesson: lessonDone, training, game }), true)[1]).toEqual({ key: 'training', state: 'done' })
    expect(dayPlanSteps(plan({ step: null, done: true, lesson: lessonDone, training, game }), true).every((s) => s.state === 'done')).toBe(true)
  })

  it('marks a later step done by its own flag (the game played before the review)', () => {
    expect(dayPlanSteps(plan({ step: 2, lesson: lessonDone, training, game: { ...game, played: true } }), true)[2]).toEqual({ key: 'game', state: 'done' })
  })
})

describe('gameStepExpected', () => {
  const practice = { available: true, pro_required: false, modes: ['pairs_timed' as const], battle: false, pro_promise: 'none' as const }

  it('is true only for an open practice with the timed pairs', () => {
    expect(gameStepExpected(practice)).toBe(true)
    expect(gameStepExpected({ ...practice, available: false })).toBe(false)
    expect(gameStepExpected({ ...practice, pro_required: true })).toBe(false)
    expect(gameStepExpected({ ...practice, modes: ['pairs'] })).toBe(false)
    expect(gameStepExpected(undefined)).toBe(false)
  })
})

describe('dayPlanStepPosition', () => {
  it('counts the steps the plan really has: "step 2 of 2" for the game without the review', () => {
    expect(dayPlanStepPosition(plan({ step: 3, lesson: lessonDone, game }), true, 'game')).toEqual({ step: 2, total: 2 })
    expect(dayPlanStepPosition(plan({ step: 2, lesson: lessonDone, training, game }), true, 'training')).toEqual({ step: 2, total: 3 })
    expect(dayPlanStepPosition(plan({ step: 3, lesson: lessonDone, training, game }), true, 'game')).toEqual({ step: 3, total: 3 })
  })

  it('is null without a plan, or for a step the plan does not have', () => {
    expect(dayPlanStepPosition(null, true, 'game')).toBeNull()
    expect(dayPlanStepPosition(undefined, true, 'training')).toBeNull()
    expect(dayPlanStepPosition(plan({ step: 2, lesson: lessonDone, training }), true, 'game')).toBeNull()
  })
})
