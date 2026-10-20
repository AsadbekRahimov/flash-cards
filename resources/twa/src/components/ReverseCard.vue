<script setup lang="ts">
import { computed, toRef } from 'vue'
import type { Card, Quality } from '@/api/types'
import QuizOption from '@/components/QuizOption.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Reverse exercise (docs/13 §2.5): the translation is shown, the learner picks the English word
 * among four. Graded here — the right answer is `card.word` — and sent as `quality`.
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

const { picked, stateOf, isCorrect, pick } = useChoice(options, toRef(props.card, 'word'), (quality) => {
  if (props.disabled) return
  emit('grade', quality)
})

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
</script>

<template>
  <article class="card flex flex-col gap-4" data-testid="reverse-card">
    <header class="text-sm text-tg-hint">{{ t('exercise.reverse.title') }}</header>

    <div class="text-center py-4">
      <h2 class="text-3xl font-bold break-words" data-testid="front">{{ front }}</h2>
      <p v-if="card.translation_fallback" class="text-tg-hint text-xs mt-1" data-testid="translation-fallback">
        {{ t('card.translation_fallback') }}
      </p>
    </div>

    <div class="flex flex-col gap-2" role="group" :aria-label="t('exercise.reverse.title')">
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

    <p v-if="picked !== null && card.example" class="text-tg-hint italic text-center" data-testid="example">
      {{ card.example }}
    </p>
  </article>
</template>
