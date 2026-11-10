<script setup lang="ts">
import { computed } from 'vue'
import type { Card, Quality } from '@/api/types'
import CardHooks from '@/components/CardHooks.vue'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Reverse exercise (docs/13 §2.5): the translation is shown, the learner picks the English word
 * among four. Graded here — the right answer is `card.word` — and the grade (`grade`: 4 right, 2 wrong) is emitted at the
 * pick, so it is saved even if the learner leaves at once. `advance` says when the next card may appear: a right pick
 * moves on by itself after a short pause; a wrong one stays on screen (the right option and the example are shown)
 * until "Next" (docs/14 N6). If the grade has not come back from the server yet (`disabled`), "Next" waits for it.
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  /** The pick is final: its grade as the screen reads it (4 / 2) and the picked option — the server grades the option (docs/06 §12.2). */
  (e: 'grade', quality: Quality, picked: string): void
  (e: 'advance'): void
}>()

const { haptic, notificationHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const front = computed((): string => props.card.prompt?.front || props.card.translation)

const { picked, stateOf, isCorrect, awaitingConfirm, confirmed, pick, confirm } = useChoice(
  options,
  computed(() => props.card.word),
  {
    grade: (quality, picked) => emit('grade', quality, picked),
    advance: () => emit('advance'),
  },
)

/** "Next" was pressed while the grade is still on its way: the button waits for the server. */
const waiting = computed((): boolean => confirmed.value && props.disabled === true)

function onPick(index: number): void {
  if (props.disabled || picked.value !== null) return
  pick(index)
  if (isCorrect.value) {
    notificationHaptic('success')
    void speak(props.card.word, 'en-US', props.card.audio_url)
  } else {
    notificationHaptic('error')
  }
  haptic('medium')
}

function onContinue(): void {
  haptic('light')
  confirm()
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="reverse-card">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.reverse.title') }}</p>
        <h2 class="text-3xl leading-9 font-bold break-words" data-testid="front">{{ front }}</h2>
        <p v-if="card.translation_fallback" class="text-[13px] leading-[18px] text-tg-hint" data-testid="translation-fallback">
          {{ t('card.translation_fallback') }}
        </p>
      </article>

      <div class="flex flex-col gap-2.5" role="group" :aria-label="t('exercise.reverse.title')">
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

      <p v-if="picked !== null && card.example" class="px-2 text-center text-base leading-[22px] text-tg-subtitle italic" data-testid="example">
        {{ card.example }}
      </p>
      <!-- The method (docs/06 §12.3, §12.6): the hooks and the confusable pair once the answer is on screen. -->
      <CardHooks v-if="picked !== null" :card="card" class="px-2" />
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
