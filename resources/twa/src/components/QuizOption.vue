<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { OptionState } from '@/composables/useChoice'
import { useI18n } from '@/i18n'

/**
 * One answer of a four-choice question (training and exam): the `.option` button of the design system, placed on the
 * white screen under the question (docs/design Question / Feedback). Its number (1-4, mono, muted) leads it. `state` goes to
 * `data-state` and decides the colours: `selected` is the pick waiting for the server, `correct` / `incorrect` show the
 * verdict on the picked option, `reveal` marks the right answer after a miss. The colour is never the only sign: the
 * verdict states carry a caption in words ("your answer" / "right answer") and a check / a cross on the right.
 * A disabled option that has no state yet takes the quiet look of `dimmed` (no frame of its own, muted text).
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

const { t } = useI18n()

const dimmed = computed((): boolean => props.disabled === true && props.state === 'idle')

const caption = computed((): string | null => {
  switch (props.state) {
    case 'incorrect':
      return t('exercise.option.yours')
    case 'correct':
    case 'reveal':
      return t('exercise.option.right')
    default:
      return null
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
    class="option short:min-h-12"
    :class="{ 'bg-transparent text-muted shadow-[inset_0_0_0_1px_var(--app-divider)]': dimmed }"
    :disabled="disabled"
    :data-testid="`option-${index}`"
    :data-state="state"
    :data-dimmed="dimmed ? 'true' : undefined"
    @click="onClick"
  >
    <span
      class="w-3 shrink-0 font-mono text-[13px] leading-[18px] font-normal"
      :class="state === 'selected' ? 'text-primary' : state === 'idle' ? 'text-muted' : ''"
      aria-hidden="true"
    >{{ index + 1 }}</span>
    <span class="min-w-0 flex-1 break-words">{{ label }}</span>
    <span v-if="caption" class="shrink-0 text-[13px] leading-[18px] font-normal" :data-testid="`option-caption-${index}`">{{ caption }}</span>
    <AppIcon v-if="state === 'correct'" name="check" :size="18" class="shrink-0" />
    <AppIcon v-else-if="state === 'incorrect'" name="x-mark" :size="18" class="shrink-0" />
  </button>
</template>
