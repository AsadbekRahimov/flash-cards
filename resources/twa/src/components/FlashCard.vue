<script setup lang="ts">
import { computed } from 'vue'
import type { Card, Quality } from '@/api/types'
import { useTts } from '@/composables/useTts'
import { useTelegram } from '@/composables/useTelegram'

const props = defineProps<{
  card: Card
  showTranslation: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'reveal'): void
  (e: 'grade', quality: Quality): void
}>()

const { speak } = useTts()
const { haptic, selectionHaptic } = useTelegram()

const kindLabel = computed(() => (props.card.card_kind === 'new' ? 'Новое слово' : 'Повторение'))

function onSpeak(): void {
  selectionHaptic()
  void speak(props.card.word)
}

function onReveal(): void {
  haptic('light')
  emit('reveal')
}

function onGrade(q: Quality): void {
  if (props.disabled) return
  haptic('medium')
  emit('grade', q)
}
</script>

<template>
  <article class="card flex flex-col gap-4" data-testid="flashcard">
    <header class="flex items-center justify-between text-sm text-tg-hint">
      <span>{{ kindLabel }}</span>
      <button
        type="button"
        class="text-tg-link text-xl"
        aria-label="Произнести"
        @click="onSpeak"
      >
        🔊
      </button>
    </header>

    <div class="text-center py-4">
      <h2 class="text-4xl font-bold break-words" data-testid="word">{{ card.word }}</h2>
      <p v-if="card.transcription" class="text-tg-hint mt-2 text-lg" data-testid="transcription">
        {{ card.transcription }}
      </p>
    </div>

    <div v-if="!showTranslation" class="mt-auto">
      <button
        type="button"
        class="btn btn-primary w-full text-lg"
        data-testid="reveal-btn"
        :disabled="disabled"
        @click="onReveal"
      >
        Показать перевод
      </button>
    </div>

    <div v-else class="flex flex-col gap-4 mt-2">
      <div class="text-center">
        <p class="text-2xl font-semibold" data-testid="translation">{{ card.translation }}</p>
        <p v-if="card.example" class="text-tg-hint italic mt-2" data-testid="example">
          {{ card.example }}
        </p>
      </div>

      <div class="grid grid-cols-3 gap-2" role="group" aria-label="Оценка">
        <button
          type="button"
          class="btn btn-secondary flex-col py-4"
          data-testid="grade-hard"
          :disabled="disabled"
          @click="onGrade(2)"
        >
          <span class="text-2xl">😰</span>
          <span class="text-xs mt-1">Трудно</span>
        </button>
        <button
          type="button"
          class="btn btn-secondary flex-col py-4"
          data-testid="grade-medium"
          :disabled="disabled"
          @click="onGrade(3)"
        >
          <span class="text-2xl">🙂</span>
          <span class="text-xs mt-1">Средне</span>
        </button>
        <button
          type="button"
          class="btn btn-secondary flex-col py-4"
          data-testid="grade-easy"
          :disabled="disabled"
          @click="onGrade(5)"
        >
          <span class="text-2xl">😎</span>
          <span class="text-xs mt-1">Легко</span>
        </button>
      </div>
    </div>
  </article>
</template>
