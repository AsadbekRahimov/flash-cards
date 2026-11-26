import { t, tn } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { SegmentedNavItem } from '@/components/ui/SegmentedNav.vue'
import type { CourseSection, Progress } from '@/api/types'

const GOAL_KEYS: Record<string, StringKey> = {
  general: 'catalog.goal.general',
  school: 'catalog.goal.school',
  ielts: 'catalog.goal.ielts',
  sat: 'catalog.goal.sat',
  phrases: 'catalog.goal.phrases',
}

/** Goals of the exam-preparation courses: their chip is highlighted in the accent colour (docs/14 §6.5). */
const EXAM_GOALS = ['ielts', 'sat']

/** One fact about a course or a deck, drawn as a `.chip` on its card. */
export interface CourseChip {
  /** Which fact it states; also a stable `v-for` key. */
  key: 'level' | 'goal' | 'words'
  /** Already localized. */
  label: string
  /** The template turns it into `chip-accent` / `chip-neutral`. */
  tone: 'neutral' | 'accent'
}

/** A course of the "Phrases" section of the catalog (docs/35 §3.3 a); a course without the field (an older server) is a usual one. */
export function isPhraseCourse(course: { section?: CourseSection } | null | undefined): boolean {
  return course?.section === 'phrases'
}

/**
 * Chips of a course: level ("A1"), goal ("General English"; "IELTS" / "SAT" in the accent colour) and size
 * ("900 words"; "300 phrases" in a course of phrases). Level and goal are left out when the course has none; a course of phrases has
 * no goal chip - its section already says what it is.
 */
export function courseChips(course: { level: string | null; exam_tag: string | null; words_count: number; section?: CourseSection }): CourseChip[] {
  const chips: CourseChip[] = []
  const phrases = isPhraseCourse(course)
  if (course.level !== null && course.level !== '') chips.push({ key: 'level', label: course.level, tone: 'neutral' })

  const goal = phrases ? null : goalLabel(course.exam_tag)
  if (goal !== null) chips.push({ key: 'goal', label: goal, tone: EXAM_GOALS.includes(course.exam_tag ?? '') ? 'accent' : 'neutral' })

  chips.push({ key: 'words', label: tn(phrases ? 'catalog.phrases' : 'catalog.words', course.words_count), tone: 'neutral' })
  return chips
}

/** Localized name of a course goal (`exam_tag`); an unknown tag is shown as is. */
export function goalLabel(tag: string | null): string | null {
  if (tag === null || tag === '') return null
  const key = GOAL_KEYS[tag]
  return key ? t(key) : tag
}

/** Share of learned words, 0..100 (a progress bar of an empty course stays empty). */
export function progressPercent(progress: Progress): number {
  return progress.total > 0 ? Math.min(100, Math.round((progress.done / progress.total) * 100)) : 0
}

/** "Learned 184 of 900": the caption of a progress bar. */
export function progressText(progress: Progress): string {
  return t('catalog.progress', { done: progress.done, total: progress.total })
}

/** "Courses | My decks" under the heading of both roots of the Courses tab. */
export function catalogTabs(): SegmentedNavItem[] {
  return [
    { to: { name: 'courses' }, label: t('catalog.title'), testid: 'seg-courses' },
    { to: { name: 'decks' }, label: t('decks.title'), testid: 'seg-decks' },
  ]
}
