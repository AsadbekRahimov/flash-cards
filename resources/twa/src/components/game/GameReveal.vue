<script setup lang="ts">
/**
 * What the server said about an answer in a run (docs/05 §5b.3): the verdict as a symbol AND words (the colour is never alone), the
 * explanation, and what the answer did to the run - the points, a flask lost, the oasis that gave one back. "Next" is at the bottom
 * (`StickyActions`); on the last element it leads to the summary (`last`).
 *
 * - A pairs round, a collocation, a sentence and a dictation are explained by `AnswerReveal` of the practice, through the adapter.
 * - A task made of a word (`translate_*`, `listen`, `spell`, `gap`): the right answer, the word with its translation, for `gap` the whole
 *   example, and - after a miss - what the learner answered.
 * - An answer that was counted EARLIER (409 `answer_already_accepted`): only the verdict and "an answer sent earlier was counted".
 */
import { computed } from 'vue'
import { isPracticeElement, practiceOutcomeOf } from '@/components/game/practiceAdapter'
import AnswerReveal from '@/components/practice/AnswerReveal.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { verdictLabel } from '@/composables/practiceLabels'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { GameWordReveal } from '@/api/gameRunTypes'
import type { PracticeVerdict } from '@/api/types'
import type { GameOutcome } from '@/stores/gameRun'

const props = defineProps<{
  outcome: GameOutcome
  /** The next step is the summary, not another element. */
  last?: boolean
}>()

const emit = defineEmits<{ continue: [] }>()

const { haptic } = useTelegram()
const { t } = useI18n()

const LOOKS: Record<PracticeVerdict, { icon: IconName; circle: string }> = {
  correct: { icon: 'check', circle: 'bg-success-soft text-success-ink' },
  typo: { icon: 'exclamation-circle', circle: 'bg-warning-soft text-warning-ink' },
  wrong: { icon: 'x-mark', circle: 'bg-danger-soft text-danger-ink' },
  skipped: { icon: 'light-bulb', circle: 'bg-accent-soft text-accent-ink' },
}

const look = computed(() => LOOKS[props.outcome.verdict])

/** The practice components' explanation, when the element is one of their kinds. */
const practiceOutcome = computed(() => {
  const outcome = props.outcome
  return isPracticeElement(outcome.element) ? practiceOutcomeOf({ ...outcome, element: outcome.element }) : null
})

const word = computed((): GameWordReveal | null => {
  const reveal = props.outcome.reveal
  return reveal !== null && 'correct_text' in reveal ? (reveal as GameWordReveal) : null
})

/** What the learner answered, in words (after a miss only): the text typed, or the text of the option picked. */
const yours = computed((): string | null => {
  const { answer, element, verdict } = props.outcome
  if (verdict === 'correct' || props.outcome.earlier) return null
  if ('text' in answer && answer.text.trim() !== '') return answer.text
  if ('option_id' in answer && 'options' in element) return element.options.find((option) => option.id === answer.option_id)?.text ?? null
  return null
})

const chips = computed(() => ({
  points: props.outcome.points > 0,
  flask: props.outcome.flaskLost,
  oasis: props.outcome.eventApplied === 'oasis',
}))
const hasChips = computed((): boolean => chips.value.points || chips.value.flask || chips.value.oasis)

function next(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="game-reveal">
    <div v-if="hasChips" class="flex flex-wrap justify-center gap-2 px-4 pt-3" data-testid="reveal-chips">
      <span v-if="chips.points" class="chip chip-success" data-testid="chip-points">{{ t('gameRun.reveal.points', { points: outcome.points }) }}</span>
      <span v-if="chips.flask" class="chip chip-danger" data-testid="chip-flask">{{ t('gameRun.reveal.flask_lost') }}</span>
      <span v-if="chips.oasis" class="chip chip-accent" data-testid="chip-oasis">{{ t('gameRun.event.applied_oasis') }}</span>
    </div>

    <AnswerReveal v-if="practiceOutcome" :outcome="practiceOutcome" :last="last" @continue="emit('continue')" />

    <template v-else>
      <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4" data-testid="word-reveal">
        <div class="flex flex-col items-center gap-2 text-center">
          <span class="flex size-16 items-center justify-center rounded-full" :class="look.circle" :data-verdict="outcome.verdict" data-testid="verdict-circle">
            <AppIcon :name="look.icon" :size="32" />
          </span>
          <h2 class="text-[22px] leading-7 font-bold" data-testid="verdict-label">{{ verdictLabel('collocation', outcome.verdict) }}</h2>
        </div>

        <p v-if="outcome.earlier" class="text-center text-[15px] leading-5 text-tg-hint" data-testid="earlier-note">{{ t('practice.reveal.earlier') }}</p>

        <article v-if="word" class="card flex flex-col gap-1 text-center">
          <p class="text-[13px] leading-[18px] font-medium text-tg-subtitle">{{ t('gameRun.reveal.right') }}</p>
          <p class="text-[22px] leading-7 font-bold break-words" lang="en" data-testid="right-answer">{{ word.correct_text }}</p>
          <p v-if="word.full_text" class="mt-1 text-[15px] leading-5 break-words" lang="en" data-testid="full-text">{{ word.full_text }}</p>
          <p v-if="word.word && word.word !== word.correct_text" class="text-[15px] leading-5 text-tg-hint" lang="en" data-testid="word">{{ word.word }}</p>
          <p v-if="word.translation" class="text-[15px] leading-5 text-tg-hint" data-testid="translation">{{ word.translation }}</p>
        </article>

        <p v-if="yours" class="text-center text-[15px] leading-5 text-tg-hint" data-testid="your-answer">{{ t('practice.reveal.your_answer') }}: {{ yours }}</p>
      </div>

      <StickyActions>
        <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="next-btn" @click="next">
          {{ last ? t('gameRun.reveal.to_summary') : t('gameRun.reveal.next') }}
        </button>
      </StickyActions>
    </template>
  </div>
</template>
