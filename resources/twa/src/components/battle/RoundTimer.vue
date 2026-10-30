<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { clockText, remainingMs, secondsLeft } from '@/composables/battleTime'
import { useNow } from '@/composables/useNow'
import { useI18n } from '@/i18n'

/**
 * The time left in a round (docs/16 §11.2, §11.5): a bar and the minutes and seconds, counted to the deadline of the
 * SERVER by the server's clock (`offsetMs` = `server time − device time`). It only shows the time: the server decides
 * what is on time, so an answer sent after 0 still goes (the server's grace), and at 0 the timer says "Time is up" and
 * waits for the server to move the round on.
 *
 * - `deadline` — `round.deadline_at`; `null` shows a full bar and no time.
 * - `totalSeconds` — the length of a round, for the bar.
 * - the last ten seconds are warm (the warning colour, never red, nothing blinks).
 *
 * a11y: `role="timer"`. The numbers that change every second are hidden from a screen reader; a live region says the time
 * left every ten seconds and every second of the last five, and "Time is up" at 0.
 */
const props = defineProps<{
  deadline: string | null
  offsetMs: number
  totalSeconds: number
}>()

const { t } = useI18n()
const now = useNow()

const left = computed(() => (props.deadline === null ? props.totalSeconds * 1000 : remainingMs(props.deadline, now.value, props.offsetMs)))
const seconds = computed(() => secondsLeft(left.value))
const over = computed(() => props.deadline !== null && left.value <= 0)
const low = computed(() => !over.value && props.deadline !== null && seconds.value <= 10)
const share = computed(() => (props.totalSeconds > 0 ? Math.min(1, Math.max(0, left.value / (props.totalSeconds * 1000))) : 0))

/** What the live region says: set only at the moments worth a word, so that the reader is not told every second. */
const announced = ref('')
watch(
  [seconds, over],
  ([s, isOver]) => {
    if (isOver) announced.value = t('battle.timer.over')
    else if (s <= 5 || s % 10 === 0) announced.value = t('battle.timer.left', { seconds: s })
  },
  { immediate: true },
)
</script>

<template>
  <div
    class="flex items-center gap-3"
    role="timer"
    :aria-label="t('battle.timer.label')"
    :data-state="over ? 'over' : low ? 'low' : 'ok'"
    data-testid="round-timer"
  >
    <div class="progress flex-1" aria-hidden="true">
      <div class="progress-fill" :class="{ 'bg-warning': low || over }" :style="{ width: `${Math.round(share * 100)}%` }" data-testid="timer-fill" />
    </div>
    <span
      class="flex min-w-[4.5rem] items-center justify-end gap-1 font-bold tabular-nums"
      :class="[over ? 'text-[17px] leading-[22px]' : 'text-[22px] leading-7', low || over ? 'text-warning-ink' : 'text-tg-text']"
      aria-hidden="true"
      data-testid="timer-text"
    >
      <AppIcon name="clock" :size="18" />
      <template v-if="over">{{ t('battle.timer.over') }}</template>
      <template v-else>{{ clockText(seconds) }}</template>
    </span>
    <span class="sr-only" aria-live="polite" aria-atomic="true" data-testid="timer-live">{{ announced }}</span>
  </div>
</template>
