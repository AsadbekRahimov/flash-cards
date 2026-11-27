<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import TopicArt from '@/components/ui/TopicArt.vue'
import { api } from '@/api'
import { catalogTabs, courseChips, isPhraseCourse, progressPercent, progressText } from '@/composables/courseLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useCourseSelect } from '@/composables/useCourseSelect'
import { useAuthStore } from '@/stores/auth'
import { useCoursesStore } from '@/stores/courses'
import { errorMessage as describeError } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { Course, CourseDetail, CourseStage } from '@/api/types'

/**
 * The catalog of courses (docs/14 §6.5, kit Muhrla screen 12 `docs/design/2026-11-27-muhrla-kit/01-screens/light/12-vse-kursy.png`): the
 * heading "Courses" on the centre axis with the line of what a course gives, the switch "Courses | My decks", then the courses as a grid of
 * tiles, three in a row (the level in Unbounded and a caption: «идёт · 12%» for the learner's course, else its size); the picked tile is
 * the ink one. Under the grid - the picked course: its card (name, chips, description, the words learned and the one primary button: "Open
 * the lessons" of the learner's course, else "Start course" / "Switch to this course", which leads to Home), and its modules as a grid of
 * two columns with the picture of each topic (`topicImage(course, module)`; a course without pictures - a plate with the number): done -
 * "done", the current one in a 2 px ink frame with «урок 3 из 10», the ones after it dimmed. A module opens the course screen. The modules
 * are `GET /courses/{slug}` of the picked course (the list has no modules), read once per course while the screen is open.
 *
 * The courses of phrases (`section = phrases`, docs/35 §3.2) are tiles at the end of the grid named «Фразы» (their level under it); the
 * card of a picked one says what they are. The "Course" tab opens the learner's own course; this list is reached from it ("All courses"),
 * and is the tab itself while there is no course yet. A root of the tab: no system "Back" button - except when it was opened by "All
 * courses" of a course (`?from=<slug>`): then the system "Back" returns to that course.
 */
const auth = useAuthStore()
const catalog = useCoursesStore()
const { t, tn } = useI18n()
const { chooseLabel, choose } = useCourseSelect()

const { courses, listStatus: status, selecting, errorMessage } = storeToRefs(catalog)

const tabs = computed(catalogTabs)

/** The usual courses first, then the courses of phrases (the order of the server inside each). */
const ordered = computed((): Course[] => [
  ...courses.value.filter((course) => !isPhraseCourse(course)),
  ...courses.value.filter((course) => isPhraseCourse(course)),
])

/** The tile the learner picked; at first the learner's course, else the first one. */
const picked = ref<string | null>(null)
const selected = computed((): Course | null => {
  const list = ordered.value
  return list.find((course) => course.slug === picked.value) ?? list.find((course) => course.is_active) ?? list[0] ?? null
})

/** The big text of a tile: «Фразы» for a course of phrases, else the level, else the name of the course. */
function tileLabel(course: Course): string {
  if (isPhraseCourse(course)) return t('catalog.phrases_title')
  return course.level !== null && course.level !== '' ? course.level : course.title
}

/** The caption of a tile: «идёт · 12%» of the learner's course, the level of a course of phrases, else its size («1000 слов»). */
function tileCaption(course: Course): string {
  if (course.is_active) return t('catalog.tile_active', { percent: progressPercent(course.progress) })
  if (isPhraseCourse(course) && course.level !== null && course.level !== '') return course.level
  return tn(isPhraseCourse(course) ? 'catalog.phrases' : 'catalog.words', course.words_count)
}

/**
 * The size of the label of a tile, so the three tiles of a row keep one height: a level («A1–A2», «B1») - 22 px; a short word («Фразы»: the
 * letters of Unbounded are wide) - 18 px; a name of a course - 15 px on two lines at most.
 */
function labelSize(course: Course): 'level' | 'word' | 'name' {
  const label = tileLabel(course)
  if (label.length > 8) return 'name'
  return /\d/.test(label) || label.length <= 3 ? 'level' : 'word'
}
const LABEL_CLASSES = { level: 'text-[22px] leading-[26px]', word: 'text-[18px] leading-[26px]', name: 'line-clamp-2 text-[15px] leading-5 [overflow-wrap:anywhere]' }

// ---- the modules of the picked course: `GET /courses/{slug}`, once per course while the screen is open ----

type ModulesState = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; detail: CourseDetail }
const modules = ref<Record<string, ModulesState>>({})

async function loadModules(slug: string): Promise<void> {
  modules.value = { ...modules.value, [slug]: { status: 'loading' } }
  try {
    const detail = await api.courses.get(slug)
    modules.value = { ...modules.value, [slug]: { status: 'ready', detail: { ...detail, stages: detail.stages ?? [] } } }
  } catch (err) {
    modules.value = { ...modules.value, [slug]: { status: 'error', message: describeError(err, 'catalog') } }
  }
}

watch(selected, (course) => {
  if (course !== null && modules.value[course.slug] === undefined) void loadModules(course.slug)
}, { immediate: true })

const selectedModules = computed((): ModulesState | null => (selected.value === null ? null : (modules.value[selected.value.slug] ?? null)))
const selectedDetail = computed((): CourseDetail | null => {
  const state = selectedModules.value
  return state !== null && state.status === 'ready' ? state.detail : null
})

/** The first lesson of the picked course that still has words, with its module: only the learner's course has a "current" module. */
const current = computed((): { stage: number; place: number; total: number } | null => {
  const detail = selectedDetail.value
  if (detail === null || !detail.is_active) return null
  for (const stage of detail.stages) {
    const index = stage.lessons.findIndex((lesson) => lesson.progress.done < lesson.progress.total)
    if (index >= 0) return { stage: stage.number, place: index + 1, total: stage.lessons.length }
  }
  return null
})

type ModuleState = 'done' | 'current' | 'locked' | 'open'

function moduleState(stage: CourseStage): ModuleState {
  const withWords = stage.lessons.filter((lesson) => lesson.progress.total > 0)
  if (withWords.length > 0 && withWords.every((lesson) => lesson.progress.done >= lesson.progress.total)) return 'done'
  if (current.value !== null && stage.number === current.value.stage) return 'current'
  if (current.value !== null && stage.number > current.value.stage) return 'locked'
  return 'open'
}

/** «1. Знакомство и люди»; a module without a name of its own is «Модуль 1». */
function moduleTitle(stage: CourseStage): string {
  const title = stage.title?.trim()
  return title ? `${stage.number}. ${title}` : t('course.module', { number: stage.number })
}

function moduleMeta(stage: CourseStage): string {
  const state = moduleState(stage)
  if (state === 'done') return t('course.lesson_done')
  if (state === 'current' && current.value !== null) return t('course.lesson_of', { number: current.value.place, total: current.value.total })
  const words = stage.lessons.reduce((sum, lesson) => sum + lesson.progress.total, 0)
  return tn(isPhraseCourse(selectedDetail.value) ? 'catalog.phrases' : 'catalog.words', words)
}

/** «3 / 20» next to the heading of the modules: the module of the learner (or the modules done) of all. */
const modulesCount = computed((): string => {
  const detail = selectedDetail.value
  if (detail === null || detail.stages.length === 0) return ''
  const at = current.value?.stage ?? detail.stages.filter((stage) => moduleState(stage) === 'done').length
  return `${at} / ${detail.stages.length}`
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
    <!-- The heading of the kit (12): on the centre axis, the line under it. -->
    <header class="pt-2 pb-5 text-center">
      <h1 class="t-display-l">{{ t('catalog.title') }}</h1>
      <p class="t-body-m mt-1 text-muted" data-testid="hint">{{ t('catalog.hint') }}</p>
    </header>
    <SegmentedNav :items="tabs" :ariaLabel="t('catalog.title')" />

    <div v-if="status === 'idle' || status === 'loading'" role="status" aria-busy="true" class="mt-5" data-testid="spinner">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <div aria-hidden="true">
        <div class="grid grid-cols-3 gap-3">
          <div v-for="tile in 6" :key="tile" class="skeleton h-16 rounded-lg" />
        </div>
        <div class="skeleton mt-6 h-6 w-1/2 rounded-md" />
        <div class="mt-3 grid grid-cols-2 gap-3">
          <div v-for="card in 4" :key="card" class="skeleton h-40 rounded-lg" />
        </div>
      </div>
    </div>

    <ErrorState v-else-if="status === 'error'" :message="errorMessage" @retry="load" />

    <div v-else-if="courses.length === 0" class="flex flex-col items-center gap-3 py-10 text-center" data-testid="empty">
      <MascotArt pose="reading" :height="96" />
      <p class="t-body-m text-muted">{{ t('catalog.empty') }}</p>
    </div>

    <div v-else class="mt-5">
      <InlineAlert v-if="errorMessage" tone="danger" :message="errorMessage" class="mb-4" data-testid="select-error" />

      <!-- The courses: a grid of tiles, three in a row; the picked one is ink. -->
      <div class="grid grid-cols-3 gap-3" role="group" :aria-label="t('catalog.title')" data-testid="course-grid">
        <button
          v-for="course in ordered"
          :key="course.slug"
          type="button"
          class="pressable flex min-h-16 min-w-0 flex-col items-center justify-center rounded-lg border px-2 py-2.5 text-center"
          :class="course.slug === selected?.slug ? 'border-inverse bg-inverse text-on-inverse' : 'border-hairline bg-surface text-body'"
          :aria-pressed="course.slug === selected?.slug ? 'true' : 'false'"
          :data-active="course.is_active ? 'true' : undefined"
          :data-testid="`course-${course.slug}`"
          @click="picked = course.slug"
        >
          <span
            class="max-w-full font-display font-semibold tracking-[-0.02em]"
            :class="LABEL_CLASSES[labelSize(course)]"
            data-testid="tile-label"
          >{{ tileLabel(course) }}</span>
          <span class="t-label-s mt-0.5 max-w-full truncate" :class="course.slug === selected?.slug ? 'opacity-80' : 'text-muted'" data-testid="tile-caption">
            {{ tileCaption(course) }}
          </span>
        </button>
      </div>

      <!-- The picked course: what it is, the words learned and the one primary button of the screen. -->
      <article v-if="selected" class="card mt-6" :data-testid="`selected-${selected.slug}`">
        <span v-if="selected.is_active" class="chip chip-success mb-2.5" data-testid="active-badge">{{ t('catalog.active') }}</span>
        <RouterLink
          :to="{ name: 'course', params: { slug: selected.slug } }"
          class="flex items-start gap-2 active:opacity-60"
          data-testid="course-link"
        >
          <div class="min-w-0 flex-1">
            <h2 class="t-title-m break-words" data-testid="course-title">{{ selected.title }}</h2>
            <div class="mt-2 flex flex-wrap gap-1.5" data-testid="meta">
              <span v-for="chip in courseChips(selected)" :key="chip.key" class="chip" :class="`chip-${chip.tone}`">{{ chip.label }}</span>
            </div>
            <p v-if="selected.description" class="t-body-m mt-2 line-clamp-2 text-muted" data-testid="description">{{ selected.description }}</p>
          </div>
          <AppIcon name="chevron-right" :size="20" class="mt-0.5 shrink-0 text-muted" />
        </RouterLink>
        <p v-if="isPhraseCourse(selected)" class="t-label-s mt-2 text-muted" data-testid="phrases-hint">{{ t('catalog.phrases_hint') }}</p>

        <div class="mt-3.5 flex flex-col-reverse gap-1.5">
          <ProgressLine :value="progressPercent(selected.progress)" :label="progressText(selected.progress)" data-testid="progress-bar" />
          <span class="t-label-s text-muted" data-testid="progress-text">{{ progressText(selected.progress) }}</span>
        </div>

        <div class="mt-4">
          <RouterLink
            v-if="selected.is_active"
            :to="{ name: 'course', params: { slug: selected.slug } }"
            class="btn btn-primary btn-block"
            data-testid="open-lessons"
          >
            {{ t('catalog.open_lessons') }}
            <AppIcon name="arrow-right" :size="20" />
          </RouterLink>
          <button
            v-else
            type="button"
            class="btn btn-primary btn-block"
            :disabled="selecting !== null"
            data-testid="choose-btn"
            @click="choose(selected.slug)"
          >
            {{ chooseLabel }}
          </button>
        </div>
      </article>

      <!-- The modules of the picked course: two columns of cards with the picture of the topic. -->
      <section v-if="selected" class="mt-6" data-testid="modules">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="t-display-m min-w-0" data-testid="modules-title">{{ tileLabel(selected) }} · {{ t('course.modules') }}</h2>
          <span v-if="modulesCount" class="t-body-m shrink-0 text-muted tabular-nums" data-testid="modules-count">{{ modulesCount }}</span>
        </div>

        <div v-if="selectedModules === null || selectedModules.status === 'loading'" class="mt-3 grid grid-cols-2 gap-3" role="status" aria-busy="true" data-testid="modules-loading">
          <span class="sr-only">{{ t('common.loading') }}</span>
          <div v-for="card in 4" :key="card" class="skeleton h-40 rounded-lg" aria-hidden="true" />
        </div>

        <div v-else-if="selectedModules.status === 'error'" class="mt-3 flex items-center gap-2" role="status" data-testid="modules-error">
          <AppIcon name="exclamation-circle" :size="18" class="shrink-0 text-warning" />
          <p class="t-label-s min-w-0 flex-1 text-muted">{{ selectedModules.message }}</p>
          <button type="button" class="btn btn-plain btn-sm shrink-0" data-testid="modules-retry" @click="loadModules(selected.slug)">
            {{ t('common.retry') }}
          </button>
        </div>

        <p v-else-if="selectedModules.detail.stages.length === 0" class="t-body-m mt-3 text-muted" data-testid="modules-empty">
          {{ t('course.no_lessons') }}
        </p>

        <ol v-else class="mt-3 grid grid-cols-2 gap-3">
          <li v-for="stage in selectedModules.detail.stages" :key="stage.number" :data-state="moduleState(stage)" :data-testid="`module-${stage.number}`">
            <RouterLink
              :to="{ name: 'course', params: { slug: selected.slug } }"
              class="pressable flex h-full flex-col items-center gap-2 rounded-lg bg-surface px-3 pt-4 pb-3.5 text-center"
              :class="moduleState(stage) === 'current' ? 'border-2 border-strong' : 'border border-hairline'"
            >
              <TopicArt :course="selected.slug" :stage="stage.number" :size="76" :class="{ 'opacity-45 grayscale': moduleState(stage) === 'locked' }">
                <template #fallback>
                  <span class="icon-tile t-title-m size-[76px] rounded-md" :class="{ 'text-muted': moduleState(stage) === 'locked' }">{{ stage.number }}</span>
                </template>
              </TopicArt>
              <span class="t-body-m-strong [overflow-wrap:anywhere]" :class="{ 'text-muted': moduleState(stage) === 'locked' }" data-testid="module-title">{{ moduleTitle(stage) }}</span>
              <span
                class="t-label-s"
                :class="{
                  'text-success-ink lowercase': moduleState(stage) === 'done',
                  'text-accent': moduleState(stage) === 'current',
                  'text-muted': moduleState(stage) === 'locked' || moduleState(stage) === 'open',
                }"
                data-testid="module-meta"
              >{{ moduleMeta(stage) }}</span>
            </RouterLink>
          </li>
        </ol>
      </section>
    </div>
  </div>
</template>
