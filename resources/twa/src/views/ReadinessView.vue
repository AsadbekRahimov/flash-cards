<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import WeeklyQuizChart from '@/components/WeeklyQuizChart.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { readinessSegments } from '@/composables/readiness'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useExamStore } from '@/stores/exam'
import { useReadinessStore } from '@/stores/readiness'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { ReadinessSource } from '@/api/types'

/**
 * Readiness for the level of a course (docs/05 §5, docs/04 A.20): the share of the level's words the learner has learned, the
 * three parts, the progress by modules, the test of the week with its history by weeks. It is an estimate of the vocabulary,
 * not a promise about an exam - the text says so. `?source=today|profile|course` is where the learner came from (analytics).
 */
const props = defineProps<{ slug: string }>()

const { t, tn } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useReadinessStore()
const exam = useExamStore()
const { data, status, errorMessage: loadError, disabled } = storeToRefs(store)
const { openingQuiz, quizError } = storeToRefs(exam)

useBackButton(() => {
  const from = route.query.source
  if (from === 'profile') void router.push({ name: 'profile' })
  else if (from === 'course') void router.push({ name: 'course', params: { slug: props.slug } })
  else void router.push({ name: 'today' })
})

const SOURCES: ReadinessSource[] = ['today', 'profile', 'course']
const source = computed((): ReadinessSource => {
  const from = route.query.source
  return SOURCES.find((item) => item === from) ?? 'today'
})

const HISTORY_WEEKS = 12

/** The test block: the button on Home leads here (`?focus=quiz`) so the rules are read before the one scored attempt is started. */
const quizSection = ref<HTMLElement | null>(null)
let focused = false

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    store.status = 'error'
    store.errorMessage = errorMessage(err, 'catalog')
    return
  }
  await store.load(props.slug, source.value)
}

onMounted(() => {
  exam.clearQuizError()
  void load()
})
onBeforeUnmount(() => exam.clearQuizError())

const level = computed(() => data.value?.course.level_label ?? null)
const heading = computed(() => {
  const percent = data.value?.percent ?? 0
  return level.value ? t('readiness.percent_level', { percent, level: level.value }) : t('readiness.percent_course', { percent })
})
const segments = computed(() => (data.value ? readinessSegments(data.value.words) : null))
const barLabel = computed(() => {
  const current = data.value
  if (!current) return ''
  return t('readiness.progress_label', {
    percent: current.percent,
    learned: current.words.learned,
    in_progress: current.words.in_progress,
    not_started: current.words.not_started,
  })
})
const quiz = computed(() => (data.value?.quiz.enabled ? data.value.quiz : null))
const scoredDone = computed(() => quiz.value?.this_week != null)

const minutes = computed(() => Math.max(1, Math.round((quiz.value?.seconds ?? 0) / 60)))
const quizHint = computed(() => (quiz.value ? tn('readiness.quiz.hint', quiz.value.questions, { minutes: minutes.value }) : ''))

watch([quizSection, () => route.query.focus], async ([section, focus]) => {
  if (focused || !section || focus !== 'quiz') return
  focused = true
  await nextTick()
  section.scrollIntoView?.({ block: 'start' })
  section.focus({ preventScroll: true })
})

/** Opens (or resumes) the test; a refusal stays on this screen as a message under the button. */
async function takeQuiz(): Promise<void> {
  const sessionId = await exam.openLevelQuiz(props.slug)
  if (sessionId !== null) await router.push({ name: 'exam', params: { sessionId }, query: { mode: 'quiz' } })
}
</script>

<template>
  <div class="page" data-testid="readiness-view">
    <PageHeader :title="t('readiness.title')" :subtitle="data?.course.title" />

    <LoadingState v-if="!data && status !== 'error'" />

    <ErrorState v-else-if="!data && disabled" :message="t('readiness.unavailable')" data-testid="readiness-disabled" />

    <ErrorState v-else-if="!data" :message="loadError" @retry="load" />

    <div v-else class="space-y-4">
      <InlineAlert v-if="status === 'error'" tone="danger" :message="loadError" data-testid="reload-error" />

      <section class="card" data-testid="readiness-summary">
        <p class="text-[28px] leading-[34px] font-bold tabular-nums" data-testid="readiness-percent">{{ data.percent }}%</p>
        <p class="mt-0.5 text-[15px] leading-5" data-testid="readiness-heading">{{ heading }}</p>

        <div
          class="progress mt-3 flex"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="data.percent"
          :aria-label="barLabel"
          data-testid="readiness-bar"
        >
          <div class="h-full bg-success" :style="{ width: `${segments?.learned ?? 0}%` }" />
          <div class="h-full bg-accent" :style="{ width: `${segments?.inProgress ?? 0}%` }" />
        </div>

        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
          <div data-testid="readiness-learned">
            <dt class="flex items-center justify-center gap-1 text-[13px] leading-[18px] text-tg-hint">
              <span class="size-2 rounded-full bg-success" aria-hidden="true" />{{ t('readiness.learned') }}
            </dt>
            <dd class="text-[17px] leading-[22px] font-semibold tabular-nums">{{ data.words.learned }}</dd>
          </div>
          <div data-testid="readiness-in-progress">
            <dt class="flex items-center justify-center gap-1 text-[13px] leading-[18px] text-tg-hint">
              <span class="size-2 rounded-full bg-accent" aria-hidden="true" />{{ t('readiness.in_progress') }}
            </dt>
            <dd class="text-[17px] leading-[22px] font-semibold tabular-nums">{{ data.words.in_progress }}</dd>
          </div>
          <div data-testid="readiness-not-started">
            <dt class="flex items-center justify-center gap-1 text-[13px] leading-[18px] text-tg-hint">
              <span class="size-2 rounded-full bg-track" aria-hidden="true" />{{ t('readiness.not_started') }}
            </dt>
            <dd class="text-[17px] leading-[22px] font-semibold tabular-nums">{{ data.words.not_started }}</dd>
          </div>
        </dl>
        <p v-if="data.words.mastered > 0" class="mt-2 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="readiness-mastered">
          {{ t('readiness.mastered', { count: data.words.mastered }) }}
        </p>

        <p class="mt-3 text-[13px] leading-[18px] text-tg-hint" data-testid="readiness-explain">
          {{ tn('readiness.explain', data.thresholds.known_days) }}
        </p>
      </section>

      <section
        v-if="quiz"
        ref="quizSection"
        tabindex="-1"
        class="card space-y-3 outline-none"
        :aria-label="t('readiness.quiz.title')"
        data-testid="readiness-quiz"
      >
        <div class="flex items-start gap-3">
          <span class="icon-tile tile-purple"><AppIcon name="flag" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('readiness.quiz.title') }}</h2>
            <p v-if="quiz.this_week" class="mt-0.5 text-[15px] leading-5" data-testid="quiz-this-week">
              {{ t('readiness.quiz.result', { correct: quiz.this_week.correct, total: quiz.this_week.total }) }}
              · {{ quiz.this_week.percent }}%
            </p>
            <p v-if="quiz.best_percent !== null" class="mt-0.5 text-[13px] leading-[18px] text-tg-hint" data-testid="quiz-best">
              {{ t('readiness.quiz.best', { percent: quiz.best_percent }) }}
            </p>
          </div>
        </div>

        <WeeklyQuizChart :history="quiz.history" :weeks="HISTORY_WEEKS" :current-week="quiz.week_start" />

        <p class="text-[13px] leading-[18px] text-tg-hint" data-testid="quiz-hint">
          {{ scoredDone ? t('readiness.quiz.practice_hint') : quizHint }}
        </p>
        <InlineAlert v-if="quizError" tone="danger" :message="quizError" data-testid="quiz-error" />
        <button
          type="button"
          class="btn btn-block"
          :class="scoredDone ? 'btn-tinted' : 'btn-primary'"
          :disabled="openingQuiz"
          data-testid="quiz-btn"
          :data-scored="scoredDone ? 'done' : 'open'"
          @click="takeQuiz"
        >
          {{ scoredDone ? t('readiness.quiz.again') : t('readiness.quiz.take') }}
        </button>
      </section>

      <section v-if="data.modules.length > 0" data-testid="readiness-modules">
        <h2 class="section-label">{{ t('readiness.modules') }}</h2>
        <div class="card space-y-3">
          <div v-for="item in data.modules" :key="item.number" :data-testid="`readiness-module-${item.number}`">
            <div class="flex items-baseline justify-between gap-2 text-[15px] leading-5">
              <span class="min-w-0 truncate">{{ item.title ?? t('readiness.module', { number: item.number }) }}</span>
              <span class="shrink-0 text-tg-hint tabular-nums">{{ item.learned }} / {{ item.total }}</span>
            </div>
            <div
              class="progress mt-1 h-1"
              role="progressbar"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="item.total > 0 ? Math.min(100, Math.floor((item.learned / item.total) * 100)) : 0"
              :aria-label="item.title ?? t('readiness.module', { number: item.number })"
            >
              <div
                class="progress-fill progress-success"
                :style="{ width: `${item.total > 0 ? Math.min(100, (item.learned / item.total) * 100) : 0}%` }"
              />
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
