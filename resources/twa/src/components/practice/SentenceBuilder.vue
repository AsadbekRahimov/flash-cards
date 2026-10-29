<script setup lang="ts">
/**
 * PLACEHOLDER of "Build the sentence" (task 18.1-T): the final interface of the component, with the simplest working
 * content. 18.4 replaces this file as a whole; the props and the events stay.
 *
 * - `element` — the meaning in the language of the learner and the shuffled fragments.
 * - `mode` — `solo` / `battle`: "Check" sends the whole order once, in both.
 * - `disabled` — an answer is on its way: nothing reacts.
 * Events: `answer` (`{ order }`, the ids of all fragments in the order picked), `skip` ("I don't know").
 */
import { computed, ref } from 'vue'
import StickyActions from '@/components/StickyActions.vue'
import { modeTitle } from '@/composables/practiceLabels'
import { useI18n } from '@/i18n'
import type { PracticeAnswerPayload, SentenceElement } from '@/api/types'

const props = defineProps<{
  element: SentenceElement
  mode: 'solo' | 'battle'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: PracticeAnswerPayload]; skip: [] }>()

const { t } = useI18n()

/** Ids of the fragments in the answer line, in the order picked. */
const order = ref<string[]>([])

const byId = computed(() => new Map(props.element.fragments.map((fragment) => [fragment.id, fragment.text])))
const bank = computed(() => props.element.fragments.filter((fragment) => !order.value.includes(fragment.id)))
const complete = computed(() => order.value.length === props.element.fragments.length)

function take(id: string): void {
  if (!props.disabled) order.value = [...order.value, id]
}

function putBack(id: string): void {
  if (!props.disabled) order.value = order.value.filter((picked) => picked !== id)
}

function check(): void {
  if (!props.disabled && complete.value) emit('answer', { order: [...order.value] })
}
</script>

<template>
  <div class="flex flex-1 flex-col" data-testid="sentence-builder">
    <div class="flex flex-1 flex-col gap-4 px-4 pt-4 pb-4">
      <article class="card flex flex-col items-center gap-2 px-5 py-6 text-center">
        <p class="text-[15px] leading-5 font-medium text-tg-subtitle">{{ modeTitle('sentence') }}</p>
        <h2 class="text-2xl leading-8 font-bold break-words" data-testid="sentence-prompt">{{ element.prompt }}</h2>
      </article>

      <ul class="card flex min-h-[4.5rem] flex-wrap content-start gap-2 p-2" data-testid="sentence-answer">
        <li v-for="id in order" :key="id">
          <button type="button" class="btn btn-secondary" :disabled="disabled" @click="putBack(id)">{{ byId.get(id) }}</button>
        </li>
      </ul>

      <div class="flex flex-wrap gap-2" role="group" data-testid="sentence-bank">
        <button v-for="fragment in bank" :key="fragment.id" type="button" class="btn btn-tinted" :disabled="disabled" :data-testid="`fragment-${fragment.id}`" @click="take(fragment.id)">
          {{ fragment.text }}
        </button>
      </div>
    </div>

    <StickyActions>
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="disabled || !complete" data-testid="check-btn" @click="check">
        {{ t('exercise.check') }}
      </button>
      <button type="button" class="btn btn-plain btn-block" :disabled="disabled" data-testid="skip-btn" @click="emit('skip')">
        {{ t('exercise.skip') }}
      </button>
    </StickyActions>
  </div>
</template>
