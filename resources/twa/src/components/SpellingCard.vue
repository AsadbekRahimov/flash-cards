<script setup lang="ts">
import { computed } from 'vue'
import type { Card } from '@/api/types'
import AnswerInput from '@/components/AnswerInput.vue'
import { useI18n } from '@/i18n'

/**
 * Spelling exercise (docs/13 §2.5, Pro): the translation is shown, the learner types the English
 * word. The server grades the text (`POST /review {answer}`); the example is not shown — it
 * would give the word away.
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'answer', text: string): void
}>()

const { t } = useI18n()

const front = computed((): string => props.card.prompt?.front || props.card.translation)
const answerLength = computed((): number | null => props.card.prompt?.answer_length ?? null)
</script>

<template>
  <article class="card flex flex-col gap-4" data-testid="spelling-card">
    <header class="text-sm text-tg-hint">{{ t('exercise.spelling.title') }}</header>

    <div class="text-center py-4">
      <h2 class="text-3xl font-bold break-words" data-testid="front">{{ front }}</h2>
      <p v-if="card.translation_fallback" class="text-tg-hint text-xs mt-1" data-testid="translation-fallback">
        {{ t('card.translation_fallback') }}
      </p>
    </div>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </article>
</template>
