<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import type { OptionState } from '@/composables/useChoice'

/**
 * One answer of a four-choice question (training and exam): the `.option` button of the design system, placed on the
 * page under the question card (docs/14 §4.3). `state` goes to `data-state` and decides the colours: `selected` is the
 * pick waiting for the server, `correct` / `incorrect` show the verdict on the picked option (with a check / a cross on the
 * right), `reveal` marks the right answer after a miss. A disabled option that has no state yet is dimmed.
 */
const props = defineProps<{
  label: string
  index: number
  state: OptionState
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'pick', index: number): void
}>()

function onClick(): void {
  if (props.disabled) return
  emit('pick', props.index)
}
</script>

<template>
  <button
    type="button"
    class="option"
    :class="{ 'opacity-60': disabled && state === 'idle' }"
    :disabled="disabled"
    :data-testid="`option-${index}`"
    :data-state="state"
    @click="onClick"
  >
    <span class="min-w-0 flex-1 break-words">{{ label }}</span>
    <AppIcon v-if="state === 'correct'" name="check" :size="22" />
    <AppIcon v-else-if="state === 'incorrect'" name="x-mark" :size="22" />
  </button>
</template>
