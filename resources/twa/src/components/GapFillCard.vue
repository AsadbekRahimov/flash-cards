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
  <article class="card flex flex-col gap-4" data-testid="gap-fill-card">
    <header class="text-sm text-tg-hint">{{ t('exercise.gap_fill.title') }}</header>

    <div class="text-center py-4 space-y-2">
      <p class="text-xl leading-relaxed break-words" data-testid="masked-example">{{ masked }}</p>
      <p class="text-tg-hint" data-testid="hint">{{ hint }}</p>
    </div>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </article>
</template>
