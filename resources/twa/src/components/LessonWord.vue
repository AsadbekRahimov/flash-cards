<script setup lang="ts">
import { computed } from 'vue'

/**
 * The word (or the phrase, or the translation) a card of a lesson asks about, in the display face of the kit Muhrla (Word/XL: Unbounded 600,
 * 44 / 50, DESIGN.md §3). Unbounded is a wide face, so the size steps down with the length and the text wraps by words (a single word longer
 * than the line breaks inside it): nothing is clipped at 320 px. Another step down on a short window (`short:`) and once the answer is in
 * (`compact`: the options must stay above the feedback sheet).
 *
 * - `text` — what is shown; `phrase` — a phrase of the phrase course (up to 100 characters: smaller steps, balanced lines).
 * - `compact` — the answer is in: one step smaller.
 *
 * Attributes (`data-testid`, `lang`, `class`) go to the `<h2>`. The classes are written out in full, so that Tailwind finds them.
 *
 * @example <LessonWord :text="word" :compact="answered" lang="en" data-testid="word" />
 */
const props = defineProps<{ text: string; phrase?: boolean; compact?: boolean }>()

/** The steps by length: [the largest that fits a line of 350 px (Unbounded is ~0.7 em per letter), compact, short window]. */
const WORD_STEPS: readonly { max: number; full: string; compact: string }[] = [
  {
    max: 9,
    full: 'text-[44px] leading-[50px] tracking-[-0.03em] short:text-[38px] short:leading-[44px]',
    compact: 'text-[36px] leading-[42px] tracking-[-0.03em] short:text-[32px] short:leading-[38px]',
  },
  {
    max: 13,
    full: 'text-[36px] leading-[42px] tracking-[-0.03em] short:text-[32px] short:leading-[38px]',
    compact: 'text-[30px] leading-[36px] tracking-[-0.02em] short:text-[28px] short:leading-[34px]',
  },
  {
    max: 18,
    full: 'text-[30px] leading-[36px] tracking-[-0.02em] short:text-[28px] short:leading-[34px]',
    compact: 'text-[26px] leading-[32px] tracking-[-0.02em] short:text-[24px] short:leading-[30px]',
  },
  {
    max: Infinity,
    full: 'text-[26px] leading-[32px] tracking-[-0.02em] short:text-[24px] short:leading-[30px]',
    compact: 'text-[22px] leading-[28px] tracking-[-0.01em] short:text-[20px] short:leading-[26px]',
  },
]

const PHRASE_STEPS: readonly { max: number; full: string; compact: string }[] = [
  {
    max: 18,
    full: 'text-[30px] leading-[36px] tracking-[-0.02em] short:text-[26px] short:leading-[32px]',
    compact: 'text-[26px] leading-[32px] tracking-[-0.02em] short:text-[22px] short:leading-[28px]',
  },
  {
    max: 36,
    full: 'text-[24px] leading-[30px] tracking-[-0.02em] short:text-[22px] short:leading-[28px]',
    compact: 'text-[22px] leading-[28px] tracking-[-0.01em] short:text-[20px] short:leading-[26px]',
  },
  {
    max: 60,
    full: 'text-[20px] leading-[26px] tracking-[-0.01em] short:text-[18px] short:leading-[24px]',
    compact: 'text-[18px] leading-[24px] tracking-[-0.01em] short:text-[17px] short:leading-[22px]',
  },
  {
    max: Infinity,
    full: 'text-[18px] leading-[24px] tracking-[-0.01em] short:text-[17px] short:leading-[22px]',
    compact: 'text-[17px] leading-[22px] short:text-[16px] short:leading-[22px]',
  },
]

const size = computed((): string => {
  const steps = props.phrase ? PHRASE_STEPS : WORD_STEPS
  const length = props.text.trim().length
  const step = steps.find((item) => length <= item.max) ?? steps[steps.length - 1]
  return props.compact ? (step?.compact ?? '') : (step?.full ?? '')
})
</script>

<template>
  <h2 class="max-w-full font-display font-semibold text-balance break-words" :class="size" :data-size="size.split(' ')[0]">{{ text }}</h2>
</template>
