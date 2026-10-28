<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { courseChips, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { useExamStore } from '@/stores/exam'
import { useTrainingStore } from '@/stores/training'
import { useI18n } from '@/i18n'
import type { CourseLesson } from '@/api/types'

/**
 * One course (docs/14 §6.5): the heading with the progress, the main action ("Continue: lesson N" in the main
 * course, "Make main" in any other), the IELTS / SAT mock for Pro, and the modules with their lessons and
 * statuses. "Back" is the system button (-> the catalog).
 */

/** Courses with an IELTS / SAT mock (docs/13 §8.4); the server refuses the others anyway. */
const MOCK_EXAM_TAGS = ['ielts', 'sat']
const MOCK_EXAM_WORDS = 40
const MOCK_EXAM_MINUTES = 20

type LessonState = 'done' | 'started' | 'new'

/** Look of the status mark of a lesson row: a check, a filled circle with the number, a quiet circle with the number. */
const STATE_CLASS: Record<LessonState, string> = {
  done: 'text-success',
  started: 'bg-accent text-tg-button-text',
  new: 'bg-fill text-tg-subtitle',
}

const props = defineProps<{ slug: string }>()

const router = useRouter()
const auth = useAuthStore()
const catalog = useCoursesStore()
const training = useTrainingStore()
const exam = useExamStore()
const { t } = useI18n()

const { detail, detailStatus: status, selecting, errorMessage, nextLesson, allLessonsDone } = storeToRefs(catalog)
const { opening, openError } = storeToRefs(training)
const { openingMock, mockPaywall, mockError } = storeToRefs(exam)

useBackButton({ name: 'courses' })

const offersMock = computed((): boolean => detail.value !== null && detail.value.exam_tag !== null && MOCK_EXAM_TAGS.includes(detail.value.exam_tag))
const mockHint = computed((): string => t('course.mock_exam_hint', {
  count: Math.min(MOCK_EXAM_WORDS, detail.value?.words_count ?? MOCK_EXAM_WORDS),
  minutes: MOCK_EXAM_MINUTES,
}))

function lessonState(lesson: CourseLesson): LessonState {
  const { done, total } = lesson.progress
  if (total > 0 && done >= total) return 'done'
  return done > 0 ? 'started' : 'new'
}

/** "Lesson 1 · University"; a lesson without a title is just "Lesson 1". */
function lessonTitle(lesson: CourseLesson): string {
  const number = t('course.lesson', { number: lesson.number })
  return lesson.title ? `${number} · ${lesson.title}` : number
}

async function load(): Promise<void> {
  try {
    await auth.ensure()
    await catalog.loadDetail(props.slug)
  } catch (err) {
    catalog.fail(err, 'detail')
  }
}

async function learn(lessonId: number): Promise<void> {
  const sessionId = await training.open({ mode: 'lesson', lesson_id: lessonId, source: 'picker' })
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

/** Pro: opens (or resumes) the mock and goes to the exam screen; Free: the paywall sheet. */
async function mockExam(): Promise<void> {
  const sessionId = await exam.openMock(props.slug)
  if (sessionId !== null) await router.push({ name: 'exam', params: { sessionId }, query: { mode: 'mock' } })
}

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  training.clearOpenError()
  exam.clearMockError()
})
</script>

<template>
  <div class="page">
    <LoadingState v-if="status === 'idle' || status === 'loading'" variant="list" />

    <ErrorState v-else-if="status === 'error' || detail === null" :message="errorMessage" @retry="load" />

    <template v-else>
      <PageHeader :title="detail.title" data-testid="title" />

      <div class="flex flex-wrap gap-1.5" data-testid="meta">
        <span v-for="chip in courseChips(detail)" :key="chip.key" class="chip" :class="`chip-${chip.tone}`">{{ chip.label }}</span>
      </div>

      <p v-if="detail.description" class="mt-3 text-[15px] leading-5 text-tg-subtitle">{{ detail.description }}</p>

      <div class="mt-4">
        <div
          class="progress"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="progressPercent(detail.progress)"
          :aria-label="progressText(detail.progress)"
          data-testid="progress-bar"
        >
          <div class="progress-fill" :style="{ width: `${progressPercent(detail.progress)}%` }" data-testid="progress-fill" />
        </div>
        <div class="mt-1.5 flex items-center justify-between gap-3">
          <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="progress-text">{{ progressText(detail.progress) }}</p>
          <span v-if="detail.is_active" class="chip chip-success shrink-0" data-testid="active-badge">
            <AppIcon name="check" solid :size="14" />
            {{ t('catalog.active') }}
          </span>
        </div>
      </div>

      <p
        v-if="errorMessage"
        class="mt-4 flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-2.5 text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="select-error"
      >
        <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
        <span>{{ errorMessage }}</span>
      </p>
      <p
        v-if="openError"
        class="mt-4 flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-2.5 text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="open-error"
      >
        <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
        <span>{{ openError }}</span>
      </p>

      <!-- The one primary button of the screen: continue in the main course, make the course main otherwise. -->
      <template v-if="detail.is_active">
        <button
          v-if="nextLesson"
          type="button"
          class="btn btn-primary btn-lg btn-block mt-4"
          :disabled="opening"
          data-testid="continue-btn"
          @click="learn(nextLesson.id)"
        >
          {{ t('course.continue', { number: nextLesson.number }) }}
        </button>
        <p
          v-else-if="allLessonsDone"
          class="mt-4 flex items-center justify-center gap-2 text-[15px] leading-5 font-medium text-success-ink"
          data-testid="all-done"
        >
          <AppIcon name="check-circle" solid :size="20" />
          {{ t('course.all_done') }}
        </p>
      </template>
      <template v-else>
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block mt-4"
          :disabled="selecting !== null"
          data-testid="choose-btn"
          @click="catalog.select(detail.slug)"
        >
          {{ t('catalog.choose') }}
        </button>
        <p class="mt-2 px-4 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="choose-hint">{{ t('course.choose_hint') }}</p>
      </template>

      <section v-if="offersMock" class="card mt-4" data-testid="mock-exam">
        <div class="flex items-start gap-3">
          <span class="icon-tile tile-purple"><AppIcon name="flag" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('course.mock_exam') }}</h2>
            <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle" data-testid="mock-exam-hint">{{ mockHint }}</p>
          </div>
        </div>
        <p
          v-if="mockError"
          class="mt-3 flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-2.5 text-[15px] leading-5 text-danger-ink"
          role="alert"
          data-testid="mock-exam-error"
        >
          <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
          <span>{{ mockError }}</span>
        </p>
        <button
          type="button"
          class="btn btn-tinted btn-block mt-3"
          :disabled="openingMock"
          data-testid="mock-exam-btn"
          @click="mockExam()"
        >
          {{ t('course.mock_exam_start') }}
        </button>
      </section>

      <EmptyState v-if="detail.stages.length === 0" icon="book-open" :text="t('course.no_lessons')" data-testid="no-lessons" />

      <section v-for="stage in detail.stages" :key="stage.number" class="mt-6" :data-testid="`stage-${stage.number}`">
        <div class="flex items-center justify-between gap-3">
          <h2 class="min-w-0 text-[17px] leading-[22px] font-semibold break-words">{{ stage.title }}</h2>
          <span class="chip chip-neutral shrink-0">{{ t('course.module', { number: stage.number }) }}</span>
        </div>
        <div
          class="progress mt-2 h-1"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="progressPercent(stage.progress)"
          :aria-label="stage.title"
          data-testid="progress-bar"
        >
          <div class="progress-fill" :style="{ width: `${progressPercent(stage.progress)}%` }" data-testid="progress-fill" />
        </div>

        <ListGroup v-if="stage.lessons.length > 0" class="mt-3">
          <div v-for="lesson in stage.lessons" :key="lesson.id" class="list-row list-row-icon" :data-testid="`lesson-${lesson.id}`">
            <span
              class="flex size-[30px] shrink-0 items-center justify-center rounded-full text-sm font-semibold"
              :class="STATE_CLASS[lessonState(lesson)]"
              :data-state="lessonState(lesson)"
              data-testid="lesson-status"
            >
              <AppIcon v-if="lessonState(lesson) === 'done'" name="check-circle" solid :size="30" />
              <template v-else>{{ lesson.number }}</template>
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-base leading-[22px] break-words">{{ lessonTitle(lesson) }}</p>
              <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="lesson-progress">
                {{ t('course.lesson_words', { done: lesson.progress.done, total: lesson.progress.total }) }}
              </p>
            </div>
            <button
              type="button"
              class="btn btn-tinted btn-sm shrink-0"
              :disabled="opening || lesson.progress.total === 0"
              data-testid="learn-btn"
              @click="learn(lesson.id)"
            >
              {{ t('course.learn') }}
            </button>
          </div>
        </ListGroup>
      </section>
    </template>

    <PaywallSheet v-if="mockPaywall" reason="exam_simulator" @close="exam.dismissMockPaywall()" />
  </div>
</template>
