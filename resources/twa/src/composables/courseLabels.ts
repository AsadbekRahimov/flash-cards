import { t, tn } from '@/i18n'
import type { StringKey } from '@/i18n/ru'

const GOAL_KEYS: Record<string, StringKey> = {
  general: 'catalog.goal.general',
  school: 'catalog.goal.school',
  ielts: 'catalog.goal.ielts',
  sat: 'catalog.goal.sat',
}

/** "A1 · General English · 500 words": level and goal are left out when the course has none. */
export function courseMeta(course: { level: string | null; exam_tag: string | null; words_count: number }): string {
  return [course.level, goalLabel(course.exam_tag), tn('catalog.words', course.words_count)]
    .filter((part): part is string => part !== null && part !== '')
    .join(' · ')
}

/** Localized name of a course goal (`exam_tag`); an unknown tag is shown as is. */
export function goalLabel(tag: string | null): string | null {
  if (tag === null || tag === '') return null
  const key = GOAL_KEYS[tag]
  return key ? t(key) : tag
}
