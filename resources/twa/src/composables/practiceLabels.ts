import { t } from '@/i18n'
import type { IconName } from '@/components/ui/icons'
import type { TileColor } from '@/components/ui/ListRow.vue'
import type { PracticeLessonRef, PracticeMode } from '@/api/types'

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

/** "Module 2 · Lesson 4": where the lesson is, without its name. */
export const lessonNumber = (lesson: PracticeLessonRef): string => t('training.lesson_header', { stage: lesson.stage, lesson: lesson.number })

/** "Module 2 · Lesson 4 · Food"; a lesson without a title is "Module 2 · Lesson 4". */
export function lessonHeader(lesson: PracticeLessonRef): string {
  const head = lessonNumber(lesson)
  return lesson.title ? `${head} · ${lesson.title}` : head
}

/** Whole minutes for "≈ 2 min": never less than one. */
export const estimateMinutes = (seconds: number): number => Math.max(1, Math.round(seconds / 60))

/** "1:33" of a duration in milliseconds. */
export function formatDuration(ms: number): string {
  const total = Math.max(0, Math.round(ms / 1000))
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}
