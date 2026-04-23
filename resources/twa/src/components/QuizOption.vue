<script setup lang="ts">
import { computed } from 'vue'

type State = 'idle' | 'selected' | 'correct' | 'incorrect' | 'reveal'

const props = defineProps<{
  label: string
  index: number
  state: State
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'pick', index: number): void
}>()

const classes = computed((): string => {
  switch (props.state) {
    case 'correct':
      return 'bg-green-500 text-white border-green-600'
    case 'incorrect':
      return 'bg-red-500 text-white border-red-600'
    case 'reveal':
      return 'bg-green-100 text-green-900 border-green-400'
    case 'selected':
      return 'bg-tg-button text-tg-button-text border-transparent'
    default:
      return 'bg-tg-secondary-bg text-tg-text border-transparent'
  }
})

function onClick(): void {
  if (props.disabled) return
  emit('pick', props.index)
}
</script>

<template>
  <button
    type="button"
    class="w-full text-left rounded-xl px-4 py-3 border-2 transition-colors duration-150 active:scale-[0.98] disabled:opacity-60"
    :class="classes"
    :disabled="disabled"
    :data-testid="`option-${index}`"
    :data-state="state"
    @click="onClick"
  >
    <span class="text-base font-medium">{{ label }}</span>
  </button>
</template>
