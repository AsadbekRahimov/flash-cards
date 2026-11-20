<script setup lang="ts">
import { onMounted } from 'vue'
import type { Card, Graded } from '@/api/types'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What follows a pretest pick (docs/06 §12.7): the word with its meaning (`graded.expected`). A right pick — "you know
 * this word already" (it graduated as known); a wrong one or "I don't know" — the first meeting with the word, as the
 * open new card would have been. "Next" goes on to the card the review answered with.
 */
const props = defineProps<{
  card: Card
  verdict: Graded
}>()

const emit = defineEmits<{
  (e: 'continue'): void
}>()

const { haptic } = useTelegram()
const { t } = useI18n()
const { speakWord } = useAutoSpeak()

// The pick is checked: the word is read aloud by itself (auto-speak), after the tone of the verdict.
onMounted(() => speakWord(props.card.word, props.card.audio_url, true))

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="pretest-reveal" :data-kind="verdict.correct ? 'known' : 'learn'">
    <article class="mt-14 flex flex-col items-center gap-2.5 px-5 pb-4 text-center short:mt-5 short:gap-1.5">
      <span
        class="flex size-12 items-center justify-center rounded-full"
        :class="verdict.correct ? 'bg-success-soft text-success-ink' : 'bg-accent-soft text-accent-ink'"
        data-testid="reveal-icon"
      >
        <AppIcon :name="verdict.correct ? 'check' : 'speaker-wave'" solid :size="24" />
      </span>
      <p class="text-[15px] leading-5" :class="verdict.correct ? 'text-success-ink' : 'text-muted'" data-testid="reveal-text">
        {{ verdict.correct ? t('exercise.pretest.known') : t('exercise.pretest.learn') }}
      </p>
      <h2
        class="max-w-full text-[46px] leading-[52px] font-semibold tracking-[-0.6px] break-words short:text-[36px] short:leading-[42px]"
        lang="en"
        data-testid="word"
      >
        {{ card.word }}
      </h2>
      <p v-if="card.transcription" class="font-ipa text-[15px] leading-5 text-muted" data-testid="transcription">{{ card.transcription }}</p>
      <hr class="mt-3 w-full border-divider short:mt-1" />
      <p class="pt-3 text-2xl leading-[30px] font-semibold break-words short:pt-1" data-testid="translation">{{ verdict.expected }}</p>
    </article>

    <StickyActions surface>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
