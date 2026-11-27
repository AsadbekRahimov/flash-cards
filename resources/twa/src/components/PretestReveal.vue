<script setup lang="ts">
import { computed, onMounted } from 'vue'
import type { Card, Graded } from '@/api/types'
import LessonWord from '@/components/LessonWord.vue'
import PhraseLabels from '@/components/PhraseLabels.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What follows a pretest pick (docs/06 §12.7): the word with its meaning (`graded.expected`). A right pick — "you know
 * this word already" (it graduated as known); a wrong one or "I don't know" — the first meeting with the word, as the
 * open new card would have been. "Next" goes on to the card the review answered with. A phrase card (`card.phrase`): the phrase takes
 * a size by its length, its labels are under it, and the lines say «фраза».
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

const phrase = computed(() => props.card.phrase ?? null)
const revealText = computed((): string => {
  if (props.verdict.correct) return t(phrase.value ? 'exercise.pretest.known_phrase' : 'exercise.pretest.known')
  return t(phrase.value ? 'exercise.pretest.learn_phrase' : 'exercise.pretest.learn')
})

// The pick is checked: the word is read aloud by itself (auto-speak), after the tone of the verdict.
onMounted(() => speakWord(props.card.word, props.card.audio_url, true))

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="pretest-reveal" :data-kind="verdict.correct ? 'known' : 'learn'">
    <!-- The word as the card of the kit shows it (F1): the word in Word/XL, the transcription, the meaning on the sunken paper. -->
    <article class="mt-5 flex flex-col items-center gap-2 px-5 pb-4 text-center short:mt-3 short:gap-1.5">
      <span
        class="flex size-12 items-center justify-center rounded-full"
        :class="verdict.correct ? 'bg-success-soft text-success-ink' : 'bg-sunken text-body'"
        data-testid="reveal-icon"
      >
        <AppIcon :name="verdict.correct ? 'check' : 'speaker-wave'" :size="24" />
      </span>
      <p class="t-body-m" :class="verdict.correct ? 'text-success-ink' : 'text-muted'" data-testid="reveal-text">
        {{ revealText }}
      </p>
      <LessonWord :text="card.word" :phrase="phrase !== null" lang="en" data-testid="word" />
      <PhraseLabels v-if="phrase" :phrase="phrase" />
      <p v-if="card.transcription" class="font-ipa text-[17px] leading-6 text-muted" data-testid="transcription">{{ card.transcription }}</p>
      <p class="t-display-m mt-3 w-full rounded-lg bg-sunken px-4 py-4 break-words short:mt-1" data-testid="translation">{{ verdict.expected }}</p>
    </article>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
        <AppIcon name="arrow-right" :size="20" />
      </button>
    </StickyActions>
  </div>
</template>
