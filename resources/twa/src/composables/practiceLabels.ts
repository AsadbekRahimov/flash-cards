import { t } from '@/i18n'
import type { IconName } from '@/components/ui/icons'
import type { TileColor } from '@/components/ui/ListRow.vue'
import type { PracticeElementKind, PracticeLessonRef, PracticeMode, PracticeVerdict } from '@/api/types'

/** The order of the cards of the hub: the order of the plan (docs/15 §6). */
export const PRACTICE_MODE_ORDER: readonly PracticeMode[] = ['pairs', 'collocation', 'sentence', 'dictation']

/** The icon tile of a mode: the same one on the hub card, in the "Continue" card and in the invitations. */
export const MODE_LOOK: Record<PracticeMode, { icon: IconName; tile: TileColor }> = {
  pairs: { icon: 'arrows-right-left', tile: 'blue' },
  collocation: { icon: 'puzzle-piece', tile: 'purple' },
  sentence: { icon: 'bars-3-bottom-left', tile: 'orange' },
  dictation: { icon: 'speaker-wave', tile: 'teal' },
}

/** "Find the pairs". */
export const modeTitle = (mode: PracticeMode): string => t(`practice.mode.${mode}.title`)

/**
 * "Module 2 · Lesson 4": where the lesson is, without its name. A lesson outside a module is "Lesson 4" (as in the training
 * header); one whose number the server does not know says nothing - never "Module null" - and the caller falls back to the
 * title or to the course.
 */
export function lessonNumber(lesson: PracticeLessonRef): string {
  if (lesson.number === null) return ''
  return lesson.stage === null
    ? t('training.lesson_header_no_stage', { lesson: lesson.number })
    : t('training.lesson_header', { stage: lesson.stage, lesson: lesson.number })
}

/** "Module 2 · Lesson 4 · Food"; a lesson without a title is "Module 2 · Lesson 4", one without a number is its title. */
export function lessonHeader(lesson: PracticeLessonRef): string {
  return [lessonNumber(lesson), lesson.title].filter((part): part is string => Boolean(part)).join(' · ')
}

/**
 * The verdict in words. A dictation can still be written better, so its "wrong" is "Needs fixing"; a pick, a sentence and a pair
 * that are wrong are simply "Wrong" (docs/14 §6.2): the answer is final, there is nothing to fix, only "Next".
 */
export function verdictLabel(kind: PracticeElementKind, verdict: PracticeVerdict): string {
  if (verdict === 'wrong' && kind !== 'dictation') return t('practice.verdict.wrong_final')
  return t(`practice.verdict.${verdict}`)
}

/** Whole minutes for "≈ 2 min": never less than one. */
export const estimateMinutes = (seconds: number): number => Math.max(1, Math.round(seconds / 60))

/** "1:33" of a duration in milliseconds. */
export function formatDuration(ms: number): string {
  const total = Math.max(0, Math.round(ms / 1000))
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}
