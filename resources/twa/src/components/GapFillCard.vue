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
  /** The "Pro" chip of the header: the exercise is a feature of Pro and this learner has Pro (the screen decides). No text, no action. */
  proChip?: boolean
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
    <!-- The question (docs/design/2026-11-25-redesign Question): the sentence with the gap on the white screen; the field and the buttons are at the bottom. -->
    <article class="mt-[30px] flex flex-col items-center gap-2 px-5 pb-4 text-center short:mt-4 short:gap-1.5">
      <span v-if="card.is_leech || proChip" class="flex gap-2">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <span v-if="proChip" class="chip chip-accent" data-testid="pro-chip">{{ t('profile.chip.pro') }}</span>
      </span>
      <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ t('exercise.gap_fill.title') }}</p>
      <p class="max-w-full text-[26px] leading-[34px] font-bold break-words short:text-[22px] short:leading-7" lang="en" data-testid="masked-example">{{ masked }}</p>
      <p class="text-base leading-[22px] text-muted" data-testid="hint">{{ hint }}</p>
    </article>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </div>
</template>
