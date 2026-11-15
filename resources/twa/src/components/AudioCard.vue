<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useChoice } from '@/composables/useChoice'
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

const { haptic, notificationHaptic, selectionHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()
const { show: sayAloud } = useSayAloudHint()

const options = computed((): string[] => props.card.prompt?.options ?? [])

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
    transcription: r.transcription,
    audio_url: r.audio_url ?? props.card.audio_url,
    hook: r.hook,
    note: r.note,
    confused_with: r.confused_with,
  }
})

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
  <div class="flex flex-1 flex-col" data-testid="audio-card">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.audio.title') }}</p>
        <button
          type="button"
          class="flex size-[72px] shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent transition-transform duration-150 active:scale-95"
          :aria-label="t('exercise.audio.play')"
          data-testid="play-btn"
          @click="onPlay"
        >
          <AppIcon name="speaker-wave" :size="36" />
        </button>
        <!-- The slot keeps its height: the word that replaces the hint after the pick must not push the options down. -->
        <div class="flex min-h-[64px] flex-col items-center justify-center">
          <template v-if="isCorrect !== null">
            <h2 class="text-3xl leading-9 font-bold break-words" data-testid="word">{{ shown.word }}</h2>
            <p v-if="shown.transcription" class="mt-0.5 text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">
              {{ shown.transcription }}
            </p>
          </template>
          <p v-else class="text-[13px] leading-[18px] text-tg-hint" data-testid="replay-hint">{{ t('exercise.audio.replay_hint') }}</p>
          <p v-if="picked === null && sayAloud" class="mt-1 text-[13px] leading-[18px] text-tg-hint" data-testid="say-aloud">{{ t('card.say_aloud') }}</p>
        </div>
      </article>

      <div class="flex flex-col gap-2.5" role="group" :aria-label="t('exercise.audio.title')">
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

      <p v-if="isCorrect !== null && shown.example" class="px-2 text-center text-base leading-[22px] text-tg-subtitle italic" data-testid="example">
        {{ shown.example }}
      </p>
      <!-- The method (docs/06 §12.3, §12.6): the hooks and the confusable pair once the answer is on screen. -->
      <CardHooks v-if="isCorrect !== null" :card="shown" class="px-2" />
    </div>

    <StickyActions v-if="awaitingConfirm">
      <button
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
    </StickyActions>
  </div>
</template>
