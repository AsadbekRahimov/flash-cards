<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { OptionState } from '@/composables/useChoice'
import { useI18n } from '@/i18n'

/**
 * One answer of a four-choice question (training and exam): AnswerOption of the kit Muhrla (COMPONENTS.md, `.option` of the design
 * system): a 28 x 28 key with the number on the left (1-4, radius xs), the label (Body/L), and on the right the mark of a checked
 * answer. `state` goes to `data-state` and decides the look:
 * - `idle` — the hairline frame, the key on the sunken paper;
 * - `selected` — the pick waiting for the server: a 2 px ink frame, the key in ink;
 * - `correct` / `reveal` — the right answer: the success soft fill and frame, the key in the success ink, a check on the right and
 *   "right answer" in words;
 * - `incorrect` — the learner's miss: the error soft fill and frame, the key in the error ink, a cross and "your answer" in words.
 * The colour is never the only sign: the verdict states carry a caption in words and a shape (check / cross).
 * A disabled option that has no state yet takes the quiet look of `dimmed` (no frame of its own, muted text, a quiet key),
 * as the options of the Feedback mock-up that are neither picked nor right.
 *
 * Accessibility: the options are a radio group (the parent is `role="radiogroup"` with the question as its name): each is
 * `role="radio"` with `aria-checked` (the picked one) and, when it can no longer be picked, `aria-disabled` — it stays
 * focusable, so a screen reader still reads the verdict ("right answer") after the check. The key is decoration (`aria-hidden`): the
 * name of the option is its label. The arrow keys move the focus to the previous / next option of the group (Tab still walks them one
 * by one); Enter and Space pick, as on any button. The box is at least 60 px high (48 px in a low window).
 *
 * `long` - the options of a phrase card (docs/35 §3.3 d): whole phrases or their translations. The text is a size smaller and wraps over
 * as many lines as it needs (the box grows with it, nothing is clipped), the caption of the verdict goes under the text instead of
 * beside it. `lang` - the language of the label (`en` for English phrases), for the screen reader.
 *
 * `note` - a short line under the label, in the colour of the option (the game "Karvon": "removed by the hint" under an option the hint
 * of the bazaar took away; such an option is passed `disabled` and takes the quiet look of `dimmed`). Optional; nothing changes without it.
 */
const props = defineProps<{
  label: string
  index: number
  state: OptionState
  disabled?: boolean
  long?: boolean
  lang?: string
  note?: string
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

/** The key on the left: its fill by the state (the figure is on-inverse on the filled ones, 4.5:1 in both palettes). */
const keyTone = computed((): string => {
  if (dimmed.value) return 'bg-transparent text-muted shadow-[inset_0_0_0_1px_var(--app-divider)]'
  switch (props.state) {
    case 'selected':
      return 'bg-inverse text-on-inverse'
    case 'correct':
    case 'reveal':
      return 'bg-success-ink text-on-inverse'
    case 'incorrect':
      return 'bg-danger-ink text-on-inverse'
    default:
      return 'bg-sunken text-muted'
  }
})

/** The mark on the right of a checked answer: a check for the right one, a cross for a miss; none before the check. */
const mark = computed((): { icon: 'check' | 'x-mark'; tone: string } | null => {
  switch (props.state) {
    case 'correct':
    case 'reveal':
      return { icon: 'check', tone: 'text-success-ink' }
    case 'incorrect':
      return { icon: 'x-mark', tone: 'text-danger-ink' }
    default:
      return null
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
    class="option min-h-[60px] short:min-h-12"
    :class="{
      'py-3 text-base leading-[22px] short:py-2.5': long,
      'bg-transparent text-muted shadow-[inset_0_0_0_1px_var(--app-divider)]': dimmed,
      'cursor-default active:scale-100': disabled,
    }"
    :aria-checked="checked ? 'true' : 'false'"
    :aria-disabled="disabled ? 'true' : undefined"
    :data-testid="`option-${index}`"
    :data-state="state"
    :data-dimmed="dimmed ? 'true' : undefined"
    :data-long="long ? 'true' : undefined"
    @click="onClick"
    @keydown.down="onArrow($event, 1)"
    @keydown.right="onArrow($event, 1)"
    @keydown.up="onArrow($event, -1)"
    @keydown.left="onArrow($event, -1)"
  >
    <span
      class="flex size-7 shrink-0 items-center justify-center self-center rounded-xs text-[15px] leading-5 font-medium transition-colors duration-200"
      :class="keyTone"
      aria-hidden="true"
      :data-testid="`option-key-${index}`"
    >{{ index + 1 }}</span>
    <span v-if="long || note" class="min-w-0 flex-1 break-words">
      <span class="block" :lang="lang" data-testid="option-label">{{ label }}</span>
      <span v-if="note" class="mt-0.5 block text-[13px] leading-[18px] font-normal" :data-testid="`option-note-${index}`">{{ note }}</span>
      <span
        v-if="caption && long"
        class="mt-0.5 block text-[13px] leading-[18px]"
        :class="state === 'incorrect' ? 'font-normal text-danger-ink' : 'font-medium text-success-ink'"
        :data-testid="`option-caption-${index}`"
      >{{ caption }}</span>
    </span>
    <span v-else class="min-w-0 flex-1 break-words" :lang="lang" data-testid="option-label">{{ label }}</span>
    <span
      v-if="caption && !long"
      class="shrink-0 text-[13px] leading-[18px]"
      :class="state === 'incorrect' ? 'font-normal text-danger-ink' : 'font-medium text-success-ink'"
      :data-testid="`option-caption-${index}`"
    >{{ caption }}</span>
    <span
      v-if="mark"
      class="option-mark flex size-6 shrink-0 items-center justify-center"
      :class="mark.tone"
      aria-hidden="true"
      :data-testid="`option-indicator-${index}`"
    >
      <AppIcon :name="mark.icon" :size="22" />
    </span>
  </button>
</template>
