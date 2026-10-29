<script setup lang="ts">
/**
 * PLACEHOLDER of "Find the pairs" (task 18.1-T): the final interface of the component, with the simplest working
 * content. 18.2 replaces this file as a whole; the props and the events stay.
 *
 * - `element` — the round: the words on the left, the translations on the right, `matched` (put together already),
 *   `mismatches`.
 * - `mode` — `solo`: every match is sent at once (`answer` with `{ left_id, right_id }`); `battle`: the matches are
 *   collected and sent together by "Check" (`answer` with `{ pairs }`).
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (the payload of the mode).
 */
import { computed, ref } from 'vue'
import { useI18n } from '@/i18n'
import type { PairsRoundElement, PracticeAnswerPayload } from '@/api/types'

const props = defineProps<{
  element: PairsRoundElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload] }>()

const { t } = useI18n()

/** The word picked on the left, waiting for its translation. */
const picked = ref<string | null>(null)
/** Battle only: the matches put together so far (left → right). */
const chosen = ref<Record<string, string>>({})

const matchedLeft = computed(() => new Set(props.element.matched.map(([left]) => left)))
const matchedRight = computed(() => new Set(props.element.matched.map(([, right]) => right)))
const takenRight = computed(() => new Set(Object.values(chosen.value)))
const complete = computed(() => Object.keys(chosen.value).length === props.element.left.length)

function pickLeft(id: string): void {
  if (props.disabled || matchedLeft.value.has(id)) return
  picked.value = picked.value === id ? null : id
}

function pickRight(id: string): void {
  if (props.disabled || picked.value === null || matchedRight.value.has(id)) return
  if (props.mode === 'solo') {
    emit('answer', { left_id: picked.value, right_id: id })
  } else if (!takenRight.value.has(id)) {
    chosen.value = { ...chosen.value, [picked.value]: id }
  }
  picked.value = null
}

function check(): void {
  if (complete.value) emit('answer', { pairs: { ...chosen.value } })
}
</script>

<template>
  <div class="flex flex-1 flex-col gap-3 px-4 pt-4 pb-4" data-testid="pairs-board">
    <div class="grid grid-cols-2 gap-2.5">
      <div class="flex flex-col gap-2.5" role="group" data-testid="pairs-left">
        <button
          v-for="word in element.left"
          :key="word.id"
          type="button"
          class="option min-h-14 justify-center text-center"
          :data-state="matchedLeft.has(word.id) || word.id in chosen ? 'correct' : picked === word.id ? 'selected' : 'idle'"
          :disabled="disabled || matchedLeft.has(word.id)"
          :data-testid="`left-${word.id}`"
          @click="pickLeft(word.id)"
        >
          {{ word.text }}
        </button>
      </div>
      <div class="flex flex-col gap-2.5" role="group" data-testid="pairs-right">
        <button
          v-for="word in element.right"
          :key="word.id"
          type="button"
          class="option min-h-14 justify-center text-center"
          :data-state="matchedRight.has(word.id) || takenRight.has(word.id) ? 'correct' : 'idle'"
          :disabled="disabled || matchedRight.has(word.id)"
          :data-testid="`right-${word.id}`"
          @click="pickRight(word.id)"
        >
          {{ word.text }}
        </button>
      </div>
    </div>

    <button v-if="mode === 'battle'" type="button" class="btn btn-primary btn-lg btn-block" :disabled="disabled || !complete" data-testid="pairs-check" @click="check">
      {{ t('exercise.check') }}
    </button>
  </div>
</template>
