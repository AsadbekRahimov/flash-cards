<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import type { IconName } from '@/components/ui/icons'
import { formatDuration } from '@/composables/practiceLabels'
import { playSound } from '@/composables/useFeedbackSound'
import { useI18n } from '@/i18n'
import type { PracticeFinishReason, PracticeMistake, PracticeMode, PracticeSummary as PracticeSummaryData } from '@/api/types'

/**
 * The summary of a finished solo session (docs/16 §6.4, §11.2): the numbers, the list of mistakes and what to do next.
 *
 * - One card of three numbers (docs/design Finish, in `font-mono`): done (rounds for pairs) / right from the first try / hints
 *   (wrong matches for pairs). A line below it adds the typos and the time.
 * - The mistakes, one row each: what it was, what the learner wrote (struck through) and the kind of the mistake as a
 *   chip with a symbol; no mistakes — a quiet line that says so.
 * - At the bottom: "Repeat mistakes" (the one primary button) when the server says they can be repeated, otherwise
 *   "Again"; "To the course" and "Close" as text buttons. Events: `retry`, `again`, `course`, `close`.
 *
 * A timed game (`pairs_timed`, the summary has `score`) has its own face: "Score: N", "New record!" or "Record: M", the time and the
 * wrong matches under it, and the sound of a done lesson; the buttons are "Again" and "To the games" (`hub`); no mistakes, no "Repeat
 * mistakes". No celebration on either face (redesign 2026-11): no big icon, no confetti - the words and the numbers say it.
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

/**
 * A sentence with its number in `font-mono` (docs/14 §4.5: numbers are mono, the words around them are not): the localized text cut
 * around the first place the number is written. A text without it (a translation that spells it differently) is one plain part.
 */
function withNumber(text: string, value: number | string): { before: string; number: string; after: string } {
  const number = String(value)
  const at = text.indexOf(number)
  return at < 0 ? { before: text, number: '', after: '' } : { before: text.slice(0, at), number, after: text.slice(at + number.length) }
}

const scoreLine = computed(() => withNumber(t('practice.timed.summary_score', { score: props.summary.score ?? 0 }), props.summary.score ?? 0))
const bestLine = computed(() =>
  props.summary.best_score == null ? null : withNumber(t('practice.timed.summary_best', { score: props.summary.best_score }), props.summary.best_score),
)

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
    <div class="flex flex-1 flex-col gap-2 px-5 pt-10 pb-6">
      <p
        class="text-[13px] leading-[18px]"
        :class="summary.finish_reason === 'completed' ? 'text-success-ink' : 'text-muted'"
        data-testid="summary-reason"
      >
        {{ summary.finish_reason === 'completed' ? t('practice.timed.summary_completed') : t(reasonKey(summary.finish_reason)) }}
      </p>
      <h1 class="text-[32px] leading-[38px] font-semibold tracking-[-0.4px]" data-testid="timed-summary-score">
        {{ scoreLine.before }}<span class="font-mono font-medium">{{ scoreLine.number }}</span>{{ scoreLine.after }}
      </h1>
      <p v-if="record" class="text-[15px] leading-5 text-success-ink" data-testid="timed-new-record">{{ t('practice.timed.summary_new_record') }}</p>
      <p v-else-if="bestLine" class="text-[15px] leading-5 text-muted" data-testid="timed-best">
        {{ bestLine.before }}<span class="font-mono">{{ bestLine.number }}</span>{{ bestLine.after }}
      </p>
      <p class="text-[13px] leading-[18px] text-muted" data-testid="summary-facts">{{ timedFacts }}</p>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="busy" data-testid="again-btn" @click="emit('again')">
        {{ t('practice.timed.again') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" data-testid="hub-btn" @click="emit('hub')">
        {{ t('practice.timed.to_games') }}
      </button>
    </StickyActions>
  </div>

  <div v-else class="flex flex-1 flex-col" data-testid="practice-summary">
    <div class="flex flex-1 flex-col px-5 pt-10 pb-6">
      <header class="flex flex-col gap-2">
        <h1 class="text-[32px] leading-[38px] font-semibold tracking-[-0.4px]">{{ t('practice.summary.title') }}</h1>
        <p class="text-[15px] leading-5 text-muted" data-testid="summary-reason">{{ t(reasonKey(summary.finish_reason)) }}</p>
      </header>

      <!-- Three numbers in one card, the lines between them (docs/design Finish). -->
      <div class="mt-7 grid grid-cols-3 rounded-card border border-hairline bg-surface" data-testid="summary-stats">
        <div class="flex min-w-0 flex-col gap-1 px-3 py-4">
          <p class="font-mono text-xl leading-7 font-medium break-words" data-testid="stat-done">{{ summary.answered }} / {{ summary.total }}</p>
          <p class="text-[13px] leading-[18px] text-muted">{{ isPairs ? t('practice.summary.stat.rounds') : t('practice.summary.stat.done') }}</p>
        </div>
        <div class="flex min-w-0 flex-col gap-1 border-l border-divider px-3 py-4">
          <p class="font-mono text-xl leading-7 font-medium break-words" data-testid="stat-first">{{ summary.correct_first }}</p>
          <p class="text-[13px] leading-[18px] text-muted">{{ t('practice.summary.stat.first') }}</p>
        </div>
        <div class="flex min-w-0 flex-col gap-1 border-l border-divider px-3 py-4">
          <p class="font-mono text-xl leading-7 font-medium break-words" data-testid="stat-third">{{ isPairs ? (summary.mismatches ?? 0) : summary.hints }}</p>
          <p class="text-[13px] leading-[18px] text-muted">{{ isPairs ? t('practice.summary.stat.mismatches') : t('practice.summary.stat.hints') }}</p>
        </div>
      </div>
      <p class="mt-2 px-[18px] text-[13px] leading-[18px] text-muted" data-testid="summary-facts">{{ facts }}</p>

      <ListGroup v-if="summary.mistakes.length > 0" :header="t('practice.summary.mistakes')" data-testid="mistakes">
        <!-- Pairs: several words of one round are several rows of the same element, so the index is part of the key. -->
        <div v-for="(mistake, index) in summary.mistakes" :key="`${mistake.element_id}:${index}`" class="list-row" data-testid="mistake-row">
          <div class="min-w-0 flex-1">
            <p class="text-[15px] leading-5 font-semibold break-words" data-testid="mistake-label">{{ mistake.label }}</p>
            <p v-if="mistake.your_answer" class="mt-0.5 text-[13px] leading-[18px] text-muted break-words" data-testid="mistake-answer">
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
      <p v-else class="mt-5 flex items-center gap-2 px-[18px] text-[15px] leading-5 text-success-ink" data-testid="no-mistakes">
        <AppIcon name="check" :size="18" />
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
      <button type="button" class="btn btn-plain btn-block" data-testid="course-btn" @click="emit('course')">
        {{ t('practice.summary.to_course') }}
      </button>
      <button type="button" class="btn btn-plain btn-block text-muted" data-testid="close-btn" @click="emit('close')">
        {{ t('common.exit_app') }}
      </button>
    </StickyActions>
  </div>
</template>
