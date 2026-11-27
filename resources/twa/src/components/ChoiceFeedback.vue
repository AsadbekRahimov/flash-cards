<script setup lang="ts">
import type { Card } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import ExampleAudioButton from '@/components/ExampleAudioButton.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'

/**
 * The answer panel of a four-choice card (meaning / reverse / audio; kit Muhrla 05-urok-oshibka, "Feedback sheet"): a sheet of the soft
 * error / success colour at the bottom of the screen (rounded top, no dimming) with a round mark, "word — meaning" and the verdict in words, the
 * example with its speaker and its translation, the hooks (docs/06 §12.3, §12.6) and, after a miss, the line about the word
 * coming back (`missNote`, the screen decides it) and "Next". A right pick has no button: the card moves on by itself.
 *
 * A phrase card (`card.phrase`, docs/35 §3.3 d): the phrase and its meaning are two lines (both are long), and «Буквально: …» follows
 * them when the server sent the literal translation (it comes only with the answer, `revealed.phrase`).
 */
defineProps<{
  /** The English word (the right one, after the answer). */
  word: string
  /** What it means: the translation (meaning, audio) or the prompt the learner saw (reverse). */
  meaning: string
  /** The transcription under the title (reverse: the word was not on screen before). */
  transcription?: string | null
  /** The card as shown after the answer (`result.revealed` merged in): the example and the hooks. */
  card: Card
  correct: boolean
  /** A miss waits for "Next". */
  awaitingConfirm: boolean
  /** "Next" was pressed while the grade is still on its way: the button waits with a spinner. */
  waiting: boolean
  /** After a miss: whether the word comes back in this lesson / round, or a neutral line; null — no line. */
  missNote?: string | null
}>()

const emit = defineEmits<{
  (e: 'continue'): void
}>()

const { t } = useI18n()
</script>

<template>
  <!--
    The feedback sheet of the kit Muhrla (05-urok-oshibka): the soft error / success colour, the top corners 28, padding 20, sliding up
    over the bottom of the question without dimming it. A round mark (a cross / a check: never the colour alone), the word and its
    meaning in Title, the example with its speaker, the hooks as a card, and after a miss "Next".
  -->
  <StickyActions :tone="correct ? 'success' : 'danger'">
    <div class="flex flex-col gap-3 short:gap-2" data-testid="feedback" :data-correct="correct ? 'true' : 'false'">
      <div class="flex items-start gap-3">
        <span
          class="option-mark flex size-7 shrink-0 items-center justify-center rounded-full text-on-primary"
          :class="correct ? 'bg-success' : 'bg-danger'"
          aria-hidden="true"
          data-testid="feedback-mark"
        >
          <AppIcon :name="correct ? 'check' : 'x-mark'" solid :size="18" />
        </span>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
          <div class="flex items-baseline justify-between gap-3">
            <p
              v-if="card.phrase"
              class="flex min-w-0 flex-col gap-0.5 break-words"
              :class="correct ? 'text-success-ink' : 'text-danger-ink'"
              data-testid="feedback-title"
            >
              <span class="t-title-m" lang="en" data-testid="feedback-phrase">{{ word }}</span>
              <span class="t-body-m-strong text-body" data-testid="feedback-meaning">{{ meaning }}</span>
            </p>
            <p v-else class="t-title-m min-w-0 break-words" :class="correct ? 'text-success-ink' : 'text-danger-ink'" data-testid="feedback-title">
              <span lang="en">{{ word }}</span> — {{ meaning }}
            </p>
            <span
              class="t-label-s shrink-0"
              :class="correct ? 'text-success-ink' : 'text-danger-ink'"
              data-testid="feedback-status"
            >
              {{ t(correct ? 'exercise.feedback.right' : 'exercise.feedback.wrong') }}
            </span>
          </div>
          <p v-if="transcription" class="font-ipa text-sm leading-5 text-muted" data-testid="transcription">{{ transcription }}</p>
        </div>
      </div>
      <p v-if="card.phrase?.literal" class="t-body-m break-words" data-testid="phrase-literal">
        <span class="text-muted">{{ t('phrase.literal') }}:</span>
        {{ card.phrase.literal }}
      </p>
      <!-- The example and its speaker side by side (docs/26 §9.11): the sentence and its translation wrap beside the button. -->
      <div v-if="card.example" class="flex items-center gap-3">
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
          <p class="text-[17px] leading-6 break-words" lang="en" data-testid="example">{{ card.example }}</p>
          <p v-if="card.example_translation" class="t-label-s break-words text-muted" data-testid="example-translation">
            {{ card.example_translation }}
          </p>
        </div>
        <ExampleAudioButton v-if="card.example_audio_url" :url="card.example_audio_url" />
      </div>
      <CardHooks :card="card" />
      <p v-if="!correct && missNote" class="t-label-s text-muted" data-testid="miss-note">{{ missNote }}</p>
      <button
        v-if="awaitingConfirm"
        type="button"
        class="btn btn-primary btn-block mt-1"
        data-testid="continue-btn"
        :disabled="waiting"
        :aria-busy="waiting"
        @click="emit('continue')"
      >
        <template v-if="waiting">
          <span class="btn-dots" aria-hidden="true" data-testid="continue-waiting"><span /><span /><span /></span>
          <span class="sr-only">{{ t('exercise.continue') }}</span>
        </template>
        <template v-else>
          {{ t('exercise.continue') }}
          <AppIcon name="arrow-right" :size="20" />
        </template>
      </button>
    </div>
  </StickyActions>
</template>
