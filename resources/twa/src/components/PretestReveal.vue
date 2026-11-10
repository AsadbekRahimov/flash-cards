<script setup lang="ts">
import type { Card, Graded } from '@/api/types'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What follows a pretest pick (docs/06 §12.7): the word with its meaning (`graded.expected`). A right pick — "you know
 * this word already" (it graduated as known); a wrong one or "I don't know" — the first meeting with the word, as the
 * open new card would have been. "Next" goes on to the card the review answered with.
 */
defineProps<{
  card: Card
  verdict: Graded
}>()

const emit = defineEmits<{
  (e: 'continue'): void
}>()

const { haptic } = useTelegram()
const { t } = useI18n()

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="pretest-reveal" :data-kind="verdict.correct ? 'known' : 'learn'">
    <div class="flex flex-1 flex-col justify-center px-4 py-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span
          class="flex size-12 items-center justify-center rounded-full"
          :class="verdict.correct ? 'bg-success-soft text-success-ink' : 'bg-accent-soft text-accent-ink'"
          data-testid="reveal-icon"
        >
          <AppIcon :name="verdict.correct ? 'check' : 'speaker-wave'" solid :size="24" />
        </span>
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle" data-testid="reveal-text">
          {{ verdict.correct ? t('exercise.pretest.known') : t('exercise.pretest.learn') }}
        </p>
        <h2 class="text-4xl leading-10 font-bold break-words" data-testid="word">{{ card.word }}</h2>
        <p v-if="card.transcription" class="text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">{{ card.transcription }}</p>
        <hr class="w-full border-hairline" />
        <p class="text-2xl leading-8 font-semibold break-words" data-testid="translation">{{ verdict.expected }}</p>
      </article>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
