<script setup lang="ts">
import { computed } from 'vue'
import type { Card, Quality } from '@/api/types'
import QuizOption from '@/components/QuizOption.vue'
import StickyActions from '@/components/StickyActions.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Reverse exercise (docs/13 §2.5): the translation is shown, the learner picks the English word
 * among four. Graded here — the right answer is `card.word` — and sent as `quality`.
 * A right pick moves on by itself; a wrong one stays on screen (the right option and the example are shown)
 * until "Next" (docs/14 N6).
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'grade', quality: Quality): void
}>()

const { haptic, notificationHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])
const front = computed((): string => props.card.prompt?.front || props.card.translation)

const { picked, stateOf, isCorrect, awaitingConfirm, pick, confirm } = useChoice(
  options,
  computed(() => props.card.word),
  (quality) => {
    if (props.disabled) return
    emit('grade', quality)
  },
)

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
    </div>

    <StickyActions v-if="awaitingConfirm">
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" :disabled="disabled" @click="onContinue">
        {{ t('exercise.continue') }}
      </button>
    </StickyActions>
  </div>
</template>
