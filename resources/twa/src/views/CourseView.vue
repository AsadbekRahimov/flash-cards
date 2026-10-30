<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { courseChips, progressPercent, progressText } from '@/composables/courseLabels'
import { showErrorToast } from '@/composables/errorToast'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useCoursesStore } from '@/stores/courses'
import { useExamStore } from '@/stores/exam'
import { useTrainingStore } from '@/stores/training'
import { useI18n } from '@/i18n'
import type { CourseLesson, CourseStage } from '@/api/types'

/**
 * One course (docs/14 §6.5): the heading with the progress, the main action ("Continue: module M, lesson N" in the
 * main course, "Make main" in any other), "Practice" under it (the hub of the course, docs/16 §11.1; shown while
 * `/me.practice.available`, with a Pro chip and the paywall while it is behind Pro), the IELTS / SAT mock for Pro, and
 * the modules with their lessons and statuses; a lesson row has "Learn" and a quiet icon button "Practice of the lesson"
 * that closes the line of its progress (the hub of that lesson, same rules). "Back" is the system button (-> the catalog).
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
const billing = useBillingStore()
const { t, tn } = useI18n()

const { detail, detailStatus: status, selecting, errorMessage, nextLesson, allLessonsDone } = storeToRefs(catalog)
const { opening, openError } = storeToRefs(training)
const { openingMock, mockPaywall, mockError } = storeToRefs(exam)

useBackButton({ name: 'courses' })

const offersMock = computed((): boolean => detail.value !== null && detail.value.exam_tag !== null && MOCK_EXAM_TAGS.includes(detail.value.exam_tag))
const mockHint = computed((): string =>
  tn('course.mock_exam_hint', Math.min(MOCK_EXAM_WORDS, detail.value?.words_count ?? MOCK_EXAM_WORDS), { minutes: MOCK_EXAM_MINUTES }),
)

function lessonState(lesson: CourseLesson): LessonState {
  const { done, total } = lesson.progress
  if (total > 0 && done >= total) return 'done'
  return done > 0 ? 'started' : 'new'
}

/** The own name of a module; null for a module without one (`title: null`, or a blank one that an old row may hold). */
function stageTitle(stage: CourseStage): string | null {
  const title = stage.title?.trim()
  return title ? title : null
}

/** "Education"; a module without a name of its own is just "Module 1" - the number, in the language of the reader. */
function stageHeading(stage: CourseStage): string {
  return stageTitle(stage) ?? t('course.module', { number: stage.number })
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

/** "Repeat words" in the paywall sheet: the sheet closes and the daily review starts (the refusal of the mock is Free's). */
const { reviewFromPaywall } = useReviewFromPaywall(() => exam.dismissMockPaywall())

/**
 * Practice (docs/16 §11.1): the entries show while `/me.practice.available` - the button of the course and the small one in
 * every row of a lesson; behind Pro they open the paywall instead.
 */
const practice = computed(() => billing.practiceAccess)
const practicePaywall = ref(false)
const { reviewFromPaywall: reviewFromPracticePaywall } = useReviewFromPaywall(() => {
  practicePaywall.value = false
})

function enterPractice(lessonId: number | null): void {
  if (practice.value.pro_required) practicePaywall.value = true
  else void router.push({ name: 'practice-hub', params: { slug: props.slug }, query: lessonId === null ? {} : { lesson: String(lessonId) } })
}

const openPractice = (): void => enterPractice(null)
const openLessonPractice = (lessonId: number): void => enterPractice(lessonId)

/** The name of the small button of a lesson for a screen reader: "Practice: Module 2 · Lesson 4". */
function practiceLessonLabel(stage: CourseStage, lesson: CourseLesson): string {
  return t('course.practice_lesson', { place: t('training.lesson_header', { stage: stage.number, lesson: lesson.number }) })
}

/** Pro: opens (or resumes) the mock and goes to the exam screen; Free: the paywall sheet. */
async function mockExam(): Promise<void> {
  const sessionId = await exam.openMock(props.slug)
  if (sessionId !== null) await router.push({ name: 'exam', params: { sessionId }, query: { mode: 'mock' } })
}

// "Learn" is a button of the lower modules: a refusal to open the session is a toast, not an alert at the top of a long screen.
watch(openError, (message) => {
  if (message === '') return
  showErrorToast(message)
  training.clearOpenError()
})

onMounted(() => {
  void load()
  // `/me` is kept for half a minute by the store: the Today screen or the paywall may have asked already.
  void billing.load()
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

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-4" data-testid="select-error" />

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
          {{ t('course.continue', { stage: nextLesson.stage, number: nextLesson.number }) }}
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

      <!-- Under the main button, a secondary action: the practice of this course. -->
      <button v-if="practice.available" type="button" class="btn btn-tinted btn-block mt-2" data-testid="practice-btn" @click="openPractice">
        <AppIcon name="puzzle-piece" :size="20" />
        {{ t('practice.hub.title') }}
        <span v-if="practice.pro_required" class="chip chip-accent" data-testid="practice-pro">
          <AppIcon name="sparkles" solid :size="12" />
          {{ t('practice.hub.state.pro') }}
        </span>
      </button>

      <section v-if="offersMock" class="card mt-4" data-testid="mock-exam">
        <div class="flex items-start gap-3">
          <span class="icon-tile tile-purple"><AppIcon name="flag" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('course.mock_exam') }}</h2>
            <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle" data-testid="mock-exam-hint">{{ mockHint }}</p>
          </div>
        </div>
        <InlineAlert v-if="mockError" tone="danger" :message="mockError" class="mt-3" data-testid="mock-exam-error" />
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
          <h2 class="min-w-0 text-[17px] leading-[22px] font-semibold break-words">{{ stageHeading(stage) }}</h2>
          <!-- The number is the heading itself for a module without a name: no second "Модуль N" next to it. -->
          <span v-if="stageTitle(stage) !== null" class="chip chip-neutral shrink-0">{{ t('course.module', { number: stage.number }) }}</span>
        </div>
        <div
          class="progress mt-2 h-1"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="progressPercent(stage.progress)"
          :aria-label="stageHeading(stage)"
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
              <p class="text-[13px] leading-[18px] text-tg-hint">
                <span data-testid="lesson-progress">
                  {{ tn('course.lesson_words', lesson.progress.total, { done: lesson.progress.done, total: lesson.progress.total }) }}
                </span>
                <!--
                  The practice of the lesson: a quiet icon that closes the line of the progress ("learned N of M" - practise it). It is not a
                  second button beside "Learn": at 320 px that took 40 px from the title (the column fell from 138 to 98 px, in Uzbek to 72)
                  and broke words. The button is 36 px (-my cancels the height, the line stays 18 px); its touch area is the 44 x 44 px
                  layer of `.btn-sm`.
                -->
                <button
                  v-if="practice.available"
                  type="button"
                  class="btn btn-plain btn-sm -top-0.5 -my-2.5 ml-1 size-9 px-0 align-middle"
                  :disabled="opening || lesson.progress.total === 0"
                  :aria-label="practiceLessonLabel(stage, lesson)"
                  data-testid="practice-lesson-btn"
                  @click="openLessonPractice(lesson.id)"
                >
                  <AppIcon name="puzzle-piece" :size="20" />
                </button>
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

    <PaywallSheet v-if="mockPaywall" reason="exam_simulator" @close="exam.dismissMockPaywall()" @review="reviewFromPaywall" />
    <PaywallSheet v-if="practicePaywall" reason="practice" @close="practicePaywall = false" @review="reviewFromPracticePaywall" />
  </div>
</template>
