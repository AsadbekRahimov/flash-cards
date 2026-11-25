<script setup lang="ts">
import { computed } from 'vue'

/**
 * A name of a learner in a list with the emoji of Pro after it (docs/31 §5а): the league and month tables, the friends, the battles.
 * The server sends the emoji only while that learner's Pro lasts (`pro_emoji`: the symbol or null; absent on an older server) - an
 * empty value shows the name alone, as before.
 *
 * - `name` — already localized (the first name, or "Learner" for none).
 * - `emoji` — `pro_emoji` of the row.
 * - `suffix` — what follows the name and the emoji ("· you"), never cut.
 *
 * A long name is cut with an ellipsis; the emoji and the suffix are not (they stay at its end). The emoji is decoration for a screen
 * reader (`aria-hidden`): the name is read alone. The font, the colour and the width are the caller's (`class` goes to the root).
 *
 * @example <PlayerName :name="rowName(row)" :emoji="row.pro_emoji" :suffix="row.is_me ? `· ${t('league.you')}` : undefined" class="flex-1" />
 */
const props = defineProps<{
  name: string
  emoji?: string | null
  suffix?: string
}>()

const shownEmoji = computed(() => (props.emoji ?? '').trim())
</script>

<template>
  <span class="flex min-w-0 items-center gap-1">
    <span class="min-w-0 truncate" data-testid="player-name-text">{{ name }}</span>
    <span v-if="shownEmoji !== ''" class="shrink-0 font-normal" aria-hidden="true" data-testid="pro-emoji">{{ shownEmoji }}</span>
    <span v-if="suffix" class="shrink-0">{{ ` ${suffix}` }}</span>
  </span>
</template>
