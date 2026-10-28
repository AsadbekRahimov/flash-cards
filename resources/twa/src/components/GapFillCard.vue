<script setup lang="ts">
import { computed } from 'vue'
import type { Card } from '@/api/types'
import AnswerInput from '@/components/AnswerInput.vue'
import { useI18n } from '@/i18n'

/**
 * Gap-fill exercise (docs/13 §2.5, Pro): the example with the word's form masked (`____`) and
 * the translation as a hint; the learner types the missing form. The server grades the text.
 */
const props = defineProps<{
  card: Card
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'answer', text: string): void
}>()

const { t } = useI18n()

const masked = computed((): string => props.card.prompt?.masked_example ?? '')
const hint = computed((): string => props.card.prompt?.front || props.card.translation)
const answerLength = computed((): number | null => props.card.prompt?.answer_length ?? null)
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="gap-fill-card">
    <div class="flex flex-1 flex-col px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ t('exercise.gap_fill.title') }}</p>
        <p class="text-[22px] leading-8 font-semibold break-words" data-testid="masked-example">{{ masked }}</p>
        <p class="text-[15px] leading-5 text-tg-hint" data-testid="hint">{{ hint }}</p>
      </article>
    </div>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </div>
</template>
