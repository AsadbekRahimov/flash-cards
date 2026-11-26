<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { catalogTabs, courseChips, isPhraseCourse, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useCourseSelect } from '@/composables/useCourseSelect'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useI18n } from '@/i18n'
import type { Course } from '@/api/types'

/**
 * The catalog of courses (docs/14 §6.5, docs/design/2026-11-25-redesign): every course with the learner's progress. The "Course" tab
 * opens the learner's own course; this list is reached from it ("All courses"), and is the tab itself while there is no course yet.
 * A card opens the course; its button starts the course (or switches to it: the source of the new words of the daily session) and
 * leads to Home. The learner's course is the big tinted card (`bg-highlight`, `rounded-hero`), the others white cards. The twin screen
 * is "My decks" (the switch under the heading). A root of the tab: no system "Back" button - except when it was opened by "All courses"
 * of a course (`?from=<slug>`): then the system "Back" returns to that course.
 *
 * The courses of phrases (`section = phrases`, docs/35 §3.2) are a group of their own under the usual ones: the heading "Phrases" with a
 * short line of what they are, the same cards ("300 phrases"). A list without such courses (or an older server without `section`) is drawn
 * as before.
 */
const auth = useAuthStore()
const catalog = useCoursesStore()
const { t } = useI18n()
const { chooseLabel, choose } = useCourseSelect()

const { courses, listStatus: status, selecting, errorMessage } = storeToRefs(catalog)

const tabs = computed(catalogTabs)

/** The usual courses first, then the section of phrases (the order of the server inside each). */
const groups = computed((): { key: 'courses' | 'phrases'; courses: Course[] }[] => {
  const phrases = courses.value.filter((course) => isPhraseCourse(course))
  const rest = courses.value.filter((course) => !isPhraseCourse(course))
  return [
    ...(rest.length > 0 ? [{ key: 'courses' as const, courses: rest }] : []),
    ...(phrases.length > 0 ? [{ key: 'phrases' as const, courses: phrases }] : []),
  ]
})

const route = useRoute()
const fromCourse = typeof route.query.from === 'string' && route.query.from !== '' ? route.query.from : null
useBackButton(fromCourse === null ? null : { name: 'course', params: { slug: fromCourse } })

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await catalog.load()
  } catch (err) {
    catalog.fail(err, 'list')
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <PageHeader :title="t('catalog.title')" large />
    <SegmentedNav :items="tabs" :ariaLabel="t('catalog.title')" />
    <p class="mt-3 text-[13px] leading-[18px] text-muted" data-testid="hint">{{ t('catalog.hint') }}</p>

    <LoadingState v-if="status === 'idle' || status === 'loading'" class="mt-4" />

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="load" />

    <EmptyState v-else-if="courses.length === 0" icon="academic-cap" :text="t('catalog.empty')" />

    <div v-else class="mt-4 space-y-2.5">
      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" data-testid="select-error" />

      <!-- The course the learner is on is the big tinted card of the layout; the others are white cards. -->
      <template v-for="group in groups" :key="group.key">
        <div v-if="group.key === 'phrases'" class="pt-3" data-testid="phrases-section">
          <h2 class="text-[22px] leading-7 font-extrabold tracking-[-0.3px]" data-testid="phrases-title">{{ t('catalog.phrases_title') }}</h2>
          <p class="mt-1 text-[13px] leading-[18px] text-muted" data-testid="phrases-hint">{{ t('catalog.phrases_hint') }}</p>
        </div>
        <article
          v-for="course in group.courses"
          :key="course.slug"
          :class="course.is_active ? 'rounded-hero bg-highlight p-[18px]' : 'card'"
          :data-testid="`course-${course.slug}`"
        >
          <span v-if="course.is_active" class="chip chip-success mb-2.5" data-testid="active-badge">
            {{ t('catalog.active') }}
          </span>
          <RouterLink
            :to="{ name: 'course', params: { slug: course.slug } }"
            class="flex items-start gap-2 active:opacity-60"
            data-testid="course-link"
          >
            <div class="min-w-0 flex-1">
              <h2 class="text-lg leading-6 font-extrabold tracking-[-0.2px] break-words">{{ course.title }}</h2>
              <div class="mt-2 flex flex-wrap gap-1.5" data-testid="meta">
                <span v-for="chip in courseChips(course)" :key="chip.key" class="chip" :class="`chip-${chip.tone}`">{{ chip.label }}</span>
              </div>
              <p v-if="course.description" class="mt-2 line-clamp-2 text-[15px] leading-5 text-muted">{{ course.description }}</p>
            </div>
            <AppIcon name="chevron-right" :size="20" class="mt-0.5 text-muted" />
          </RouterLink>

          <div class="mt-3.5 flex flex-col-reverse gap-1.5">
            <ProgressLine
              :value="progressPercent(course.progress)"
              tone="success"
              :label="progressText(course.progress)"
              class="w-full"
              :class="{ 'bg-accent-line': course.is_active }"
              data-testid="progress-bar"
            />
            <span class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="progress-text">{{ progressText(course.progress) }}</span>
          </div>

          <div class="mt-3.5">
            <RouterLink v-if="course.is_active" :to="{ name: 'course', params: { slug: course.slug } }" class="btn btn-primary btn-block">
              {{ t('catalog.open_lessons') }}
              <AppIcon name="arrow-right" :size="17" />
            </RouterLink>
            <button
              v-else
              type="button"
              class="btn btn-tinted btn-block"
              :disabled="selecting !== null"
              data-testid="choose-btn"
              @click="choose(course.slug)"
            >
              {{ chooseLabel }}
            </button>
          </div>
        </article>
      </template>
    </div>
  </div>
</template>
