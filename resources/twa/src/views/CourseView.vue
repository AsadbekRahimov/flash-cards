<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import CourseSkeleton from '@/components/course/CourseSkeleton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { goalLabel, progressPercent, progressText } from '@/composables/courseLabels'
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
 * One course (docs/14 §6.5, docs/design/2026-11-25-redesign/Path.html): the brand row and the heading ("My course" for the learner's
 * own, else the name of the course) with "All courses" (the catalog, and "My decks" from it); the tinted card of the course - level,
 * the words learned "120 / 1000 words", a line with the per cent - and the readiness for the level; "Start course" / "Switch to this
 * course" in any course but the learner's own. Then every lesson of the course in one flat list, a thin heading of its module between
 * them ("Module 2 · Food", "1 of 5 lessons"): a learned lesson is a row with a green tick and "repeat", the current one (the first
 * unfinished lesson of the learner's course) the tinted card "you are here" with the one primary button "Start lesson" / "Continue",
 * the one right after it "after lesson N"; a whole row opens its lesson. While `/me.practice.available` every lesson has a small
 * "Practice" (the hub of that lesson, the paywall while it is behind Pro). Under the lessons: "Practice" of the course, the Karvon map
 * and the IELTS / SAT mock. The screen opens scrolled to the current lesson (a course has many lessons). "Back" is the system button
 * (-> the catalog).
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

/**
 * The first line of the card of the course: "A1–A2 · Basic words" in the learner's own course (the heading says "My course"); in
 * another one the heading is its name, so the line is its level and goal ("B2 · IELTS"). Empty: no line.
 */
const courseLine = computed((): string => {
  const course = detail.value
  if (course === null) return ''
  const parts = [course.level, course.is_active ? course.title : goalLabel(course.exam_tag)]
  return parts.filter((part): part is string => part !== null && part !== '').join(' · ')
})

/** The lesson of the one primary button: the first unfinished lesson of the course the learner is on. */
const currentLessonId = computed((): number | null => (detail.value?.is_active === true ? (nextLesson.value?.id ?? null) : null))

/** The lesson right after the current one, in the order of the list (across a module too): it says "after lesson N". */
const afterCurrent = computed((): { id: number; number: number } | null => {
  const lessons = (detail.value?.stages ?? []).flatMap((stage) => stage.lessons)
  const index = lessons.findIndex((lesson) => lesson.id === currentLessonId.value)
  const after = index === -1 ? undefined : lessons[index + 1]
  const current = lessons[index]
  return after !== undefined && current !== undefined ? { id: after.id, number: current.number } : null
})

function lessonState(lesson: CourseLesson): LessonState {
  const { done, total } = lesson.progress
  if (total > 0 && done >= total) return 'done'
  if (lesson.id === currentLessonId.value) return 'current'
  return done > 0 ? 'started' : 'new'
}

/**
 * The quiet caption at the end of a row: "repeat" for a learned lesson, "3 of 10 words" for a begun one, "after lesson 3" for the one
 * after the current lesson, else the size of the lesson ("10 words").
 */
function lessonHint(lesson: CourseLesson): string {
  const { done, total } = lesson.progress
  const state = lessonState(lesson)
  if (state === 'done') return t('course.repeat')
  if (state === 'started') return tn('course.lesson_words', total, { done, total })
  if (afterCurrent.value?.id === lesson.id) return t('course.after_lesson', { number: afterCurrent.value.number })
  return tn('catalog.words', total)
}

/** The line of the current lesson: "5 new words" before it is begun, "3 of 10 words" after. */
function currentWords(lesson: CourseLesson): string {
  const { done, total } = lesson.progress
  return done > 0 ? tn('course.lesson_words', total, { done, total }) : tn('course.new_words', total)
}

/** The own name of a module; null for a module without one (`title: null`, or a blank one that an old row may hold). */
function stageTitle(stage: CourseStage): string | null {
  const title = stage.title?.trim()
  return title ? title : null
}

/** "Module 2 · Food"; a module without a name of its own is just "Module 2" - the number, in the language of the reader. */
function stageHeading(stage: CourseStage): string {
  const number = t('course.module', { number: stage.number })
  const title = stageTitle(stage)
  return title === null ? number : `${number} · ${title}`
}

/** "1 of 5 lessons": the lessons of the module that are learned through. */
function stageCount(stage: CourseStage): string {
  const done = stage.lessons.filter((lesson) => lessonState(lesson) === 'done').length
  return tn('course.module_lessons', stage.lessons.length, { done, total: stage.lessons.length })
}

/** "Lesson 3 · Drinks"; a lesson without a title is "Lesson 3" (numbers start anew in every module, under its heading). */
function lessonTitle(lesson: CourseLesson): string {
  const number = t('course.lesson', { number: lesson.number })
  const title = lesson.title?.trim()
  return title ? `${number} · ${title}` : number
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

/**
 * The current lesson into view once the course is shown: it replaces the list of modules of the earlier screen as the way through
 * a long course. Not when the learner comes back to the screen (the history has a screen after this one): the router puts them
 * back where they were.
 */
const lessonList = ref<HTMLElement | null>(null)
const returning = typeof router.options.history.state?.forward === 'string'
let scrolled = returning

watch(status, async (value) => {
  if (value !== 'ready' || scrolled || currentLessonId.value === null) return
  scrolled = true
  await nextTick()
  const row = lessonList.value?.querySelector(`[data-testid="lesson-${currentLessonId.value}"]`)
  row?.scrollIntoView?.({ block: 'center' })
})

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

// A lesson is a row of a long list: a refusal to open the session is a toast, not an alert at the top of the screen.
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
    <CourseSkeleton v-if="status === 'idle' || status === 'loading'" />

    <ErrorState v-else-if="status === 'error' || detail === null" :message="errorMessage" @retry="load" />

    <template v-else>
      <PageHeader brand large :title="detail.is_active ? t('course.my_course') : detail.title" data-testid="title">
        <template #trailing>
          <!-- `from`: the catalog's system "Back" then returns here. -->
          <RouterLink :to="{ name: 'courses', query: { from: slug } }" class="btn btn-plain btn-sm -mr-2 px-2" data-testid="all-courses">
            {{ t('course.all_courses') }}
          </RouterLink>
        </template>
      </PageHeader>

      <!-- The card of the course: what it is, the words learned out of all and the line of the per cent (numbers of the server). -->
      <section class="rounded-hero bg-highlight px-4 pt-[18px] pb-4" data-testid="course-card">
        <p v-if="courseLine" class="text-base leading-[22px] font-bold break-words" data-testid="course-line">{{ courseLine }}</p>
        <p class="text-lg leading-6" :class="{ 'mt-2': courseLine }" data-testid="progress-text">
          <span class="font-extrabold">{{ detail.progress.done }}</span>
          {{ tn('course.words_of', detail.progress.total, { total: detail.progress.total }) }}
        </p>
        <div class="mt-2 flex items-center gap-2.5">
          <ProgressLine
            :value="progressPercent(detail.progress)"
            tone="primary"
            :label="progressText(detail.progress)"
            class="h-2.5 flex-1 bg-accent-line"
            data-testid="progress-bar"
          />
          <span class="shrink-0 text-sm leading-5 font-bold" data-testid="progress-percent">{{ progressPercent(detail.progress) }}%</span>
        </div>
        <RouterLink
          v-if="offersReadiness"
          :to="`/courses/${encodeURIComponent(props.slug)}/readiness?source=course`"
          class="-mb-2 mt-1 flex min-h-11 w-fit items-center gap-1 text-sm leading-5 font-semibold text-accent active:opacity-60"
          data-testid="readiness-row"
        >
          {{ t('course.readiness') }}
          <AppIcon name="chevron-right" :size="14" />
        </RouterLink>
      </section>

      <p v-if="!detail.is_active && detail.description" class="mt-3 text-[15px] leading-5 text-muted" data-testid="description">
        {{ detail.description }}
      </p>

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-4" data-testid="select-error" />

      <!-- Not the learner's course: making it theirs is the one primary button. In their course it is the current lesson below. -->
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

      <!--
        Every lesson in one list (the layout), with a thin heading of its module between them: a course of many modules stays easy to
        find one's way in. A row is one button (its layer covers the row); "Practice" of a row stands above that layer.
      -->
      <div v-else ref="lessonList" class="mt-[18px]" data-testid="lessons">
        <h2 class="text-[22px] leading-7 font-extrabold tracking-[-0.3px]">{{ t('course.lessons') }}</h2>
        <section v-for="stage in detail.stages" :key="stage.number" class="mt-3.5" :data-testid="`stage-${stage.number}`">
          <div class="flex items-baseline justify-between gap-3 px-1 text-[13px] leading-[18px] text-muted">
            <h3 class="min-w-0 font-semibold break-words" data-testid="stage-title">{{ stageHeading(stage) }}</h3>
            <span v-if="stage.lessons.length > 0" class="shrink-0" data-testid="stage-count">{{ stageCount(stage) }}</span>
          </div>

          <ol v-if="stage.lessons.length > 0" class="mt-2 flex flex-col gap-2">
            <li
              v-for="lesson in stage.lessons"
              :key="lesson.id"
              class="relative flex"
              :class="
                lessonState(lesson) === 'current'
                  ? 'items-start gap-3.5 rounded-hero bg-highlight py-3.5 pr-3.5 pl-[17px]'
                  : 'min-h-[54px] items-center gap-3.5 rounded-option border border-hairline bg-surface px-4'
              "
              :data-state="lessonState(lesson)"
              :data-testid="`lesson-${lesson.id}`"
            >
              <!-- The status: a green tick (learned), a ring in the accent (begun, current), a grey ring (not begun). -->
              <span
                v-if="lessonState(lesson) === 'done'"
                role="img"
                class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-success text-surface"
                :aria-label="t('course.lesson_done')"
                data-state="done"
                data-testid="lesson-status"
              >
                <AppIcon name="check" :size="16" class="[&_path]:[stroke-width:2.6]" data-testid="lesson-done" />
              </span>
              <span
                v-else
                class="flex size-[26px] shrink-0 items-center justify-center rounded-full border-2"
                :class="lessonState(lesson) === 'new' ? 'border-muted' : 'border-accent'"
                :data-state="lessonState(lesson)"
                aria-hidden="true"
                data-testid="lesson-status"
              >
                <span v-if="lessonState(lesson) === 'current'" class="size-2.5 rounded-full bg-accent" />
              </span>
              <span
                class="w-px shrink-0"
                :class="lessonState(lesson) === 'current' ? 'self-stretch bg-accent-line' : 'h-[26px] bg-hairline'"
                aria-hidden="true"
              />

              <!-- The current lesson: "you are here" and the one primary button of the screen. -->
              <div v-if="lessonState(lesson) === 'current'" class="flex min-w-0 flex-1 flex-col gap-1">
                <p class="text-[17px] leading-[22px] font-extrabold break-words" data-testid="lesson-title">{{ lessonTitle(lesson) }}</p>
                <p class="text-[13px] leading-[18px] text-muted">
                  <span data-testid="lesson-hint">{{ currentWords(lesson) }}</span>
                  · <span data-testid="lesson-here">{{ t('course.here') }}</span>
                </p>
                <button
                  type="button"
                  class="btn btn-primary btn-block mt-1 min-h-[46px] gap-2.5"
                  :disabled="opening"
                  data-testid="continue-btn"
                  @click="learn(lesson.id)"
                >
                  {{ lesson.progress.done > 0 ? t('course.continue_lesson') : t('course.start_lesson') }}
                  <AppIcon name="arrow-right" :size="20" />
                </button>
                <div v-if="practice.available" class="-ml-2">
                  <button
                    type="button"
                    class="btn btn-plain btn-sm tap-target h-9 gap-1 px-2"
                    :disabled="opening"
                    :aria-label="practiceLessonLabel(stage, lesson)"
                    data-testid="practice-lesson-btn"
                    @click="openLessonPractice(lesson.id)"
                  >
                    <AppIcon name="puzzle-piece" :size="16" />
                    <span class="text-[13px] leading-[18px]">{{ t('practice.hub.title') }}</span>
                  </button>
                </div>
              </div>

              <!--
                Any other lesson: the whole row is the button that opens it (its ::after layer covers the row), with the title and the
                quiet caption; "Practice" is a line of its own under the title, above that layer.
              -->
              <div v-else class="min-w-0 flex-1 py-3">
                <button
                  type="button"
                  class="flex w-full items-baseline gap-3 text-left outline-none after:absolute after:inset-0 after:rounded-option after:content-[''] focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-accent enabled:active:opacity-60 disabled:opacity-50"
                  :disabled="opening || lesson.progress.total === 0"
                  data-testid="learn-btn"
                  @click="learn(lesson.id)"
                >
                  <span class="min-w-0 flex-1 text-base leading-[22px] font-bold break-words" data-testid="lesson-title">{{ lessonTitle(lesson) }}</span>
                  <span class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="lesson-hint">{{ lessonHint(lesson) }}</span>
                </button>
                <div v-if="practice.available" class="relative z-10 -my-1 -ml-2">
                  <button
                    type="button"
                    class="btn btn-plain btn-sm tap-target h-9 gap-1 px-2"
                    :disabled="opening || lesson.progress.total === 0"
                    :aria-label="practiceLessonLabel(stage, lesson)"
                    data-testid="practice-lesson-btn"
                    @click="openLessonPractice(lesson.id)"
                  >
                    <AppIcon name="puzzle-piece" :size="16" />
                    <span class="text-[13px] leading-[18px]">{{ t('practice.hub.title') }}</span>
                  </button>
                </div>
              </div>
            </li>
          </ol>
        </section>
      </div>

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
        <h2 class="text-[17px] leading-[22px] font-bold">{{ t('course.mock_exam') }}</h2>
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
