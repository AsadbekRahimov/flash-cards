<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import ChoiceFeedback from '@/components/ChoiceFeedback.vue'
import PhraseLabels from '@/components/PhraseLabels.vue'
import QuizOption from '@/components/QuizOption.vue'
import { useChoice } from '@/composables/useChoice'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { phraseHeadingClass } from '@/composables/phraseText'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * Reverse exercise (docs/13 §2.5): the translation is shown, the learner picks the English word among four. The card
 * carries no word, example, transcription or audio (they give the right option away, docs/06 §12.2): the pick is emitted
 * (`pick`) and saved at once, the SERVER grades it, and `result` brings the verdict with the word and the rest of the
 * screen (`result.revealed`). `advance` says when the next card may appear: a right pick moves on by itself after a short
 * pause; a wrong one stays on screen (the right option and the example are shown) until "Next" (docs/14 N6).
 *
 * A phrase card (`card.phrase`, docs/35 §3.3 d): the translation of the phrase takes a size by its length, the labels (register, UK / US,
 * kind - none of them gives the phrase away) are under it, the options are whole English phrases on several lines, and the answer panel
 * adds «Буквально: …» from `result.revealed.phrase`.
 */
const props = defineProps<{
  card: Card
  /** The server's word on the pick; null until it arrives. */
  result?: ChoiceResult | null
  disabled?: boolean
  /** The line under the example after a miss: the word comes back in this lesson / round, or a neutral one (the screen decides). */
  missNote?: string | null
}>()

const emit = defineEmits<{
  /** The pick is final: the picked option, which the server grades (docs/06 §12.2). */
  (e: 'pick', picked: string): void
  (e: 'advance'): void
}>()

const { haptic, notificationHaptic } = useTelegram()
const { speakWord } = useAutoSpeak()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const front = computed((): string => props.card.prompt?.front || props.card.translation)
const phrase = computed(() => props.card.phrase ?? null)
const title = computed((): string => t(phrase.value ? 'exercise.reverse.title_phrase' : 'exercise.reverse.title'))

const { picked, stateOf, isCorrect, awaitingConfirm, confirmed, pick, confirm } = useChoice(
  options,
  computed(() => props.result ?? null),
  {
    pick: (picked) => emit('pick', picked),
    advance: () => emit('advance'),
  },
)

/** The card as the screen after the answer shows it: what the server held back is in `result.revealed`. */
const shown = computed((): Card => {
  const r = props.result?.revealed
  if (!r) return props.card
  return {
    ...props.card,
    word: r.word,
    example: r.example,
    example_translation: r.example_translation ?? null,
    example_audio_url: r.example_audio_url ?? null,
    transcription: r.transcription,
    audio_url: r.audio_url,
    hook: r.hook,
    note: r.note,
    confused_with: r.confused_with,
    phrase: r.phrase ?? props.card.phrase ?? null,
  }
})

/** The server has answered: the screen of the answer (Feedback) is on. */
const answered = computed((): boolean => isCorrect.value !== null)

/** "Next" was pressed while the grade is still on its way: the button waits for the server. */
const waiting = computed((): boolean => confirmed.value && props.disabled === true)

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  pick(index)
  haptic('medium')
}

// The verdict arrives with the server's answer: the haptic and the word's sound follow it (right or wrong: after a miss the
// learner hears the word he did not find), after the tone of the verdict. Never before: the word is the answer.
watch(isCorrect, (right) => {
  if (right === null) return
  notificationHaptic(right ? 'success' : 'error')
  speakWord(shown.value.word, shown.value.audio_url, true)
})

function onContinue(): void {
  haptic('light')
  confirm()
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="reverse-card">
    <div class="flex flex-col px-5 pb-4">
      <!-- The question (docs/design/2026-11-25-redesign Question): the task in words and the translation large on the white screen. -->
      <div
        class="flex flex-col items-center text-center"
        :class="answered ? 'mt-[22px] gap-1.5 short:mt-3 short:gap-1' : 'mt-[30px] gap-2 short:mt-4 short:gap-1.5'"
      >
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ title }}</p>
        <h2
          v-if="phrase"
          class="max-w-full font-extrabold text-balance break-words"
          :class="phraseHeadingClass(front, answered)"
          data-testid="front"
        >
          {{ front }}
        </h2>
        <h2
          v-else
          class="max-w-full font-extrabold break-words"
          :class="answered ? 'text-[32px] leading-[38px] tracking-[-0.6px] short:text-[28px] short:leading-[34px]' : 'text-[40px] leading-[46px] tracking-[-0.8px] short:text-[32px] short:leading-[38px]'"
          data-testid="front"
        >
          {{ front }}
        </h2>
        <PhraseLabels v-if="phrase" :phrase="phrase" />
        <p v-if="card.translation_fallback" class="text-[13px] leading-[18px] text-muted" data-testid="translation-fallback">
          {{ t('card.translation_fallback') }}
        </p>
      </div>

      <div
        class="flex flex-col gap-2.5 short:gap-2"
        :class="answered ? 'mt-[22px] short:mt-3' : 'mt-[34px] short:mt-4'"
        role="radiogroup"
        :aria-label="title"
      >
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="stateOf(index)"
          :disabled="disabled || picked !== null"
          :long="phrase !== null"
          :lang="phrase ? 'en' : undefined"
          @pick="onPick"
        />
      </div>
    </div>

    <!-- The word (the right option above) comes with its transcription: it was not on screen before the answer. -->
    <ChoiceFeedback
      v-if="isCorrect !== null"
      :word="shown.word"
      :meaning="front"
      :transcription="shown.transcription"
      :card="shown"
      :correct="isCorrect"
      :awaiting-confirm="awaitingConfirm"
      :waiting="waiting"
      :miss-note="missNote"
      @continue="onContinue"
    />
  </div>
</template>
