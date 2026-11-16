import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { useCoursesStore } from '@/stores/courses'
import { t } from '@/i18n'

/**
 * "Start the course" / "Switch to this course" (catalog and course screen): the same words for the same action and the
 * same way on. After the choice the learner is on Home with "Course chosen" and the ready "Start session" button, not
 * left in the catalog to wonder what happened. A refusal stays on the screen (the store's `errorMessage`).
 */
export function useCourseSelect() {
  const router = useRouter()
  const catalog = useCoursesStore()
  const toast = useToast()

  /** The learner has a course already: the button says "Switch", not "Start". */
  const hasActiveCourse = computed(() => catalog.courses.some((course) => course.is_active))
  const chooseLabel = computed(() => t(hasActiveCourse.value ? 'catalog.choose' : 'catalog.start'))

  async function choose(slug: string): Promise<void> {
    if (!(await catalog.select(slug))) return
    toast.show(t('catalog.selected'))
    await router.push({ name: 'today' })
  }

  return { hasActiveCourse, chooseLabel, choose }
}
