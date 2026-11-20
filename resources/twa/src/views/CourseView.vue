<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { courseChips, progressPercent, progressText } from '@/composables/courseLabels'
import { showErrorToast } from '@/composables/errorToast'
import { useBackButton } from '@/composables/useBackButton'
import { useCourseSelect } from '@/composables/useCourseSelect'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useCoursesStore } from '@/stores/courses'
import { useExamStore } from '@/stores/exam'
import { useTrainingStore } from '@/stores/training'
import { useI18n } from '@/i18n'
import type { CourseLesson, CourseStage } from '@/api/types'

/**
 * One course (docs/14 §6.5, docs/design/2026-11-redesign/Path.html): the heading with the progress of the course, "Start course" /
 * "Switch to this course" in any course but the learner's own, then one module at a time - its number of the total, its name,
 * a thin line of the words learned and the numbered lessons 01, 02... In the learner's course the current lesson (the first
 * unfinished one) is the highlighted row with the one primary button "Learn the lesson"; any other lesson keeps a quiet "Learn" and,
 * while `/me.practice.available`, a small "Practice" (the hub of that lesson, with the paywall while it is behind Pro). Under the
 * module: the previous / next module and the readiness for the level; under them "Practice" of the course, the Karvon map and the
 * IELTS / SAT mock. The module shown first is the one of the current lesson. "Back" is the system button (-> the catalog).
 */

/** Courses with an IELTS / SAT mock (docs/13 §8.4); the server refuses the others anyway. */
const MOCK_EXAM_TAGS = ['ielts', 'sat']
const MOCK_EXAM_WORDS = 40
const MOCK_EXAM_MINUTES = 20

/** done: every word learned; current: where the learner is (their course only); started / new: by the words learned. */
type LessonState = 'done' | 'current' | 'started' | 'new'

const props = defineProps<{ slug: string }>()

const router = useRouter()
const auth = useAuthStore()
const catalog = useCoursesStore()
const training = useTrainingStore()
const exam = useExamStore()
const billing = useBillingStore()
const { t, tn } = useI18n()
const { chooseLabel, choose } = useCourseSelect()

const { detail, detailStatus: status, selecting, errorMessage, nextLesson, allLessonsDone } = storeToRefs(catalog)
const { opening, openError } = storeToRefs(training)
const { openingMock, mockPaywall, mockError } = storeToRefs(exam)

useBackButton({ name: 'courses' })

/** Readiness for the level has something to count only in a course with words. */
const offersReadiness = computed(() => (detail.value?.words_count ?? 0) > 0)
const offersMock = computed((): boolean => detail.value !== null && detail.value.exam_tag !== null && MOCK_EXAM_TAGS.includes(detail.value.exam_tag))
const mockHint = computed((): string =>
  tn('course.mock_exam_hint', Math.min(MOCK_EXAM_WORDS, detail.value?.words_count ?? MOCK_EXAM_WORDS), { minutes: MOCK_EXAM_MINUTES }),
)

/** The lesson of the one primary button: the first unfinished lesson of the course the learner is on. */
const currentLessonId = computed((): number | null => (detail.value?.is_active === true ? (nextLesson.value?.id ?? null) : null))

function lessonState(lesson: CourseLesson): LessonState {
  const { done, total } = lesson.progress
  if (total > 0 && done >= total) return 'done'
  if (lesson.id === currentLessonId.value) return 'current'
  return done > 0 ? 'started' : 'new'
}

/** "01": the number of a lesson in the left column, two digits as on the layout. */
function lessonNumber(lesson: CourseLesson): string {
  return String(lesson.number).padStart(2, '0')
}

/** "3 of 10 words" once the lesson is begun; "10 words" before (nothing learned to count yet). */
function lessonWords(lesson: CourseLesson): string {
  const { done, total } = lesson.progress
  return done > 0 || total === 0 ? tn('course.lesson_words', total, { done, total }) : tn('catalog.words', total)
}

/**
 * The module on the screen: the one the learner picked with the arrows, else the module of the first unfinished lesson, else the
 * first. One module at a time (the layout): twenty modules of five lessons in a row made a page of 9000 px.
 */
const pickedStage = ref<number | null>(null)
const stageIndex = computed((): number => {
  const stages = detail.value?.stages ?? []
  const wanted = pickedStage.value ?? nextLesson.value?.stage ?? null
  const index = stages.findIndex((stage) => stage.number === wanted)
  return index === -1 ? 0 : index
})
const shownStage = computed((): CourseStage | null => detail.value?.stages[stageIndex.value] ?? null)
const prevStage = computed((): CourseStage | null => detail.value?.stages[stageIndex.value - 1] ?? null)
const nextStage = computed((): CourseStage | null => detail.value?.stages[stageIndex.value + 1] ?? null)

function showStage(stage: CourseStage): void {
  pickedStage.value = stage.number
}

watch(
  () => props.slug,
  () => {
    pickedStage.value = null
  },
)

/** The own name of a module; null for a module without one (`title: null`, or a blank one that an old row may hold). */
function stageTitle(stage: CourseStage): string | null {
  const title = stage.title?.trim()
  return title ? title : null
}

/** "Education"; a module without a name of its own is just "Module 1" - the number, in the language of the reader. */
function stageHeading(stage: CourseStage): string {
  return stageTitle(stage) ?? t('course.module', { number: stage.number })
}

/** "University" (the number stands in its own column); a lesson without a title is "Lesson 1". */
function lessonTitle(lesson: CourseLesson): string {
  const title = lesson.title?.trim()
  return title ? title : t('course.lesson', { number: lesson.number })
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
/** The Karvon map of this course (docs/05 §5b.1): the entry shows while `/me.game.available`, which is read here already - no extra request. */
const gameOn = computed(() => billing.gameAccess.available)
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
  // Whether the learner has a course already decides "Start course" / "Switch to this course": the list is the catalog's.
  void catalog.loadListQuietly()
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

      <p v-if="detail.description" class="mt-3 text-[15px] leading-5 text-muted">{{ detail.description }}</p>

      <div class="mt-4 flex items-center gap-3">
        <ProgressLine
          :value="progressPercent(detail.progress)"
          tone="success"
          :label="progressText(detail.progress)"
          class="flex-1"
          data-testid="progress-bar"
        />
        <span class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="progress-text">{{ progressText(detail.progress) }}</span>
      </div>
      <span v-if="detail.is_active" class="chip chip-success mt-3 self-start" data-testid="active-badge">{{ t('catalog.active') }}</span>

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-4" data-testid="select-error" />

      <!-- Not the learner's course: making it theirs is the one primary button. In their course it is "Learn the lesson" below. -->
      <template v-if="!detail.is_active">
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block mt-4"
          :disabled="selecting !== null"
          data-testid="choose-btn"
          @click="choose(detail.slug)"
        >
          {{ chooseLabel }}
        </button>
        <p class="mt-2 px-4 text-center text-[13px] leading-[18px] text-muted" data-testid="choose-hint">{{ t('course.choose_hint') }}</p>
      </template>
      <p
        v-else-if="allLessonsDone"
        class="mt-4 flex items-center gap-2 text-[15px] leading-5 font-semibold text-success-ink"
        data-testid="all-done"
      >
        <AppIcon name="check" :size="18" class="text-success" />
        {{ t('course.all_done') }}
      </p>

      <EmptyState v-if="detail.stages.length === 0" icon="book-open" :text="t('course.no_lessons')" data-testid="no-lessons" />

      <section v-if="shownStage" class="mt-7" :data-testid="`stage-${shownStage.number}`">
        <!-- "Module 2 of 20" above the name; a module without a name of its own is just "Module N" (the heading), no second number. -->
        <p v-if="stageTitle(shownStage) !== null" class="mb-1 text-[13px] leading-[18px] text-muted" data-testid="stage-of">
          {{ t('course.module_of', { number: shownStage.number, total: detail.stages.length }) }}
        </p>
        <h2 class="text-[22px] leading-7 font-semibold break-words">{{ stageHeading(shownStage) }}</h2>
        <div class="mt-2.5 flex items-center gap-3">
          <ProgressLine
            :value="progressPercent(shownStage.progress)"
            tone="success"
            :label="stageHeading(shownStage)"
            class="flex-1"
            data-testid="progress-bar"
          />
          <span class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="stage-progress">{{ progressText(shownStage.progress) }}</span>
        </div>

        <ol v-if="shownStage.lessons.length > 0" class="list mt-[18px]">
          <li
            v-for="lesson in shownStage.lessons"
            :key="lesson.id"
            class="border-t border-divider first:border-t-0"
            :class="lessonState(lesson) === 'current' ? 'bg-accent-soft shadow-[inset_0_0_0_1.5px_var(--app-primary)]' : undefined"
            :data-state="lessonState(lesson)"
            :data-testid="`lesson-${lesson.id}`"
          >
            <div
              class="flex items-center gap-3.5 px-[18px]"
              :class="lessonState(lesson) === 'current' ? 'pt-4' : 'min-h-[60px] py-3'"
            >
              <span
                class="w-5 shrink-0 font-mono text-[13px] leading-[18px]"
                :class="lessonState(lesson) === 'current' ? 'font-medium text-accent-ink' : 'text-muted'"
                :data-state="lessonState(lesson)"
                data-testid="lesson-status"
              >
                {{ lessonNumber(lesson) }}
              </span>
              <div class="min-w-0 flex-1">
                <p
                  class="break-words"
                  :class="{
                    'text-[17px] leading-[22px] font-semibold': lessonState(lesson) === 'current',
                    'text-[15px] leading-5 font-semibold': lessonState(lesson) === 'done' || lessonState(lesson) === 'started',
                    'text-[15px] leading-5 text-muted': lessonState(lesson) === 'new',
                  }"
                  data-testid="lesson-title"
                >
                  {{ lessonTitle(lesson) }}
                </p>
                <p class="mt-0.5 text-[13px] leading-[18px] text-muted">
                  <span data-testid="lesson-progress">{{ lessonWords(lesson) }}</span>
                  <!--
                    The practice of the lesson: a quiet button that closes the line of the progress ("learned N of M" - practise it), an icon with
                    a short caption "Practice" (an icon alone was not understood). It is part of the line of the progress and wraps under it when
                    there is no room: a second button beside "Learn" took 40 px from the title at 320 px. The button is 36 px (-my cancels the
                    height, the line stays 18 px); its touch area is the 44 px layer of `.btn-sm`.
                  -->
                  <button
                    v-if="practice.available"
                    type="button"
                    class="btn btn-plain btn-sm -top-0.5 -my-2.5 ml-1 h-9 gap-1 px-2 align-middle"
                    :disabled="opening || lesson.progress.total === 0"
                    :aria-label="practiceLessonLabel(shownStage, lesson)"
                    data-testid="practice-lesson-btn"
                    @click="openLessonPractice(lesson.id)"
                  >
                    <AppIcon name="puzzle-piece" :size="16" />
                    <span class="text-[13px] leading-[18px]">{{ t('practice.hub.title') }}</span>
                  </button>
                </p>
              </div>
              <span v-if="lessonState(lesson) === 'current'" class="chip chip-accent shrink-0" data-testid="lesson-here">{{ t('course.here') }}</span>
              <template v-else>
                <span
                  v-if="lessonState(lesson) === 'done'"
                  role="img"
                  class="shrink-0 text-success"
                  :aria-label="t('course.lesson_done')"
                  data-testid="lesson-done"
                >
                  <AppIcon name="check" :size="18" class="[&_path]:[stroke-width:2.4]" />
                </span>
                <button
                  type="button"
                  class="btn btn-plain btn-sm shrink-0 px-2"
                  :disabled="opening || lesson.progress.total === 0"
                  data-testid="learn-btn"
                  @click="learn(lesson.id)"
                >
                  {{ t('course.learn') }}
                </button>
              </template>
            </div>
            <!-- The one primary button of the screen in the learner's course: the lesson they are on. -->
            <div v-if="lessonState(lesson) === 'current'" class="px-[18px] pt-3 pb-[18px]">
              <button type="button" class="btn btn-primary btn-block" :disabled="opening" data-testid="continue-btn" @click="learn(lesson.id)">
                {{ t('course.learn_lesson') }}
              </button>
            </div>
          </li>
        </ol>

        <!-- Previous module, the readiness for the level (a course with words), next module: one line under the lessons. -->
        <nav class="mt-3.5 grid grid-cols-[auto_1fr_auto] items-center gap-2" :aria-label="t('course.module_of', { number: shownStage.number, total: detail.stages.length })">
          <div class="flex justify-start">
            <button
              v-if="prevStage"
              type="button"
              class="-ml-2 flex min-h-11 items-center gap-1 rounded-control px-2 text-[14px] leading-5 whitespace-nowrap text-accent active:opacity-60"
              :aria-label="t('course.module_prev', { module: stageHeading(prevStage) })"
              data-testid="stage-prev"
              @click="showStage(prevStage)"
            >
              <AppIcon name="chevron-left" :size="14" />{{ t('course.module', { number: prevStage.number }) }}
            </button>
          </div>
          <RouterLink
            v-if="offersReadiness"
            :to="`/courses/${encodeURIComponent(props.slug)}/readiness?source=course`"
            class="flex min-h-11 items-center justify-center px-1 text-center text-[14px] leading-5 text-accent active:opacity-60"
            data-testid="readiness-row"
          >
            {{ t('course.readiness') }}
          </RouterLink>
          <span v-else />
          <div class="flex justify-end">
            <button
              v-if="nextStage"
              type="button"
              class="-mr-2 flex min-h-11 items-center gap-1 rounded-control px-2 text-[14px] leading-5 whitespace-nowrap text-accent active:opacity-60"
              :aria-label="t('course.module_next', { module: stageHeading(nextStage) })"
              data-testid="stage-next"
              @click="showStage(nextStage)"
            >
              {{ t('course.module', { number: nextStage.number }) }}<AppIcon name="chevron-right" :size="14" />
            </button>
          </div>
        </nav>
      </section>

      <!-- Secondary entries of the course: its practice (a Pro chip while it is behind Pro) and the Karvon map. -->
      <div v-if="practice.available || gameOn" class="mt-5 flex flex-col gap-2">
        <button v-if="practice.available" type="button" class="btn btn-tinted btn-block" data-testid="practice-btn" @click="openPractice">
          {{ t('practice.hub.title') }}
          <span v-if="practice.pro_required" class="chip chip-accent" data-testid="practice-pro">{{ t('practice.hub.state.pro') }}</span>
        </button>
        <button
          v-if="gameOn"
          type="button"
          class="btn btn-tinted btn-block"
          data-testid="game-btn"
          @click="router.push({ name: 'game', query: { course: props.slug } })"
        >
          {{ t('game.enter') }}
        </button>
      </div>

      <section v-if="offersMock" class="card mt-5" data-testid="mock-exam">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('course.mock_exam') }}</h2>
        <p class="mt-1 text-[13px] leading-[18px] text-muted" data-testid="mock-exam-hint">{{ mockHint }}</p>
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
    </template>

    <PaywallSheet v-if="mockPaywall" reason="exam_simulator" @close="exam.dismissMockPaywall()" @review="reviewFromPaywall" />
    <PaywallSheet v-if="practicePaywall" reason="practice" @close="practicePaywall = false" @review="reviewFromPracticePaywall" />
  </div>
</template>
