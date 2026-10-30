<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { PracticeOutcome } from '@/stores/practice'
import type { CollocationReveal, DictationReveal, PracticeReveal, PracticeVerdict, SentenceReveal } from '@/api/types'

/**
 * What the server said about the answer (docs/16 §5, §11.2): a coloured circle with a symbol and the verdict in words
 * (the colour is never alone), then the explanation in the shape of the task:
 * - dictation — the phrase as it should be written, what was typed (the differing words marked), the translation;
 * - sentence — the correct sentence, the other accepted orders, what was assembled;
 * - collocation — the whole phrase, the three options with the right one and the picked one marked, the translation;
 * - the end of a round of pairs — "Round 2 of 3 is done" and the number of wrong matches in it.
 * With the explanation of the task, when it has one. "Next" is at the bottom (`StickyActions`); on the last element it
 * leads to the summary (`last`).
 *
 * The verdict is announced to a screen reader by the screen, in a live region that is always there.
 */
const props = defineProps<{
  outcome: PracticeOutcome
  /** The next step is the summary, not another element. */
  last?: boolean
}>()

const emit = defineEmits<{ continue: [] }>()

const { haptic } = useTelegram()
const { t } = useI18n()

const verdict = computed((): PracticeVerdict => props.outcome.verdict)

interface Look {
  icon: IconName
  /** The circle: the soft colour and its "ink" for the symbol. */
  circle: string
}

const LOOKS: Record<PracticeVerdict, Look> = {
  correct: { icon: 'check', circle: 'bg-success-soft text-success-ink' },
  typo: { icon: 'exclamation-circle', circle: 'bg-warning-soft text-warning-ink' },
  wrong: { icon: 'x-mark', circle: 'bg-danger-soft text-danger-ink' },
  skipped: { icon: 'light-bulb', circle: 'bg-accent-soft text-accent-ink' },
}

const isRoundEnd = computed(() => props.outcome.element.kind === 'pairs_round')
const look = computed((): Look => (isRoundEnd.value ? { icon: 'check-circle', circle: LOOKS.correct.circle } : LOOKS[verdict.value]))

const reveal = computed((): PracticeReveal | null => props.outcome.reveal)
const dictation = computed((): DictationReveal | null => (reveal.value !== null && 'expected' in reveal.value ? reveal.value : null))
const sentence = computed((): SentenceReveal | null => (reveal.value !== null && 'correct_text' in reveal.value ? reveal.value : null))
const collocation = computed((): CollocationReveal | null => (reveal.value !== null && 'correct_option_ids' in reveal.value ? reveal.value : null))

const translation = computed((): string | null => dictation.value?.translation ?? collocation.value?.translation ?? null)
const explanation = computed((): string | null => dictation.value?.explanation ?? sentence.value?.explanation ?? collocation.value?.explanation ?? null)

/** What the learner typed or assembled, when it was not right. */
const yourText = computed((): string | null => {
  if (verdict.value === 'correct') return null
  const text = dictation.value?.your_text ?? sentence.value?.your_text ?? null
  return text !== null && text.trim() !== '' ? text : null
})

/** The phrase as it should be written, marked token by token where the answer differed (a typo is a miss of one word). */
const diff = computed(() => (verdict.value === 'correct' ? null : (dictation.value?.diff ?? null)))

type OptionState = 'idle' | 'correct' | 'incorrect' | 'reveal'

interface ShownOption {
  id: string
  text: string
  state: OptionState
}

const options = computed((): ShownOption[] => {
  const element = props.outcome.element
  const done = collocation.value
  if (element.kind !== 'collocation' || done === null) return []
  const picked = 'option_id' in props.outcome.answer ? props.outcome.answer.option_id : null

  return element.options.map((option): ShownOption => {
    const right = done.correct_option_ids.includes(option.id)
    if (option.id === picked) return { ...option, state: right ? 'correct' : 'incorrect' }
    return { ...option, state: right ? 'reveal' : 'idle' }
  })
})

/** The end of a round of pairs: which round, the wrong matches in it, and its pairs (they have left the board one by one). */
const round = computed(() => {
  const element = props.outcome.element
  if (element.kind !== 'pairs_round') return null

  // The pairs matched before the last one are on the element; the last one is what was just answered.
  const matched = new Map(element.matched)
  const last = props.outcome.pair
  if (last !== null) matched.set(last.left_id, last.right_id)

  const pairs = element.left.flatMap((word) => {
    const translation = element.right.find((card) => card.id === matched.get(word.id))
    return translation === undefined ? [] : [{ id: word.id, word: word.text, translation: translation.text }]
  })

  return { round: element.round, rounds: element.rounds, mistakes: element.mismatches, pairs }
})

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="answer-reveal" :data-kind="outcome.element.kind" :data-verdict="isRoundEnd ? 'round' : verdict">
    <div class="flex flex-1 flex-col gap-3 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span class="flex size-16 items-center justify-center rounded-full" :class="look.circle" data-testid="reveal-icon">
          <AppIcon :name="look.icon" solid :size="32" />
        </span>

        <template v-if="round">
          <h2 class="text-[22px] leading-7 font-bold" data-testid="reveal-text">
            {{ t('practice.session.round_done', { round: round.round, rounds: round.rounds }) }}
          </h2>
          <p class="text-base leading-[22px] text-tg-subtitle" data-testid="round-mistakes">
            {{ round.mistakes === 0 ? t('practice.session.round_clean') : t('practice.session.round_mistakes', { count: round.mistakes }) }}
          </p>
          <div v-if="round.pairs.length > 0" class="mt-1 w-full" data-testid="round-pairs">
            <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.pairs.recap') }}</p>
            <ul class="mt-1.5 flex flex-col gap-1.5 text-left" role="list">
              <li v-for="pair in round.pairs" :key="pair.id" class="flex items-baseline justify-between gap-3 text-base leading-[22px]" data-testid="round-pair">
                <span class="min-w-0 font-medium break-words">{{ pair.word }}</span>
                <span class="min-w-0 text-right text-tg-subtitle break-words">{{ pair.translation }}</span>
              </li>
            </ul>
          </div>
        </template>

        <template v-else>
          <h2 class="text-[22px] leading-7 font-bold" data-testid="reveal-text">{{ t(`practice.verdict.${verdict}`) }}</h2>
          <p v-if="verdict === 'typo'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="reveal-note">{{ t('practice.reveal.typo_note') }}</p>
          <p v-else-if="verdict === 'skipped'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="reveal-note">{{ t('practice.reveal.skipped_note') }}</p>
        </template>

        <!-- The right answer, as the task wants it written. -->
        <div v-if="dictation || sentence" class="mt-1 w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('exercise.verdict.expected') }}</p>
          <p class="mt-1 text-2xl leading-8 font-bold break-words" data-testid="expected">{{ dictation?.expected ?? sentence?.correct_text }}</p>
        </div>
        <p v-if="collocation" class="mt-1 w-full text-2xl leading-8 font-bold break-words" data-testid="expected">{{ collocation.full_text }}</p>

        <div v-if="yourText" class="w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.your_answer') }}</p>
          <p v-if="diff" class="mt-1 flex flex-wrap justify-center gap-x-1.5 text-base leading-[22px]" data-testid="diff">
            <template v-for="(token, index) in diff" :key="index">
              <span v-if="token.ok">{{ token.got }}</span>
              <span v-else class="font-semibold text-danger-ink underline decoration-danger decoration-2 underline-offset-4">{{ token.got || '…' }}</span>
            </template>
          </p>
          <p v-else class="mt-1 text-base leading-[22px] text-tg-hint line-through break-words" data-testid="your-text">{{ yourText }}</p>
        </div>

        <div v-if="sentence && sentence.alternatives.length > 0" class="w-full" data-testid="alternatives">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.also_correct') }}</p>
          <p v-for="alternative in sentence.alternatives" :key="alternative" class="mt-1 text-base leading-[22px] break-words">{{ alternative }}</p>
        </div>
      </article>

      <!-- The three options of a collocation, each with a symbol and a word besides the colour. -->
      <div v-if="options.length > 0" class="flex flex-col gap-2.5" role="group" data-testid="reveal-options">
        <div v-for="option in options" :key="option.id" class="option" :data-state="option.state" :data-testid="`reveal-option-${option.id}`">
          <span class="min-w-0 flex-1 break-words">{{ option.text }}</span>
          <span v-if="option.state === 'correct' || option.state === 'reveal'" class="sr-only">{{ t('practice.reveal.right_option') }}</span>
          <span v-else-if="option.state === 'incorrect'" class="sr-only">{{ t('practice.reveal.your_option') }}</span>
          <AppIcon v-if="option.state === 'correct' || option.state === 'reveal'" name="check" :size="22" />
          <AppIcon v-else-if="option.state === 'incorrect'" name="x-mark" :size="22" />
        </div>
      </div>

      <section v-if="translation || explanation" class="card flex flex-col gap-3">
        <div v-if="translation" data-testid="translation">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.translation') }}</p>
          <p class="mt-0.5 text-base leading-[22px] italic">{{ translation }}</p>
        </div>
        <div v-if="explanation" data-testid="explanation">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('practice.reveal.explanation') }}</p>
          <p class="mt-0.5 text-base leading-[22px]">{{ explanation }}</p>
        </div>
      </section>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ last ? t('practice.session.to_summary') : t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
