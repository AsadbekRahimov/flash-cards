<script setup lang="ts">
/**
 * PLACEHOLDER of "Pick the collocation" (task 18.1-T): the final interface of the component, with the simplest working
 * content. 18.3 replaces this file as a whole; the props and the events stay.
 *
 * - `element` — the phrase with a blank (`___`) and its three options.
 * - `mode` — `solo` / `battle`: one tap is the answer in both (the choice is final).
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (`{ option_id }`).
 */
import { ref } from 'vue'
import QuizOption from '@/components/QuizOption.vue'
import { modeTitle } from '@/composables/practiceLabels'
import type { CollocationElement, PracticeAnswerPayload } from '@/api/types'

const props = defineProps<{
  element: CollocationElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload] }>()

const picked = ref<number | null>(null)

function pick(index: number): void {
  const option = props.element.options[index]
  if (props.disabled || picked.value !== null || option === undefined) return
  picked.value = index
  emit('answer', { option_id: option.id })
}
</script>

<template>
  <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4" data-testid="collocation-choice">
    <article class="card flex flex-col items-center gap-3 px-5 py-6 text-center">
      <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ modeTitle('collocation') }}</p>
      <h2 class="text-2xl leading-8 font-bold break-words" data-testid="collocation-text">{{ element.text }}</h2>
    </article>

    <div class="flex flex-col gap-2.5" role="group" :aria-label="modeTitle('collocation')">
      <QuizOption
        v-for="(option, index) in element.options"
        :key="option.id"
        :label="option.text"
        :index="index"
        :state="picked === index ? 'selected' : 'idle'"
        :disabled="disabled || picked !== null"
        @pick="pick"
      />
    </div>
  </div>
</template>
