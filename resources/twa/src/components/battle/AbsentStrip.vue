<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { secondsLeft } from '@/composables/battleTime'
import { useNow } from '@/composables/useNow'
import { useI18n } from '@/i18n'

/**
 * "The opponent lost connection. If they do not come back in 21 s, the victory is yours" (docs/16 §8.4, §11.2).
 *
 * The server says since when the opponent has been silent (`opponent_absent_since`: their last presence) and how long it waits for
 * them (`presence_grace_seconds`); the number on the strip is what is left of that wait by the SERVER's clock, so a screen that is
 * opened (or reloaded) in the middle of it shows the same number as the one that has been here all along, and it never goes below 0.
 *
 * - `since` - server time (epoch ms) of the last presence of the opponent; `graceSeconds` - how long the server waits; `offsetMs` -
 *   `server time − device time` (the clock of the battle store).
 * - `playing` - in a match the one who stayed wins; before it (the room, the countdown) the match is canceled.
 *
 * a11y: the numbers change every second, so the lines are hidden from a screen reader; a polite live region says the sentence when the
 * strip appears, then every ten seconds and every second of the last five - the way the timer of a round does.
 */
const props = defineProps<{
  since: number
  graceSeconds: number
  offsetMs: number
  playing: boolean
}>()

const { t } = useI18n()
const now = useNow()

const seconds = computed((): number => secondsLeft(props.since + props.graceSeconds * 1000 - (now.value + props.offsetMs)))
const hint = computed((): string => t(props.playing ? 'battle.connection.absent_playing' : 'battle.connection.absent_room', { seconds: seconds.value }))
const sentence = computed((): string => `${t('battle.connection.absent')} ${hint.value}`)

/** What the live region says: set only at the moments worth a word. */
const announced = ref('')
watch(
  seconds,
  (s) => {
    if (announced.value === '' || s <= 5 || s % 10 === 0) announced.value = sentence.value
  },
  { immediate: true },
)
</script>

<template>
  <InlineAlert tone="warning" data-testid="absent-strip" :data-seconds="seconds">
    <span class="block font-medium" aria-hidden="true" data-testid="absent-text">{{ t('battle.connection.absent') }}</span>
    <span class="block text-[13px] leading-[18px]" aria-hidden="true" data-testid="absent-hint">{{ hint }}</span>
    <span class="sr-only" data-testid="absent-live">{{ announced }}</span>
  </InlineAlert>
</template>
