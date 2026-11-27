<script setup lang="ts">
import { computed } from 'vue'
import { NUMBER_MARK } from '@/components/game/numberMark'

/**
 * A localized line with numbers in it ("У вас 40", "Лучший счёт: 120", "12 / 40 · до пт 18:00"), where only the numbers are set in
 * `font-mono` (docs/14 §4.0: the numbers of the kit are Unbounded with tabular figures; the words next to a number stay in the text
 * face, Onest). The caller localizes the
 * line with `NUMBER_MARK` in place of each number and passes the numbers themselves, in the order of the marks: the order of the words
 * stays the dictionary's, in either language. A line without a mark is written as it is.
 *
 * @example <NumberText :text="t('game.stop.best', { score: NUMBER_MARK })" :value="stop.best_score" />
 */
const props = defineProps<{ text: string; value: number | string | readonly (number | string)[] }>()

/** The words between the marks, each with the number that follows it (none after the last). */
const parts = computed((): { text: string; number: string | null }[] => {
  const values = Array.isArray(props.value) ? props.value : [props.value]
  const words = props.text.split(NUMBER_MARK)
  return words.map((text, index) => ({ text, number: index < words.length - 1 ? String(values[index] ?? '') : null }))
})
</script>

<template>
  <span><template v-for="(part, index) in parts" :key="index">{{ part.text }}<span v-if="part.number !== null" class="font-mono">{{ part.number }}</span></template></span>
</template>
