<script setup lang="ts">
/**
 * A pairs round, a collocation, a sentence or a dictation of a run, drawn by the component of the practice (`modeComponentFor`) - not
 * copied: this adapter gives it the props it knows (`element`, `mode="solo"`, `disabled`) and passes its events up. A hint of the bazaar on
 * a collocation takes options away before the practice component sees them (`practiceElementOf`).
 * Events: `answer` (the payload of the mode), `skip` (sentence, dictation), `silent` (dictation: "No sound for now").
 */
import { computed } from 'vue'
import { practiceElementOf } from '@/components/game/practiceAdapter'
import { modeComponentFor } from '@/components/practice/modeComponents'
import type { GameAnswerPayload, GamePracticeElement } from '@/api/gameRunTypes'

const props = defineProps<{
  element: GamePracticeElement
  mode?: 'solo'
  disabled?: boolean
}>()

const emit = defineEmits<{ answer: [payload: GameAnswerPayload]; skip: []; silent: [] }>()

const practiceElement = computed(() => practiceElementOf(props.element))
const component = computed(() => modeComponentFor(practiceElement.value))
</script>

<template>
  <component
    :is="component"
    :element="practiceElement"
    mode="solo"
    :disabled="disabled"
    @answer="emit('answer', $event)"
    @skip="emit('skip')"
    @silent="emit('silent')"
  />
</template>
