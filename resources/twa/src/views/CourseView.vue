<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import CourseSkeleton from '@/components/course/CourseSkeleton.vue'
import AppChip from '@/components/ui/AppChip.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import { goalLabel, isPhraseCourse, progressPercent, progressText } from '@/composables/courseLabels'
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
 * One course (docs/14 §6.5, kit Muhrla screen 03 `docs/design/2026-11-27-muhrla-kit/01-screens/light/03-kurs.png`): the heading ("My course"
 * for the learner's own, else the name of the course) with "All courses ›" (the catalog, and "My decks" from it); the card of the course -
 * the level in an ink chip, the name, «Модуль 3 из 20 · 120 из 1000 слов», the line of the words learned in ink and the mascot reading -
 * with the way to the readiness for the level; "Start course" / "Switch to this course" in any course but the learner's own. Then
 * "Modules", the path of the kit: the picture of the topic of each module (`topicImage(course, module)`; a course without pictures - a plate
 * with the number) with the mark of its state - done (a muhr circle with a check, "N words · repeat"), current (an ink circle with the number
 * of the lesson; the card in a 2 px ink frame "lesson 3 of 10 · you are here →" that opens the current lesson), the modules after it (a
 * lock, the picture dimmed, "after module 3"). A module opens its lessons under it (a disclosure; the current module starts open, so its
 * lesson, the number of its new words and its "Practice" are seen without a tap): every lesson of it as a row that opens the lesson,
 * learned ones "repeat"; while `/me.practice.available` every lesson has a small "Practice" (the hub of that lesson, the paywall while it
 * is behind Pro). The lessons stay open to the learner in any module: the lock only says where the path goes. Under the
 * modules: "Practice" of the course, the Karvon map and the IELTS / SAT mock. The screen opens scrolled to the current module. "Back" is
 * the system button (-> the catalog). A course of phrases (`section = phrases`, docs/35 §3.2) is the same screen with «фраз» in its lines.
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

/** A course of phrases: the lines count phrases, not words. */
const phrases = computed(() => isPhraseCourse(detail.value))

/** Readiness for the level has something to count only in a course with words. */
const offersReadiness = computed(() => (detail.value?.words_count ?? 0) > 0)
const offersMock = computed((): boolean => detail.value !== null && detail.value.exam_tag !== null && MOCK_EXAM_TAGS.includes(detail.value.exam_tag))
const mockHint = computed((): string =>
  tn('course.mock_exam_hint', Math.min(MOCK_EXAM_WORDS, detail.value?.words_count ?? MOCK_EXAM_WORDS), { minutes: MOCK_EXAM_MINUTES }),
)

/** The name on the card: the course itself in the learner's own course (the heading says "My course"); in another one the heading is its
 *  name, so the card names its goal ("IELTS"), or nothing. */
const cardTitle = computed((): string => {
  const course = detail.value
  if (course === null) return ''
  return course.is_active ? course.title : (goalLabel(course.exam_tag) ?? '')
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
 * The quiet caption at the end of a row: "repeat" for a learned lesson, "5 new words · you are here" for the current one, "3 of 10 words"
 * for a begun one, "after lesson 3" for the one after the current lesson, else the size of the lesson ("10 words").
 */
function lessonHint(lesson: CourseLesson): string {
  const { done, total } = lesson.progress
  const state = lessonState(lesson)
  if (state === 'done') return t('course.repeat')
  if (state === 'current') return `${currentWords(lesson)} · ${t('course.here')}`
  if (state === 'started') return tn(phrases.value ? 'course.lesson_phrases' : 'course.lesson_words', total, { done, total })
  if (afterCurrent.value?.id === lesson.id) return t('course.after_lesson', { number: afterCurrent.value.number })
  return tn(phrases.value ? 'catalog.phrases' : 'catalog.words', total)
}

/** The line of the current lesson: "5 new words" before it is begun, "3 of 10 words" after. */
function currentWords(lesson: CourseLesson): string {
  const { done, total } = lesson.progress
  if (phrases.value) return done > 0 ? tn('course.lesson_phrases', total, { done, total }) : tn('course.new_phrases', total)
  return done > 0 ? tn('course.lesson_words', total, { done, total }) : tn('course.new_words', total)
}

/** The own name of a module; null for a module without one (`title: null`, or a blank one that an old row may hold). */
function stageTitle(stage: CourseStage): string | null {
  const title = stage.title?.trim()
  return title ? title : null
}

/** "1 of 5 lessons": the lessons of the module that are learned through. */
function stageCount(stage: CourseStage, key: 'course.module_lessons' | 'course.lessons_passed' = 'course.module_lessons'): string {
  const done = stage.lessons.filter((lesson) => lessonState(lesson) === 'done').length
  return tn(key, stage.lessons.length, { done, total: stage.lessons.length })
}

/** The state of a module on the path: done (its lessons with words are all learned), current (it has the current lesson), locked (a module
 *  after the current one of the learner's course: the path goes there later, its lessons still open), started / new otherwise. */
type ModuleState = 'done' | 'current' | 'locked' | 'started' | 'new'

const currentStage = computed((): number | null => (currentLessonId.value === null ? null : (nextLesson.value?.stage ?? null)))

function stageWords(stage: CourseStage): { done: number; total: number } {
  return stage.lessons.reduce((sum, lesson) => ({ done: sum.done + lesson.progress.done, total: sum.total + lesson.progress.total }), { done: 0, total: 0 })
}

function moduleState(stage: CourseStage): ModuleState {
  const withWords = stage.lessons.filter((lesson) => lesson.progress.total > 0)
  if (withWords.length > 0 && withWords.every((lesson) => lessonState(lesson) === 'done')) return 'done'
  if (stage.number === currentStage.value) return 'current'
  if (currentStage.value !== null && stage.number > currentStage.value) return 'locked'
  return stageWords(stage).done > 0 ? 'started' : 'new'
}

/** «1. Знакомство и люди»; a module without a name of its own is «Модуль 1». */
function moduleTitle(stage: CourseStage): string {
  const title = stageTitle(stage)
  return title === null ? t('course.module', { number: stage.number }) : `${stage.number}. ${title}`
}

/** The current lesson of the current module and its place in it: «урок 3 из 10». */
function currentLessonOf(stage: CourseStage): { lesson: CourseLesson; place: number } | null {
  const index = stage.lessons.findIndex((lesson) => lesson.id === currentLessonId.value)
  const lesson = stage.lessons[index]
  return lesson === undefined ? null : { lesson, place: index + 1 }
}

/**
 * The line under the name of a module: «50 слов · повторить» (done), «урок 3 из 10 · вы здесь» (current), «после модуля 3» (the module right
 * after the current one), «2 из 10 уроков» (begun), else its size «50 слов».
 */
function moduleMeta(stage: CourseStage): string {
  const state = moduleState(stage)
  const words = stageWords(stage)
  const size = tn(phrases.value ? 'catalog.phrases' : 'catalog.words', words.total)
  if (state === 'done') return `${size} · ${t('course.repeat')}`
  if (state === 'current') {
    const current = currentLessonOf(stage)
    return current === null ? t('course.here') : `${t('course.lesson_of', { number: current.place, total: stage.lessons.length })} · ${t('course.here')}`
  }
  if (state === 'locked' && currentStage.value !== null && stage.number === currentStage.value + 1) return t('course.after_module', { number: currentStage.value })
  if (state === 'started') return stageCount(stage)
  return size
}

/**
 * The modules the learner has toggled (a disclosure each). The current module starts open - its lesson, the number of its new words and
 * its "Practice" are seen without a tap - the others closed; a toggle flips that.
 */
const toggledModules = ref<Set<number>>(new Set())
function toggleModule(number: number): void {
  const next = new Set(toggledModules.value)
  if (next.has(number)) next.delete(number)
  else next.add(number)
  toggledModules.value = next
}
function isModuleOpen(stage: CourseStage): boolean {
  return (moduleState(stage) === 'current') !== toggledModules.value.has(stage.number)
}
/** `aria-controls` of a disclosure: only while its list is on the page. */
function lessonsId(stage: CourseStage): string | undefined {
  return isModuleOpen(stage) && stage.lessons.length > 0 ? `module-lessons-${stage.number}` : undefined
}

/** «Модуль 3 из 20 · 120 из 1000 слов» of the card: the module of the current lesson (the learner's course), else only the words. */
const courseMeta = computed((): string => {
  const course = detail.value
  if (course === null) return ''
  const words = tn(phrases.value ? 'course.lesson_phrases' : 'course.lesson_words', course.progress.total, { done: course.progress.done, total: course.progress.total })
  const stages = course.stages.length
  const module = currentStage.value ?? (allLessonsDone.value && course.is_active ? stages : null)
  return module !== null && stages > 0 ? `${t('course.module_of', { number: module, total: stages })} · ${words}` : words
})

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
 * The current module into view once the course is shown: the way through a long course. Not when the learner comes back to the screen (the history has a screen after this one): the router puts them
 * back where they were.
 */
const lessonList = ref<HTMLElement | null>(null)
const returning = typeof router.options.history.state?.forward === 'string'
let scrolled = returning

// Keyed by the read too: a course from the cache is 'ready' at once, and the status may already have been 'ready' before.
watch([status, () => catalog.detailSeq], async ([value]) => {
  if (value !== 'ready' || scrolled || currentLessonId.value === null) return
  scrolled = true
  await nextTick()
  const row = lessonList.value?.querySelector('[data-testid="module-current"]')
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

// A course read already (All courses, an earlier visit) is drawn in the first frame; `load()` still reads it once, as always.
catalog.showCached(props.slug)

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
      <!-- The heading of the kit (03): "My course" or the name of the course, "All courses ›" in the accent of text on the right. -->
      <header class="flex items-center justify-between gap-3 pt-2 pb-5" data-testid="title">
        <h1 class="min-w-0 [overflow-wrap:anywhere]" :class="detail.is_active ? 't-display-l' : 't-display-m'">
          {{ detail.is_active ? t('course.my_course') : detail.title }}
        </h1>
        <!-- `from`: the catalog's system "Back" then returns here. -->
        <RouterLink
          :to="{ name: 'courses', query: { from: slug } }"
          class="t-body-m -mr-1 inline-flex min-h-11 shrink-0 items-center gap-0.5 font-medium text-accent"
          data-testid="all-courses"
        >
          {{ t('course.all_courses') }}
          <AppIcon name="chevron-right" :size="18" />
        </RouterLink>
      </header>

      <!-- The card of the course: the level in ink, the name, the module and the words learned, the line in ink, the mascot reading. -->
      <section class="rounded-lg border border-hairline bg-surface p-5" data-testid="course-card">
        <div class="flex items-center gap-3">
          <div class="min-w-0 flex-1">
            <AppChip v-if="detail.level" tone="ink" data-testid="course-level">{{ detail.level }}</AppChip>
            <h2 v-if="cardTitle" class="t-title-m mt-2 [overflow-wrap:anywhere]" data-testid="course-line">{{ cardTitle }}</h2>
            <p class="t-label-s mt-1 text-muted" data-testid="progress-text">{{ courseMeta }}</p>
          </div>
          <MascotArt pose="reading" :height="84" class="max-[339px]:hidden" />
        </div>
        <ProgressLine
          :value="progressPercent(detail.progress)"
          tone="primary"
          :label="progressText(detail.progress)"
          class="mt-4 h-1.5"
          data-testid="progress-bar"
        />
        <RouterLink
          v-if="offersReadiness"
          :to="`/courses/${encodeURIComponent(props.slug)}/readiness?source=course`"
          class="t-label-s -mb-2.5 mt-1.5 flex min-h-11 w-fit items-center gap-0.5 font-semibold text-accent"
          data-testid="readiness-row"
        >
          {{ t('course.readiness') }}
          <AppIcon name="chevron-right" :size="14" />
        </RouterLink>
      </section>

      <!-- "My dictionary" (docs/40 §2.3, kit F2) from the learner's own course: a link only, no number and no request (`?from`: "Back" returns here). -->
      <RouterLink
        v-if="detail.is_active"
        :to="`/vocabulary?from=${encodeURIComponent(props.slug)}`"
        class="t-body-m mt-3 flex min-h-11 w-fit items-center gap-1.5 font-semibold text-accent"
        data-testid="vocabulary-link"
      >
        <AppIcon name="book-open" :size="18" />
        {{ t('course.vocabulary') }}
        <AppIcon name="chevron-right" :size="16" />
      </RouterLink>

      <p v-if="!detail.is_active && detail.description" class="t-body-m mt-3 text-muted" data-testid="description">
        {{ detail.description }}
      </p>

      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mt-4" data-testid="select-error" />

      <!-- Not the learner's course: making it theirs is the one primary button of the screen. -->
      <template v-if="!detail.is_active">
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block mt-5"
          :disabled="selecting !== null"
          data-testid="choose-btn"
          @click="choose(detail.slug)"
        >
          {{ chooseLabel }}
        </button>
        <p class="t-label-s mt-2 px-4 text-center text-muted" data-testid="choose-hint">{{ t(phrases ? 'course.choose_hint_phrases' : 'course.choose_hint') }}</p>
      </template>
      <p v-else-if="allLessonsDone" class="t-body-m-strong mt-4 flex items-center gap-2 text-success-ink" data-testid="all-done">
        <AppIcon name="check-circle" solid :size="20" class="text-success" />
        {{ t('course.all_done') }}
      </p>

      <div v-if="detail.stages.length === 0" class="flex flex-col items-center gap-3 py-10 text-center" data-testid="no-lessons">
        <MascotArt pose="reading" :height="96" />
        <p class="t-body-m text-muted">{{ t('course.no_lessons') }}</p>
      </div>

      <!-- "Modules": the path of the kit. A module opens its lessons under it; the current one is the card that opens its lesson. -->
      <div v-else ref="lessonList" class="mt-6" data-testid="lessons">
        <h2 class="t-display-m">{{ t('course.modules') }}</h2>
        <ol class="mt-3 flex flex-col gap-1">
          <li v-for="stage in detail.stages" :key="stage.number" :data-state="moduleState(stage)" :data-testid="`stage-${stage.number}`">
            <!-- The current module (the learner's course): a card in a 2 px ink frame; the press opens the current lesson. -->
            <div
              v-if="moduleState(stage) === 'current'"
              class="rounded-lg border-2 border-strong bg-surface"
              data-testid="module-current"
            >
              <button
                type="button"
                class="flex w-full items-center gap-3 p-3 text-left disabled:opacity-50"
                :disabled="opening || currentLessonOf(stage) === null"
                data-testid="continue-btn"
                @click="currentLessonId !== null && learn(currentLessonId)"
              >
                <span class="relative shrink-0" aria-hidden="true">
                  <TopicArt :course="detail.slug" :stage="stage.number" :size="64">
                    <template #fallback><span class="icon-tile t-title-m size-16 rounded-md">{{ stage.number }}</span></template>
                  </TopicArt>
                  <span class="t-caption absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full bg-inverse font-semibold text-on-inverse ring-2 ring-surface" data-testid="module-badge">
                    {{ currentLessonOf(stage)?.place ?? stage.number }}
                  </span>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="t-body-l block [overflow-wrap:anywhere]" data-testid="module-title">{{ moduleTitle(stage) }}</span>
                  <span class="t-body-m block text-muted" data-testid="module-meta">{{ moduleMeta(stage) }}</span>
                  <span class="t-body-m-strong mt-1 block" data-testid="continue-label">
                    {{ (currentLessonOf(stage)?.lesson.progress.done ?? 0) > 0 ? t('course.continue_lesson') : t('course.start_lesson') }}
                  </span>
                </span>
                <AppIcon name="arrow-right" :size="22" class="shrink-0" />
              </button>
              <button
                type="button"
                class="t-label-s flex min-h-11 w-full items-center gap-1 border-t border-divider px-3 text-left text-muted"
                :aria-expanded="isModuleOpen(stage) ? 'true' : 'false'"
                :aria-controls="lessonsId(stage)"
                data-testid="module-toggle"
                @click="toggleModule(stage.number)"
              >
                <span class="min-w-0 flex-1">{{ stageCount(stage, 'course.lessons_passed') }}</span>
                <AppIcon name="chevron-down" :size="16" class="shrink-0 transition-transform" :class="{ 'rotate-180': isModuleOpen(stage) }" />
              </button>
            </div>

            <!-- Any other module: one row that opens its lessons. -->
            <button
              v-else
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left active:bg-fill"
              :aria-expanded="isModuleOpen(stage) ? 'true' : 'false'"
              :aria-controls="lessonsId(stage)"
              data-testid="module-toggle"
              @click="toggleModule(stage.number)"
            >
              <span class="relative shrink-0" aria-hidden="true">
                <TopicArt
                  :course="detail.slug"
                  :stage="stage.number"
                  :size="64"
                  :class="{ 'opacity-45 grayscale': moduleState(stage) === 'locked' }"
                >
                  <template #fallback>
                    <span class="icon-tile t-title-m size-16 rounded-md" :class="{ 'text-muted': moduleState(stage) === 'locked' }">{{ stage.number }}</span>
                  </template>
                </TopicArt>
                <span
                  v-if="moduleState(stage) === 'done'"
                  class="absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full bg-muhr text-on-muhr ring-2 ring-page"
                  data-testid="module-badge"
                ><AppIcon name="check" :size="14" class="[&_path]:[stroke-width:3]" /></span>
                <span
                  v-else-if="moduleState(stage) === 'locked'"
                  class="absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full bg-sunken text-muted ring-2 ring-page"
                  data-testid="module-badge"
                ><AppIcon name="lock-closed" :size="13" /></span>
              </span>
              <span class="min-w-0 flex-1">
                <span class="t-body-l block [overflow-wrap:anywhere]" :class="{ 'text-muted': moduleState(stage) === 'locked' }" data-testid="module-title">{{ moduleTitle(stage) }}</span>
                <span class="t-body-m block" :class="moduleState(stage) === 'done' ? 'text-accent' : 'text-muted'" data-testid="module-meta">{{ moduleMeta(stage) }}</span>
              </span>
              <AppIcon name="chevron-down" :size="18" class="shrink-0 text-muted transition-transform" :class="{ 'rotate-180': isModuleOpen(stage) }" />
            </button>

            <!-- The lessons of an open module: one list of the kit; a whole row opens its lesson, "Practice" stands above that layer. -->
            <ol
              v-if="isModuleOpen(stage) && stage.lessons.length > 0"
              :id="`module-lessons-${stage.number}`"
              class="list mt-2 mb-3"
              data-testid="module-lessons"
            >
              <li
                v-for="lesson in stage.lessons"
                :key="lesson.id"
                class="relative flex min-h-[60px] items-center gap-3 border-t border-divider px-3 first:border-t-0"
                :data-state="lessonState(lesson)"
                :data-testid="`lesson-${lesson.id}`"
              >
                <!-- The status: a check on green (learned), the muhr ring with a dot (current), a ring (begun), a grey ring (not begun). -->
                <span
                  v-if="lessonState(lesson) === 'done'"
                  role="img"
                  class="flex size-7 shrink-0 items-center justify-center rounded-full bg-success text-on-primary"
                  :aria-label="t('course.lesson_done')"
                  data-state="done"
                  data-testid="lesson-status"
                >
                  <AppIcon name="check" :size="16" data-testid="lesson-done" />
                </span>
                <span
                  v-else
                  class="flex size-7 shrink-0 items-center justify-center rounded-full border-[1.5px]"
                  :class="{
                    'border-muhr': lessonState(lesson) === 'current',
                    'border-strong': lessonState(lesson) === 'started',
                    'border-hairline': lessonState(lesson) === 'new',
                  }"
                  :data-state="lessonState(lesson)"
                  aria-hidden="true"
                  data-testid="lesson-status"
                >
                  <span v-if="lessonState(lesson) === 'current'" class="size-2.5 rounded-full bg-muhr" />
                </span>

                <div class="min-w-0 flex-1 py-2.5">
                  <button
                    type="button"
                    class="flex w-full items-baseline gap-3 text-left outline-none after:absolute after:inset-0 after:content-[''] focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-accent enabled:active:opacity-60 disabled:opacity-50"
                    :disabled="opening || lesson.progress.total === 0"
                    data-testid="learn-btn"
                    @click="learn(lesson.id)"
                  >
                    <span class="t-body-m-strong min-w-0 flex-1 break-words" data-testid="lesson-title">{{ lessonTitle(lesson) }}</span>
                    <span class="t-label-s shrink-0 text-muted" data-testid="lesson-hint">{{ lessonHint(lesson) }}</span>
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
                      <span class="t-label-s">{{ t('practice.hub.title') }}</span>
                    </button>
                  </div>
                </div>
              </li>
            </ol>
          </li>
        </ol>
      </div>

      <!-- Secondary entries of the course: its practice (a Pro chip while it is behind Pro) and the Karvon map, as a list of the kit. -->
      <div v-if="practice.available || gameOn" class="list mt-6 divide-y divide-divider">
        <button
          v-if="practice.available"
          type="button"
          class="flex min-h-[60px] w-full items-center gap-3 px-3 py-2.5 text-left active:bg-fill"
          data-testid="practice-btn"
          @click="openPractice"
        >
          <span class="icon-tile"><AppIcon name="puzzle-piece" :size="20" /></span>
          <span class="t-body-m-strong min-w-0 flex-1">{{ t('practice.hub.title') }}</span>
          <span v-if="practice.pro_required" class="chip chip-accent" data-testid="practice-pro">{{ t('practice.hub.state.pro') }}</span>
          <AppIcon name="chevron-right" :size="20" class="shrink-0 text-muted" />
        </button>
        <button
          v-if="gameOn"
          type="button"
          class="flex min-h-[60px] w-full items-center gap-3 px-3 py-2.5 text-left active:bg-fill"
          data-testid="game-btn"
          @click="router.push({ name: 'game', query: { course: props.slug } })"
        >
          <span class="icon-tile"><AppIcon name="map" :size="20" /></span>
          <span class="t-body-m-strong min-w-0 flex-1">{{ t('game.enter') }}</span>
          <AppIcon name="chevron-right" :size="20" class="shrink-0 text-muted" />
        </button>
      </div>

      <section v-if="offersMock" class="card mt-6" data-testid="mock-exam">
        <h2 class="t-title-m">{{ t('course.mock_exam') }}</h2>
        <p class="t-label-s mt-1 text-muted" data-testid="mock-exam-hint">{{ mockHint }}</p>
        <InlineAlert v-if="mockError" tone="danger" :message="mockError" class="mt-3" data-testid="mock-exam-error" />
        <button
          type="button"
          class="btn btn-secondary btn-block mt-3"
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
