<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import ExampleAudioButton from '@/components/ExampleAudioButton.vue'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import { useChoice } from '@/composables/useChoice'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * Reverse exercise (docs/13 §2.5): the translation is shown, the learner picks the English word among four. The card
 * carries no word, example, transcription or audio (they give the right option away, docs/06 §12.2): the pick is emitted
 * (`pick`) and saved at once, the SERVER grades it, and `result` brings the verdict with the word and the rest of the
 * screen (`result.revealed`). `advance` says when the next card may appear: a right pick moves on by itself after a short
 * pause; a wrong one stays on screen (the right option and the example are shown) until "Next" (docs/14 N6).
 */
const props = defineProps<{
  card: Card
  /** The server's word on the pick; null until it arrives. */
  result?: ChoiceResult | null
  disabled?: boolean
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
  }
})

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
      <!-- The question (docs/design Question): the translation large on the white screen. -->
      <div class="mt-14 flex flex-col items-center gap-2.5 text-center short:mt-5 short:gap-1.5">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('exercise.reverse.title') }}</p>
        <h2 class="max-w-full text-4xl leading-[42px] font-semibold tracking-[-0.4px] break-words short:text-3xl short:leading-9" data-testid="front">
          {{ front }}
        </h2>
        <p v-if="card.translation_fallback" class="text-[13px] leading-[18px] text-muted" data-testid="translation-fallback">
          {{ t('card.translation_fallback') }}
        </p>
      </div>

      <div class="mt-11 flex flex-col gap-2.5 short:mt-4 short:gap-2" role="group" :aria-label="t('exercise.reverse.title')">
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="stateOf(index)"
          :disabled="disabled || picked !== null"
          @pick="onPick"
        />
      </div>
    </div>

    <!--
      The answer (docs/design Feedback): a grey panel at the bottom with the word (the right option above) and its transcription,
      the verdict in words, the example and its translation, the hooks (docs/06 §12.3, §12.6) and, after a miss, "Next".
    -->
    <StickyActions v-if="isCorrect !== null" class="border-t border-hairline">
      <div class="flex flex-col gap-3 pt-1.5 short:gap-2" data-testid="feedback">
        <div class="flex flex-col gap-0.5">
          <div class="flex items-baseline justify-between gap-3">
            <p class="min-w-0 text-lg leading-6 font-semibold break-words" data-testid="feedback-title">
              <span lang="en">{{ shown.word }}</span> — {{ front }}
            </p>
            <span
              class="shrink-0 text-[13px] leading-[18px]"
              :class="isCorrect ? 'text-success-ink' : 'text-danger-ink'"
              data-testid="feedback-status"
            >
              {{ t(isCorrect ? 'exercise.feedback.right' : 'exercise.feedback.wrong') }}
            </span>
          </div>
          <p v-if="shown.transcription" class="font-ipa text-sm leading-5 text-muted" data-testid="transcription">{{ shown.transcription }}</p>
        </div>
        <div v-if="shown.example" class="flex flex-col gap-1">
          <!-- The speaker of the example (docs/26 §9.11) on its first line; the sentence wraps beside it. -->
          <div class="flex items-start gap-1">
            <p class="min-w-0 flex-1 text-base leading-[22px] break-words italic" lang="en" data-testid="example">{{ shown.example }}</p>
            <ExampleAudioButton v-if="shown.example_audio_url" :url="shown.example_audio_url" class="-mt-[11px]" />
          </div>
          <p v-if="shown.example_translation" class="text-sm leading-5 text-muted" data-testid="example-translation">
            {{ shown.example_translation }}
          </p>
        </div>
        <CardHooks :card="shown" />
        <button
          v-if="awaitingConfirm"
          type="button"
          class="btn btn-primary btn-lg btn-block"
          data-testid="continue-btn"
          :disabled="waiting"
          :aria-busy="waiting"
          @click="onContinue"
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
  </div>
</template>
