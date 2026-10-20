<script setup lang="ts">
import { computed, onMounted, toRef } from 'vue'
import type { Card, Quality } from '@/api/types'
import QuizOption from '@/components/QuizOption.vue'
import { useChoice } from '@/composables/useChoice'
import { useTelegram } from '@/composables/useTelegram'
import { useTts } from '@/composables/useTts'
import { useI18n } from '@/i18n'

/**
 * Audio exercise (docs/13 §2.5): the word is only heard (`card.audio_url`, else the TTS fallback),
 * the learner picks its translation among four. The word itself is shown only after the pick.
 * Graded here — the right answer is `card.translation` — and sent as `quality`.
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'grade', quality: Quality): void
}>()

const { haptic, notificationHaptic, selectionHaptic } = useTelegram()
const { speak } = useTts()
const { t } = useI18n()

const options = computed((): string[] => props.card.prompt?.options ?? [])

const { picked, stateOf, isCorrect, pick } = useChoice(options, toRef(props.card, 'translation'), (quality) => {
  if (props.disabled) return
  emit('grade', quality)
})

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
  notificationHaptic(isCorrect.value ? 'success' : 'error')
  haptic('medium')
}

// Autoplay: a tap opened the training, so the browser allows the sound; a blocked play is silent.
onMounted(play)
</script>

<template>
  <article class="card flex flex-col gap-4" data-testid="audio-card">
    <header class="text-sm text-tg-hint">{{ t('exercise.audio.title') }}</header>

    <div class="text-center py-4">
      <button
        type="button"
        class="text-5xl"
        :aria-label="t('exercise.audio.play')"
        data-testid="play-btn"
        @click="onPlay"
      >
        🔊
      </button>
      <h2 v-if="picked !== null" class="text-3xl font-bold break-words mt-3" data-testid="word">{{ card.word }}</h2>
      <p v-if="picked !== null && card.transcription" class="text-tg-hint mt-1" data-testid="transcription">
        {{ card.transcription }}
      </p>
    </div>

    <div class="flex flex-col gap-2" role="group" :aria-label="t('exercise.audio.title')">
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
  </article>
</template>
