<script setup lang="ts">
import { computed } from 'vue'
import type { Card } from '@/api/types'
import AnswerInput from '@/components/AnswerInput.vue'
import PhraseLabels from '@/components/PhraseLabels.vue'
import { useI18n } from '@/i18n'

/**
 * Gap-fill exercise (docs/13 §2.5, Pro): the example with the word's form masked (`____`) and
 * the translation as a hint; the learner types the missing form. The server grades the text.
 *
 * A phrase card (`card.phrase`, docs/06 §16): the gap is the key word of the phrase itself. The translation of the phrase is the question
 * and goes first, the phrase with its gap under it (wrapping by words), then the labels; the field, the hint of the length and the verdict
 * are the same as for a word.
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
const phrase = computed(() => props.card.phrase ?? null)
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="gap-fill-card">
    <!-- The question (docs/design/2026-11-25-redesign Question): the sentence with the gap on the white screen; the field and the buttons are at the bottom. -->
    <article class="mt-[30px] flex flex-col items-center gap-2 px-5 pb-4 text-center short:mt-4 short:gap-1.5">
      <span v-if="card.is_leech || proChip" class="flex gap-2">
        <span v-if="card.is_leech" class="chip chip-warning" data-testid="leech-chip">{{ t('card.leech') }}</span>
        <span v-if="proChip" class="chip chip-accent" data-testid="pro-chip">{{ t('profile.chip.pro') }}</span>
      </span>
      <template v-if="phrase">
        <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ t('exercise.gap_fill.title_phrase') }}</p>
        <p class="max-w-full text-lg leading-6 font-semibold text-balance break-words" data-testid="hint">{{ hint }}</p>
        <p class="mt-1 max-w-full text-[24px] leading-[30px] font-bold text-balance break-words short:text-[20px] short:leading-[26px]" lang="en" data-testid="masked-example">{{ masked }}</p>
        <PhraseLabels :phrase="phrase" class="mt-1" />
      </template>
      <template v-else>
        <p class="text-base leading-[22px] text-muted" data-testid="exercise-title">{{ t('exercise.gap_fill.title') }}</p>
        <p class="max-w-full text-[26px] leading-[34px] font-bold break-words short:text-[22px] short:leading-7" lang="en" data-testid="masked-example">{{ masked }}</p>
        <p class="text-base leading-[22px] text-muted" data-testid="hint">{{ hint }}</p>
      </template>
    </article>

    <AnswerInput :answer-length="answerLength" :disabled="disabled" @answer="emit('answer', $event)" />
  </div>
</template>
