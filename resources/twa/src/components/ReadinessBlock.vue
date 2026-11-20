<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import { readinessSegments } from '@/composables/readiness'
import { useI18n } from '@/i18n'
import type { TodayReadiness } from '@/api/types'

/**
 * The compact "readiness for the level" block of Home (docs/05 §5, docs/04 A.20): the share of the course's words the learner
 * has learned, a bar of three parts (learned / in progress / not started) and one line about the test of the week - the
 * result of this week or the button. It reads only what `/today` carries. The top of the block leads to the full screen.
 *
 * The test button only leads to the readiness screen (`focus=quiz`), where the rules sit next to the start button: the scored
 * test is one per week with a timer, a stray tap on Home must not spend it.
 *
 * Props: `readiness` (`active_course.readiness`), `slug` (of the course).
 */
const props = defineProps<{
  readiness: TodayReadiness
  slug: string
}>()

const { t } = useI18n()

const segments = computed(() => readinessSegments(props.readiness.words))
const title = computed(() => {
  // The same two numbers the percent is floored from (`LevelReadinessService`: 100 × learned / total).
  const { percent, level_label: level, words } = props.readiness
  const counts = { percent, learned: words.learned, total: words.total }
  return level ? t('readiness.percent_level', { ...counts, level }) : t('readiness.percent_course', counts)
})
const barLabel = computed(() => {
  const { words, percent } = props.readiness
  return t('readiness.progress_label', {
    percent,
    learned: words.learned,
    total: words.total,
    in_progress: words.in_progress,
    not_started: words.not_started,
  })
})
const quiz = computed(() => (props.readiness.quiz.enabled ? props.readiness.quiz : null))
const thisWeek = computed(() => quiz.value?.this_week ?? null)
</script>

<template>
  <div class="space-y-2 border-t border-divider pt-3" data-testid="readiness-block">
    <RouterLink
      :to="`/courses/${encodeURIComponent(slug)}/readiness?source=today`"
      class="block"
      data-testid="readiness-link"
    >
      <span class="flex items-center justify-between gap-2">
        <span class="min-w-0 text-[15px] leading-5 font-semibold" data-testid="readiness-title">{{ title }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
      </span>
      <span
        class="progress mt-2 flex"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="readiness.percent"
        :aria-label="barLabel"
        data-testid="readiness-bar"
      >
        <span class="h-full bg-success" :style="{ width: `${segments.learned}%` }" data-testid="readiness-seg-learned" />
        <span class="h-full bg-accent" :style="{ width: `${segments.inProgress}%` }" data-testid="readiness-seg-progress" />
      </span>
      <span class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] leading-[18px] text-muted" data-testid="readiness-legend">
        <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-success" aria-hidden="true" /><span class="font-mono">{{ readiness.words.learned }}</span> {{ t('readiness.learned') }}</span>
        <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-accent" aria-hidden="true" /><span class="font-mono">{{ readiness.words.in_progress }}</span> {{ t('readiness.in_progress') }}</span>
        <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-track" aria-hidden="true" /><span class="font-mono">{{ readiness.words.not_started }}</span> {{ t('readiness.not_started') }}</span>
      </span>
    </RouterLink>

    <template v-if="quiz">
      <p v-if="thisWeek" class="text-[15px] leading-5" data-testid="readiness-quiz-result">
        {{ t('readiness.quiz.line', { correct: thisWeek.correct, total: thisWeek.total }) }}
      </p>
      <RouterLink
        v-else
        :to="`/courses/${encodeURIComponent(slug)}/readiness?source=today&focus=quiz`"
        class="btn btn-tinted btn-block"
        data-testid="readiness-quiz-btn"
      >
        {{ t('readiness.quiz.take') }}
      </RouterLink>
    </template>
  </div>
</template>
