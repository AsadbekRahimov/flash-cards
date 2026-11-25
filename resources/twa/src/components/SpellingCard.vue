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
  /** The "Pro" chip of the header: the exercise is a feature of Pro and this learner has Pro (the screen decides). No text, no action. */
  proChip?: boolean
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
    <!-- The question (docs/design/2026-11-25-redesign Question): the translation large on the white screen; the field and the buttons are at the bottom. -->
    <article class="mt-[30px] flex flex-col items-center gap-2 px-5 pb-4 text-center short:mt-4 short:gap-1.5">
      <span v-if="card.is_leech || proChip" class="flex gap-2">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <span v-if="proChip" class="chip chip-accent" data-testid="pro-chip">{{ t('profile.chip.pro') }}</span>
      </span>
      <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ t('exercise.spelling.title') }}</p>
      <h2 class="max-w-full text-[40px] leading-[46px] font-extrabold tracking-[-0.8px] break-words short:text-[32px] short:leading-[38px]" data-testid="front">
        {{ front }}
      </h2>
      <p v-if="card.translation_fallback" class="text-[13px] leading-[18px] text-muted" data-testid="translation-fallback">
        {{ t('card.translation_fallback') }}
      </p>
    </article>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </div>
</template>
