<script setup lang="ts">
import { computed } from 'vue'
import type { Graded } from '@/api/types'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * What the server said about a typed answer (spelling / gap-fill): right, close (within the
 * tolerance — the exact spelling is shown) or wrong (the right answer is shown). "Next" goes on
 * to the card the review answered with.
 */
const props = defineProps<{
  verdict: Graded
  /** The learner's own text, shown next to the right one after a miss. */
  answer?: string
}>()

const emit = defineEmits<{
  (e: 'continue'): void
}>()

const { haptic } = useTelegram()
const { t } = useI18n()

const kind = computed((): 'correct' | 'close' | 'wrong' =>
  props.verdict.quality >= 5 ? 'correct' : props.verdict.correct ? 'close' : 'wrong',
)

const text = computed((): string =>
  kind.value === 'correct'
    ? t('exercise.verdict.correct')
    : t(`exercise.verdict.${kind.value}`, { expected: props.verdict.expected }),
)

function onContinue(): void {
  haptic('light')
  emit('continue')
}
</script>

<template>
  <article class="card flex flex-col gap-4" data-testid="answer-verdict" :data-kind="kind">
    <div class="text-center py-6 space-y-2">
      <p class="text-4xl">{{ kind === 'wrong' ? '❌' : kind === 'close' ? '🟡' : '✅' }}</p>
      <p class="text-xl font-semibold" data-testid="verdict-text">{{ text }}</p>
      <p v-if="kind !== 'correct' && answer" class="text-tg-hint line-through" data-testid="own-answer">{{ answer }}</p>
    </div>
    <button type="button" class="btn btn-primary w-full text-lg mt-auto" data-testid="continue-btn" @click="onContinue">
      {{ t('exercise.continue') }}
    </button>
  </article>
</template>
