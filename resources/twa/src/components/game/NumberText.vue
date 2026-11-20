<script setup lang="ts">
import { computed } from 'vue'
import { NUMBER_MARK } from '@/components/game/numberMark'

/**
 * A localized line with one number in it ("У вас 40", "Лучший счёт: 120", "2 из 10"), where only the number is set in `font-mono`
 * (docs/14 §4.2: Plex Mono has no Cyrillic, so the words next to a number stay in the text face). The caller localizes the line
 * with `NUMBER_MARK` in place of the number and passes the number itself: the order of the words stays the dictionary's, in
 * either language. A line without the mark is written as it is.
 *
 * @example <NumberText :text="t('game.stop.best', { score: NUMBER_MARK })" :value="stop.best_score" />
 */
const props = defineProps<{ text: string; value: number | string }>()

const parts = computed((): [string, string] | null => {
  const at = props.text.indexOf(NUMBER_MARK)
  return at < 0 ? null : [props.text.slice(0, at), props.text.slice(at + NUMBER_MARK.length)]
})
</script>

<template>
  <span v-if="parts">{{ parts[0] }}<span class="font-mono">{{ value }}</span>{{ parts[1] }}</span>
  <span v-else>{{ text }}</span>
</template>
