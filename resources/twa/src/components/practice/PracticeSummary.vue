<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import type { IconName } from '@/components/ui/icons'
import { formatDuration } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { PracticeFinishReason, PracticeMistake, PracticeMode, PracticeSummary as PracticeSummaryData } from '@/api/types'

/**
 * The summary of a finished solo session (docs/16 §6.4, §11.2): the numbers, the list of mistakes and what to do next.
 *
 * - Three tiles: done (rounds for pairs) / right from the first try / hints (wrong matches for pairs). A line below
 *   them adds the typos and the time.
 * - The mistakes, one row each: what it was, what the learner wrote (struck through) and the kind of the mistake as a
 *   chip with a symbol; no mistakes — a word of praise.
 * - At the bottom: "Repeat mistakes" (the one primary button) when the server says they can be repeated, otherwise
 *   "Again"; "To the course"; "Close". Events: `retry`, `again`, `course`, `close`.
 *
 * `retryUnavailable` hides the retry after the server has refused it once. `busy`: a new session is being opened.
 */
const props = defineProps<{
  summary: PracticeSummaryData
  mode: PracticeMode
  busy?: boolean
  retryUnavailable?: boolean
}>()

const emit = defineEmits<{ retry: []; again: []; course: []; close: [] }>()

const { t } = useI18n()

const isPairs = computed(() => props.mode === 'pairs')
const canRetry = computed(() => props.summary.retry_available && !props.retryUnavailable)

const reasonKey = (reason: PracticeFinishReason) => `practice.summary.reason.${reason}` as const

const facts = computed((): string => {
  const parts = [t('practice.summary.duration', { time: formatDuration(props.summary.duration_ms) })]
  if (props.summary.typos > 0) parts.push(t('practice.summary.typos', { count: props.summary.typos }))
  return parts.join(' · ')
})

interface Chip {
  icon: IconName
  tone: string
  label: string
}

function chipOf(mistake: PracticeMistake): Chip {
  switch (mistake.verdict) {
    case 'typo':
      return { icon: 'exclamation-circle', tone: 'chip-warning', label: t('practice.summary.chip.typo') }
    case 'skipped':
      return { icon: 'light-bulb', tone: 'chip-accent', label: t('practice.summary.chip.skipped') }
    default:
      return { icon: 'x-mark', tone: 'chip-danger', label: t('practice.summary.chip.wrong') }
  }
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="practice-summary">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-6">
      <header class="text-center">
        <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-success-soft text-success" data-testid="summary-icon">
          <AppIcon name="check-circle" solid :size="48" />
        </span>
        <h1 class="mt-4 text-[28px] leading-[34px] font-bold tracking-tight">{{ t('practice.summary.title') }}</h1>
        <p class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="summary-reason">{{ t(reasonKey(summary.finish_reason)) }}</p>
      </header>

      <div class="grid grid-cols-3 gap-2" data-testid="summary-stats">
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-done">{{ summary.answered }} / {{ summary.total }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ isPairs ? t('practice.summary.stat.rounds') : t('practice.summary.stat.done') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-first">{{ summary.correct_first }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('practice.summary.stat.first') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-third">{{ isPairs ? (summary.mismatches ?? 0) : summary.hints }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ isPairs ? t('practice.summary.stat.mismatches') : t('practice.summary.stat.hints') }}</p>
        </div>
      </div>
      <p class="-mt-1 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="summary-facts">{{ facts }}</p>

      <ListGroup v-if="summary.mistakes.length > 0" :header="t('practice.summary.mistakes')" data-testid="mistakes">
        <!-- Pairs: several words of one round are several rows of the same element, so the index is part of the key. -->
        <div v-for="(mistake, index) in summary.mistakes" :key="`${mistake.element_id}:${index}`" class="list-row" data-testid="mistake-row">
          <div class="min-w-0 flex-1">
            <p class="text-base leading-[22px] font-medium break-words" data-testid="mistake-label">{{ mistake.label }}</p>
            <p v-if="mistake.your_answer" class="text-[13px] leading-[18px] text-tg-hint break-words" data-testid="mistake-answer">
              <span class="sr-only">{{ t('practice.reveal.your_answer') }}: </span>
              <span class="line-through">{{ mistake.your_answer }}</span>
            </p>
          </div>
          <span class="chip shrink-0" :class="chipOf(mistake).tone" data-testid="mistake-chip">
            <AppIcon :name="chipOf(mistake).icon" :size="12" />
            {{ chipOf(mistake).label }}
          </span>
        </div>
      </ListGroup>
      <p
        v-else
        class="flex items-center justify-center gap-2 text-center text-[15px] leading-5 font-medium text-success-ink"
        data-testid="no-mistakes"
      >
        <AppIcon name="check-circle" solid :size="20" />
        {{ t('practice.summary.perfect') }}
      </p>
    </div>

    <StickyActions>
      <button v-if="canRetry" type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="retry-btn" @click="emit('retry')">
        {{ t('practice.summary.retry') }}
      </button>
      <button v-else type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="again-btn" @click="emit('again')">
        {{ t('practice.summary.again') }}
      </button>
      <button type="button" class="btn btn-tinted btn-block" data-testid="course-btn" @click="emit('course')">
        {{ t('practice.summary.to_course') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="close-btn" @click="emit('close')">
        {{ t('common.close') }}
      </button>
    </StickyActions>
  </div>
</template>
