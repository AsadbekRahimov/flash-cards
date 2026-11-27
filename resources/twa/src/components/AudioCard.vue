<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import ChoiceFeedback from '@/components/ChoiceFeedback.vue'
import PhraseLabels from '@/components/PhraseLabels.vue'
import LessonWord from '@/components/LessonWord.vue'
import QuizOption from '@/components/QuizOption.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useChoice } from '@/composables/useChoice'
import { useRevealInView } from '@/composables/useRevealInView'
import { useSayAloudHint } from '@/composables/useSayAloudHint'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Audio exercise (docs/13 §2.5): the word is only heard (`card.audio_url`, else the TTS fallback),
 * the learner picks its translation among four. The card carries no translation or example (they give the right option
 * away, docs/06 §12.2): the pick is emitted (`pick`) and saved at once, the SERVER grades it, and `result` brings the verdict
 * with the rest of the screen (`result.revealed`); the word is shown from then on. `advance` says when the next card may
 * appear: a right pick moves on by itself after a short pause; a wrong one stays on screen (the right option, the word and
 * the example are shown) until "Next" (docs/14 N6).
 *
 * A phrase card (`card.phrase`, docs/35 §3.3 d): the labels are under the speaker, the options (translations of phrases) are long, the
 * phrase shown after the answer takes a size by its length, and the answer panel adds «Буквально: …» from `result.revealed.phrase`.
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
const { t } = useI18n()
const { show: sayAloud } = useSayAloudHint()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const phrase = computed(() => props.card.phrase ?? null)

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
    translation: r.translation,
    example: r.example,
    example_translation: r.example_translation ?? null,
    example_audio_url: r.example_audio_url ?? null,
    transcription: r.transcription,
    audio_url: r.audio_url ?? props.card.audio_url,
    hook: r.hook,
    note: r.note,
    confused_with: r.confused_with,
    phrase: r.phrase ?? props.card.phrase ?? null,
  }
})

/** The card: a miss scrolls the right option into view above the feedback sheet. */
const root = ref<HTMLElement | null>(null)
useRevealInView(root, isCorrect)

/** The server has answered: the screen of the answer (Feedback) is on. */
const answered = computed((): boolean => isCorrect.value !== null)

/** "Next" was pressed while the grade is still on its way: the button waits for the server. */
const waiting = computed((): boolean => confirmed.value && props.disabled === true)

function play(): void {
  void speak(props.card.word, 'en-US', props.card.audio_url)
}

function onPlay(): void {
  selectionHaptic()
  play()
}

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  pick(index)
  haptic('medium')
}

// The verdict arrives with the server's answer: the haptic follows it.
watch(isCorrect, (right) => {
  if (right !== null) notificationHaptic(right ? 'success' : 'error')
})

function onContinue(): void {
  haptic('light')
  confirm()
}

// Autoplay: a tap opened the training, so the browser allows the sound; a blocked play is silent.
onMounted(play)
</script>

<template>
  <div ref="root" class="flex flex-1 flex-col" data-testid="audio-card">
    <div class="flex flex-col px-5 pb-4">
      <!--
        The question (kit Muhrla 04-urok-vopros, the language of the lesson): the task in words and only the sound - a big round speaker of
        the sunken paper; the word takes the place of the hint once the answer is in.
      -->
      <div class="flex flex-col items-center gap-2 text-center" :class="answered ? 'mt-3 short:mt-2' : 'mt-6 short:mt-3'">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="t-body-m text-muted" data-testid="exercise-title">{{ t('exercise.audio.title') }}</p>
        <button
          type="button"
          class="icon-btn size-[88px] short:size-16"
          :aria-label="t('exercise.audio.play')"
          data-testid="play-btn"
          @click="onPlay"
        >
          <AppIcon name="speaker-wave" :size="36" />
        </button>
        <!-- The slot keeps its height: the word that replaces the hint after the pick must not push the options down. -->
        <div class="flex min-h-[64px] flex-col items-center justify-center">
          <template v-if="isCorrect !== null">
            <LessonWord :text="shown.word" :phrase="phrase !== null" compact lang="en" data-testid="word" />
            <p v-if="shown.transcription" class="font-ipa text-[15px] leading-5 text-muted" data-testid="transcription">
              {{ shown.transcription }}
            </p>
          </template>
          <p v-else class="t-label-s text-muted" data-testid="replay-hint">{{ t('exercise.audio.replay_hint') }}</p>
          <PhraseLabels v-if="phrase" :phrase="phrase" class="mt-1.5" />
          <p v-if="picked === null && sayAloud" class="t-label-s mt-1 text-muted" data-testid="say-aloud">{{ t('card.say_aloud') }}</p>
        </div>
      </div>

      <div
        class="flex flex-col gap-2.5 short:gap-2"
        :class="answered ? 'mt-4 short:mt-2' : 'mt-6 short:mt-3'"
        role="radiogroup"
        :aria-label="t('exercise.audio.title')"
      >
        <QuizOption
          v-for="(label, index) in options"
          :key="index"
          :label="label"
          :index="index"
          :state="stateOf(index)"
          :disabled="disabled || picked !== null"
          :long="phrase !== null"
          @pick="onPick"
        />
      </div>
    </div>

    <ChoiceFeedback
      v-if="isCorrect !== null"
      :word="shown.word"
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
