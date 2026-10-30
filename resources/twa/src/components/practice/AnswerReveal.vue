<script setup lang="ts">
import { computed } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { filledWith, phraseSize, shownOptions, splitPhrase } from '@/composables/collocationPhrase'
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

/** The options of a collocation as the answer left them: the pick `correct` / `incorrect` (by the verdict), the right ones marked. */
const options = computed(() => {
  const element = props.outcome.element
  const done = collocation.value
  if (element.kind !== 'collocation' || done === null) return []
  const picked = 'option_id' in props.outcome.answer ? props.outcome.answer.option_id : null

  return shownOptions(element.options, picked, verdict.value, done.correct_option_ids)
})

/** The whole phrase cut around the right option, so that the word the learner needed can be marked in it. */
const phrase = computed((): { before: string; filled: string; after: string } | null => {
  const element = props.outcome.element
  const done = collocation.value
  if (element.kind !== 'collocation' || done === null) return null
  const parts = splitPhrase(element.text)
  const filled = parts === null ? null : filledWith(done.full_text, parts)

  return parts !== null && filled !== null ? { before: parts.before, filled, after: parts.after } : null
})

const round = computed(() => {
  const element = props.outcome.element
  return element.kind === 'pairs_round' ? { round: element.round, rounds: element.rounds, mistakes: element.mismatches } : null
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
        <!-- The whole collocation: the phrase with the right option in the blank, the word the learner needed marked. -->
        <div v-if="collocation" class="mt-1 w-full">
          <p class="text-[13px] leading-[18px] font-medium tracking-wide text-tg-section-header uppercase">{{ t('exercise.verdict.expected') }}</p>
          <p class="mt-1 font-bold break-words" :class="phraseSize(collocation.full_text)" lang="en" data-testid="expected">
            <template v-if="phrase">
              <span>{{ phrase.before }}</span>
              <span class="rounded-md bg-success-soft px-1 text-success-ink" data-testid="expected-fill">{{ phrase.filled }}</span>
              <span>{{ phrase.after }}</span>
            </template>
            <template v-else>{{ collocation.full_text }}</template>
          </p>
        </div>

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

      <!-- The three options of a collocation: the pick and the right one, each with a symbol and a caption besides the colour. -->
      <div v-if="options.length > 0" class="flex flex-col gap-2.5" role="group" data-testid="reveal-options">
        <div v-for="option in options" :key="option.id" class="option" :data-state="option.state" :data-testid="`reveal-option-${option.id}`">
          <span class="min-w-0 flex-1 break-words">
            <span class="block" lang="en">{{ option.text }}</span>
            <span
              v-if="option.caption"
              class="mt-0.5 block text-[13px] leading-[18px] font-normal first-letter:uppercase"
              :data-testid="`reveal-caption-${option.id}`"
            >
              {{ t(option.caption === 'your' ? 'practice.reveal.your_option' : 'practice.reveal.right_option') }}
            </span>
          </span>
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
