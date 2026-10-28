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
  <div class="flex flex-1 flex-col" data-testid="spelling-card">
    <div class="flex flex-1 flex-col px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.spelling.title') }}</p>
        <h2 class="text-3xl leading-9 font-bold break-words" data-testid="front">{{ front }}</h2>
        <p v-if="card.translation_fallback" class="text-[13px] leading-[18px] text-tg-hint" data-testid="translation-fallback">
          {{ t('card.translation_fallback') }}
        </p>
      </article>
    </div>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </div>
</template>
