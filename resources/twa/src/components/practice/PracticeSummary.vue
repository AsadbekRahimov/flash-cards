<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import type { IconName } from '@/components/ui/icons'
import { prefersReducedMotion } from '@/composables/motion'
import { formatDuration } from '@/composables/practiceLabels'
import { playSound } from '@/composables/useFeedbackSound'
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
 * A timed game (`pairs_timed`, the summary has `score`) has its own face: "Score: N", "New record!" (confetti and the sound of a done lesson,
 * the confetti not with reduced motion) or "Record: M", the time and the wrong matches under it; the buttons are "Again" and "To the games"
 * (`hub`); no mistakes, no "Repeat mistakes".
 *
 * `retryUnavailable` hides the retry after the server has refused it once. `busy`: a new session is being opened.
 */
const props = defineProps<{
  summary: PracticeSummaryData
  mode: PracticeMode
  busy?: boolean
  retryUnavailable?: boolean
}>()

const emit = defineEmits<{ retry: []; again: []; course: []; close: []; hub: [] }>()

const { t } = useI18n()

const isPairs = computed(() => props.mode === 'pairs')
const timed = computed(() => props.mode === 'pairs_timed' || props.summary.score !== undefined)
const record = computed(() => timed.value && props.summary.is_record === true)
const canRetry = computed(() => props.summary.retry_available && !props.retryUnavailable)

const reasonKey = (reason: PracticeFinishReason) => `practice.summary.reason.${reason}` as const

const facts = computed((): string => {
  const parts = [t('practice.summary.duration', { time: formatDuration(props.summary.duration_ms) })]
  if (props.summary.typos > 0) parts.push(t('practice.summary.typos', { count: props.summary.typos }))
  return parts.join(' · ')
})

const timedFacts = computed((): string =>
  [t('practice.summary.duration', { time: formatDuration(props.summary.duration_ms) }), `${t('practice.summary.stat.mismatches')}: ${props.summary.mismatches ?? 0}`].join(' · '),
)

/** Confetti for a new record: the same burst as the one of a finished lesson (style.css), fixed so that it is the same every time. */
const CONFETTI_PIECES = 20
const CONFETTI_MS = 1500
const confetti = ref(record.value && !prefersReducedMotion())
const confettiPieces = Array.from({ length: CONFETTI_PIECES }, (_, i) => {
  const angle = (i / CONFETTI_PIECES) * Math.PI * 2
  const reach = 90 + ((i * 37) % 90)
  return {
    color: i % 4,
    style: {
      '--dx': `${Math.round(Math.cos(angle) * reach)}px`,
      '--dy': `${Math.round(Math.sin(angle) * reach * 0.6 + 140 + ((i * 53) % 120))}px`,
      '--rot': `${180 + ((i * 71) % 360)}deg`,
      '--delay': `${(i % 5) * 40}ms`,
    },
  }
})
const confettiTimer: ReturnType<typeof setTimeout> | null = confetti.value ? setTimeout(() => (confetti.value = false), CONFETTI_MS) : null
onBeforeUnmount(() => {
  if (confettiTimer !== null) clearTimeout(confettiTimer)
})
if (timed.value) playSound('done')

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
  <div v-if="timed" class="flex flex-1 flex-col" data-testid="practice-summary" data-variant="timed">
    <div v-if="confetti" class="confetti" aria-hidden="true" data-testid="confetti">
      <span v-for="(piece, i) in confettiPieces" :key="i" class="confetti-piece" :class="`confetti-${piece.color}`" :style="piece.style" />
    </div>
    <div class="flex flex-1 flex-col justify-center gap-4 px-4 pt-4 pb-6">
      <header class="text-center">
        <span class="mx-auto flex size-20 items-center justify-center rounded-full bg-success-soft text-success" data-testid="summary-icon">
          <AppIcon :name="record ? 'trophy' : 'bolt'" solid :size="48" />
        </span>
        <h1 class="mt-4 text-[34px] leading-[40px] font-bold tracking-tight tabular-nums" data-testid="timed-summary-score">
          {{ t('practice.timed.summary_score', { score: summary.score ?? 0 }) }}
        </h1>
        <p v-if="record" class="mt-2 text-[20px] leading-6 font-semibold text-success-ink" data-testid="timed-new-record">{{ t('practice.timed.summary_new_record') }}</p>
        <p v-else-if="summary.best_score != null" class="mt-2 text-[17px] leading-[22px] font-medium text-tg-subtitle" data-testid="timed-best">
          {{ t('practice.timed.summary_best', { score: summary.best_score }) }}
        </p>
        <p class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="summary-reason">
          {{ summary.finish_reason === 'completed' ? t('practice.timed.summary_completed') : t(reasonKey(summary.finish_reason)) }}
        </p>
      </header>
      <p class="text-center text-[13px] leading-[18px] text-tg-hint" data-testid="summary-facts">{{ timedFacts }}</p>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="again-btn" @click="emit('again')">
        {{ t('practice.timed.again') }}
      </button>
      <button type="button" class="btn btn-tinted btn-block" data-testid="hub-btn" @click="emit('hub')">
        {{ t('practice.timed.to_games') }}
      </button>
    </StickyActions>
  </div>

  <div v-else class="flex flex-1 flex-col" data-testid="practice-summary">
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
        {{ t('common.exit_app') }}
      </button>
    </StickyActions>
  </div>
</template>
