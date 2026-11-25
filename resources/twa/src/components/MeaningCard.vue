<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import ChoiceFeedback from '@/components/ChoiceFeedback.vue'
import QuizOption from '@/components/QuizOption.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useChoice } from '@/composables/useChoice'
import { useAutoSpeak } from '@/composables/useAutoSpeak'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * The meaning exercise (docs/06 §12.1, rung 1): the word is shown — with its sound and transcription, as on a flash card
 * before the reveal — and the learner picks its translation among four. The card carries no translation, example or hooks
 * (they give the right option away, docs/06 §12.2): the pick is emitted (`pick`) and saved at once, the SERVER grades it,
 * and `result` brings the verdict with the rest of the screen (`result.revealed`). `advance` says when the next card may
 * appear: a right pick moves on by itself after a short pause; a wrong one stays on screen (the right option and the
 * example are shown) until "Next" (docs/14 N6). The same `useChoice` as the reverse and audio cards. After the verdict the word is read aloud by itself
 * (auto-speak, a setting of the learner).
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

const { haptic, notificationHaptic, selectionHaptic } = useTelegram()
const { speak } = useTts()
const { speakWord } = useAutoSpeak()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const word = computed((): string => props.card.prompt?.front || props.card.word)

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
    translation: r.translation,
    example: r.example,
    example_translation: r.example_translation ?? null,
    example_audio_url: r.example_audio_url ?? null,
    hook: r.hook,
    note: r.note,
    confused_with: r.confused_with,
  }
})

/** The server has answered: the screen of the answer (Feedback) is on. */
const answered = computed((): boolean => isCorrect.value !== null)

/** "Next" was pressed while the grade is still on its way: the button waits for the server. */
const waiting = computed((): boolean => confirmed.value && props.disabled === true)

function onSpeak(): void {
  selectionHaptic()
  void speak(word.value, 'en-US', props.card.audio_url)
}

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  pick(index)
  haptic('medium')
}

// The verdict arrives with the server's answer: the haptic follows it.
// The word is read aloud once it is checked (the answer is out, the word is no secret), after the tone of the verdict.
watch(isCorrect, (right) => {
  if (right === null) return
  notificationHaptic(right ? 'success' : 'error')
  speakWord(word.value, props.card.audio_url, true)
})

function onContinue(): void {
  haptic('light')
  confirm()
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="meaning-card">
    <div class="flex flex-col px-5 pb-4">
      <!--
        The question (docs/design/2026-11-25-redesign Question): the task in words, the word large on the white screen, its
        transcription and the round speaker beside it. Once the answer is in (Feedback) the word steps down a size, so the options
        stay above the answer panel.
      -->
      <div
        class="flex flex-col items-center text-center"
        :class="answered ? 'mt-[22px] gap-1.5 short:mt-3 short:gap-1' : 'mt-[30px] gap-2 short:mt-4 short:gap-1.5'"
      >
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ t('exercise.meaning.title') }}</p>
        <h2
          class="max-w-full font-extrabold break-words"
          :class="answered ? 'text-[40px] leading-[46px] tracking-[-0.8px] short:text-[34px] short:leading-10' : 'text-[52px] leading-[58px] tracking-[-1px] short:text-[40px] short:leading-[46px]'"
          lang="en"
          data-testid="word"
        >
          {{ word }}
        </h2>
        <div class="flex items-center gap-2.5">
          <span v-if="card.transcription" class="font-ipa text-base leading-[22px] text-muted" data-testid="transcription">{{ card.transcription }}</span>
          <button
            type="button"
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent active:bg-fill"
            :aria-label="t('card.speak')"
            data-testid="speak-btn"
            @click="onSpeak"
          >
            <AppIcon name="speaker-wave" :size="22" />
          </button>
        </div>
      </div>

      <!-- The translations: long ones wrap inside the option (QuizOption breaks words), nothing is clipped at 320 px. -->
      <div
        class="flex flex-col gap-2.5 short:gap-2"
        :class="answered ? 'mt-[22px] short:mt-3' : 'mt-[34px] short:mt-4'"
        role="radiogroup"
        :aria-label="t('exercise.meaning.title')"
      >
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

    <ChoiceFeedback
      v-if="isCorrect !== null"
      :word="word"
      :meaning="shown.translation"
      :card="shown"
      :correct="isCorrect"
      :awaiting-confirm="awaitingConfirm"
      :waiting="waiting"
      :miss-note="missNote"
      @continue="onContinue"
    />
  </div>
</template>
