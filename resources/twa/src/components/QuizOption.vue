<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { OptionState } from '@/composables/useChoice'
import { useI18n } from '@/i18n'

/**
 * One answer of a four-choice question (training and exam): the `.option` button of the design system, placed on the
 * white screen under the question (docs/design/2026-11-25-redesign Question / Feedback): 18 px text on the left, a 24 px
 * round indicator on the right (no number in front any more). `state` goes to `data-state` and decides the colours:
 * - `idle` — an empty ring (`track`);
 * - `selected` — the pick waiting for the server: the soft accent box with the primary frame, the ring filled with a check;
 * - `correct` / `reveal` — the right answer: success colours, "right answer" in words and a filled check;
 * - `incorrect` — the learner's miss: danger colours, "your answer" in words and a filled cross.
 * The colour is never the only sign: the verdict states carry a caption in words and a shape (check / cross).
 * A disabled option that has no state yet takes the quiet look of `dimmed` (no frame of its own, muted text, no ring),
 * as the options of the Feedback mock-up that are neither picked nor right.
 *
 * Accessibility: the options are a radio group (the parent is `role="radiogroup"` with the question as its name): each is
 * `role="radio"` with `aria-checked` (the picked one) and, when it can no longer be picked, `aria-disabled` — it stays
 * focusable, so a screen reader still reads the verdict ("right answer") after the check. The arrow keys move the focus to
 * the previous / next option of the group (Tab still walks them one by one); Enter and Space pick, as on any button.
 * The box is at least 58 px high (48 px in a low window): the touch area is well over 44 px.
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

/** The option the learner picked: waiting for the server, or graded. */
const checked = computed((): boolean => props.state === 'selected' || props.state === 'correct' || props.state === 'incorrect')

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

/** The round indicator on the right: an empty ring, or a filled circle with its mark; none on a dimmed option. */
const indicator = computed((): { icon: 'check' | 'x-mark' | null; fill: string } | null => {
  if (dimmed.value) return null
  switch (props.state) {
    case 'selected':
      return { icon: 'check', fill: 'bg-primary text-on-primary' }
    case 'correct':
    case 'reveal':
      return { icon: 'check', fill: 'bg-success text-on-primary' }
    case 'incorrect':
      return { icon: 'x-mark', fill: 'bg-danger text-on-primary' }
    default:
      return { icon: null, fill: 'border-[1.5px] border-track' }
  }
})

function onClick(): void {
  if (props.disabled) return
  emit('pick', props.index)
}

/** The arrow keys walk the options of the group, as in any radio group; the focus wraps around. */
function onArrow(event: KeyboardEvent, step: 1 | -1): void {
  const self = event.currentTarget as HTMLElement | null
  const group = self?.closest('[role=radiogroup]')
  if (!self || !group) return
  const options = Array.from(group.querySelectorAll<HTMLElement>('[role=radio]'))
  const at = options.indexOf(self)
  if (at < 0 || options.length < 2) return
  event.preventDefault()
  options[(at + step + options.length) % options.length]?.focus()
}
</script>

<template>
  <button
    type="button"
    role="radio"
    class="option min-h-[58px] justify-between text-[18px] leading-6 short:min-h-12"
    :class="{
      'bg-transparent text-muted shadow-[inset_0_0_0_1px_var(--app-divider)]': dimmed,
      'cursor-default active:scale-100': disabled,
    }"
    :aria-checked="checked ? 'true' : 'false'"
    :aria-disabled="disabled ? 'true' : undefined"
    :data-testid="`option-${index}`"
    :data-state="state"
    :data-dimmed="dimmed ? 'true' : undefined"
    @click="onClick"
    @keydown.down="onArrow($event, 1)"
    @keydown.right="onArrow($event, 1)"
    @keydown.up="onArrow($event, -1)"
    @keydown.left="onArrow($event, -1)"
  >
    <span class="min-w-0 flex-1 break-words">{{ label }}</span>
    <span
      v-if="caption"
      class="shrink-0 text-[13px] leading-[18px]"
      :class="state === 'incorrect' ? 'font-normal' : 'font-medium'"
      :data-testid="`option-caption-${index}`"
    >{{ caption }}</span>
    <span
      v-if="indicator"
      class="flex size-6 shrink-0 items-center justify-center rounded-full"
      :class="indicator.fill"
      aria-hidden="true"
      :data-testid="`option-indicator-${index}`"
    >
      <AppIcon v-if="indicator.icon" :name="indicator.icon" solid :size="15" />
    </span>
  </button>
</template>
