import type { DayPlan, PracticeAccessToday } from '@/api/types'

/** A step of the plan of the day (docs/31 §5б): the lesson, the review of the lesson, the game on its words. */
export type DayPlanStepKey = 'lesson' | 'training' | 'game'
export type DayPlanStepState = 'done' | 'current' | 'upcoming'

export interface DayPlanStep {
  key: DayPlanStepKey
  state: DayPlanStepState
}

const STEP_KEYS: Record<1 | 2 | 3, DayPlanStepKey> = { 1: 'lesson', 2: 'training', 3: 'game' }

/**
 * Whether the game step can come today at all, before the lesson is done (`plan.game` is null until then, docs/04 A.2a): the practice
 * of `/today` is open, not behind Pro, and has the timed pairs. Absent `practice` (an older server) - no.
 */
export function gameStepExpected(practice: PracticeAccessToday | undefined): boolean {
  return practice !== undefined && practice.available && !practice.pro_required && practice.modes.includes('pairs_timed')
}

/**
 * The steps of the plan to show, in order, with their state. Before the lesson of the day `training` and `game` are null by the server's
 * rule, so the review is shown as coming and the game - when the practice says it can come (`gameExpected`); once the lesson is done a
 * null step is switched off (the review mode is off, the game is closed or behind Pro) and is left out: "step N of M" counts only these.
 * A step before the current one reads as done even when the server has no flag for it.
 */
export function dayPlanSteps(plan: DayPlan, gameExpected: boolean): DayPlanStep[] {
  const keys: DayPlanStepKey[] = ['lesson']
  if (plan.training !== null || !plan.lesson.done) keys.push('training')
  if (plan.game !== null || (!plan.lesson.done && gameExpected)) keys.push('game')

  const current = plan.step === null ? null : STEP_KEYS[plan.step]
  const currentIndex = current === null ? -1 : keys.indexOf(current)
  const doneFlag: Record<DayPlanStepKey, boolean> = {
    lesson: plan.lesson.done,
    training: plan.training?.done === true,
    game: plan.game?.played === true,
  }

  return keys.map((key, index): DayPlanStep => {
    if (plan.done || (key !== current && doneFlag[key])) return { key, state: 'done' }
    if (key === current) return { key, state: 'current' }
    return { key, state: currentIndex >= 0 && index < currentIndex ? 'done' : 'upcoming' }
  })
}
