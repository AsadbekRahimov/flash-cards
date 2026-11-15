<script setup lang="ts">
import { computed, watch } from 'vue'
import type { Card, ChoiceResult } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * The meaning exercise (docs/06 §12.1, rung 1): the word is shown — with its sound and transcription, as on a flash card
 * before the reveal — and the learner picks its translation among four. The card carries no translation, example or hooks
 * (they give the right option away, docs/06 §12.2): the pick is emitted (`pick`) and saved at once, the SERVER grades it,
 * and `result` brings the verdict with the rest of the screen (`result.revealed`). `advance` says when the next card may
 * appear: a right pick moves on by itself after a short pause; a wrong one stays on screen (the right option and the
 * example are shown) until "Next" (docs/14 N6). The same `useChoice` as the reverse and audio cards.
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
    hook: r.hook,
    note: r.note,
    confused_with: r.confused_with,
  }
})

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
watch(isCorrect, (right) => {
  if (right !== null) notificationHaptic(right ? 'success' : 'error')
})

function onContinue(): void {
  haptic('light')
  confirm()
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="meaning-card">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.meaning.title') }}</p>
        <div class="flex max-w-full items-center gap-3">
          <h2 class="min-w-0 text-3xl leading-9 font-bold break-words" data-testid="word">{{ word }}</h2>
          <button
            type="button"
            class="btn btn-tinted size-11 min-h-0 shrink-0 rounded-full p-0"
            :aria-label="t('card.speak')"
            data-testid="speak-btn"
            @click="onSpeak"
          >
            <AppIcon name="speaker-wave" :size="22" />
          </button>
        </div>
        <p v-if="card.transcription" class="text-[17px] leading-[22px] text-tg-hint" data-testid="transcription">
          {{ card.transcription }}
        </p>
      </article>

      <!-- The translations: long ones wrap inside the option (QuizOption breaks words), nothing is clipped at 320 px. -->
      <div class="flex flex-col gap-2.5" role="group" :aria-label="t('exercise.meaning.title')">
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
