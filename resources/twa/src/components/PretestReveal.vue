<script setup lang="ts">
import { computed, onMounted } from 'vue'
import type { Card, Graded } from '@/api/types'
import PhraseLabels from '@/components/PhraseLabels.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { phraseHeadingClass } from '@/composables/phraseText'
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
    <article class="mt-[30px] flex flex-col items-center gap-2 px-5 pb-4 text-center short:mt-4 short:gap-1.5">
      <span
        class="flex size-12 items-center justify-center rounded-full"
        :class="verdict.correct ? 'bg-success-soft text-success-ink' : 'bg-accent-soft text-accent-ink'"
        data-testid="reveal-icon"
      >
        <AppIcon :name="verdict.correct ? 'check' : 'speaker-wave'" solid :size="24" />
      </span>
      <p class="text-base leading-[22px]" :class="verdict.correct ? 'text-success-ink' : 'text-muted'" data-testid="reveal-text">
        {{ revealText }}
      </p>
      <h2
        v-if="phrase"
        class="max-w-full font-extrabold text-balance break-words"
        :class="phraseHeadingClass(card.word)"
        lang="en"
        data-testid="word"
      >
        {{ card.word }}
      </h2>
      <h2
        v-else
        class="max-w-full text-[52px] leading-[58px] font-extrabold tracking-[-1px] break-words short:text-[40px] short:leading-[46px]"
        lang="en"
        data-testid="word"
      >
        {{ card.word }}
      </h2>
      <PhraseLabels v-if="phrase" :phrase="phrase" />
      <p v-if="card.transcription" class="font-ipa text-base leading-[22px] text-muted" data-testid="transcription">{{ card.transcription }}</p>
      <hr class="mt-3 w-full border-divider short:mt-1" />
      <p class="pt-3 text-2xl leading-[30px] font-bold break-words short:pt-1" data-testid="translation">{{ verdict.expected }}</p>
    </article>

    <StickyActions surface>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
