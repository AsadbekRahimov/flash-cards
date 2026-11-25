<script setup lang="ts">
import type { Card } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import ExampleAudioButton from '@/components/ExampleAudioButton.vue'
import StickyActions from '@/components/StickyActions.vue'
import { useI18n } from '@/i18n'

/**
 * The answer panel of a four-choice card (meaning / reverse / audio; docs/design/2026-11-25-redesign Feedback): a grey sheet at
 * the bottom of the white screen (`page`, rounded top, a hairline over it) with "word — meaning" and the verdict in words, the
 * example with its speaker and its translation, the hooks (docs/06 §12.3, §12.6) and, after a miss, the line about the word
 * coming back (`missNote`, the screen decides it) and "Next". A right pick has no button: the card moves on by itself.
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
  <StickyActions class="rounded-t-hero border-t border-hairline">
    <div class="flex flex-col gap-3 pt-1.5 short:gap-2" data-testid="feedback">
      <div class="flex flex-col gap-0.5">
        <div class="flex items-baseline justify-between gap-3">
          <p class="min-w-0 text-xl leading-[26px] font-extrabold break-words" data-testid="feedback-title">
            <span lang="en">{{ word }}</span> — {{ meaning }}
          </p>
          <span
            class="shrink-0 text-sm leading-5 font-semibold"
            :class="correct ? 'text-success-ink' : 'text-danger-ink'"
            data-testid="feedback-status"
          >
            {{ t(correct ? 'exercise.feedback.right' : 'exercise.feedback.wrong') }}
          </span>
        </div>
        <p v-if="transcription" class="font-ipa text-sm leading-5 text-muted" data-testid="transcription">{{ transcription }}</p>
      </div>
      <!-- The example and its speaker side by side (docs/26 §9.11): the sentence and its translation wrap beside the button. -->
      <div v-if="card.example" class="flex items-start gap-2.5">
        <div class="flex min-w-0 flex-1 flex-col gap-1">
          <p class="text-[17px] leading-[23px] break-words italic" lang="en" data-testid="example">{{ card.example }}</p>
          <p v-if="card.example_translation" class="text-sm leading-5 text-muted" data-testid="example-translation">
            {{ card.example_translation }}
          </p>
        </div>
        <ExampleAudioButton v-if="card.example_audio_url" :url="card.example_audio_url" />
      </div>
      <CardHooks :card="card" />
      <p v-if="!correct && missNote" class="text-[13px] leading-[18px] text-muted" data-testid="miss-note">{{ missNote }}</p>
      <button
        v-if="awaitingConfirm"
        type="button"
        class="btn btn-primary btn-lg btn-block"
        data-testid="continue-btn"
        :disabled="waiting"
        :aria-busy="waiting"
        @click="emit('continue')"
      >
        <span
          v-if="waiting"
          class="size-5 animate-spin rounded-full border-2 border-current/30 border-t-current motion-reduce:animate-none"
          aria-hidden="true"
          data-testid="continue-waiting"
        />
        {{ t('exercise.continue') }}
      </button>
    </div>
  </StickyActions>
</template>
