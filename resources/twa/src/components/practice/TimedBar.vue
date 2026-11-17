<script setup lang="ts">
/**
 * The clock and the score of a timed game ("Pairs against the clock", docs/16 §5.5a, §11): a big number of seconds, the pairs made, and a
 * "−2 s" that flashes when a wrong match took time.
 *
 * - `deadline` — the end of the game on the DEVICE clock (epoch ms; the store works it out from `deadline_at - server_now` of one reply); the
 *   seconds left are `deadline - now`, counted by the local clock. A device whose clock jumps is no worry: the store recomputes the deadline
 *   from every reply, and the server is the one that counts.
 * - `penalty` — `seq` grows with every wrong match (so that the flash shows again), `ms` is what it cost.
 * - Event `expired`: the clock reached zero, once per deadline (a new deadline, e.g. after a wrong match, arms it again).
 *
 * The last 10 seconds are told by colour (and by a pulse, which `prefers-reduced-motion` turns off). The seconds are read by a screen reader
 * only now and then (the number itself is `aria-hidden`; a polite line says "N seconds left" at 30, 10 and 5), so that it does not chatter.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from '@/i18n'

const props = defineProps<{
  deadline: number | null
  seconds: number
  score: number
  penalty: { seq: number; ms: number }
}>()

const emit = defineEmits<{ expired: [] }>()

const { t } = useI18n()

const TICK_MS = 200
const WARNING_SECONDS = 10
const FLASH_MS = 1000
const ANNOUNCE_AT = [30, 10, 5]

const now = ref(Date.now())
let handle: ReturnType<typeof setInterval> | null = null
let expiredFor: number | null = null

const leftMs = computed((): number => (props.deadline === null ? props.seconds * 1000 : Math.max(0, props.deadline - now.value)))
const left = computed((): number => Math.ceil(leftMs.value / 1000))
const warning = computed((): boolean => props.deadline !== null && left.value <= WARNING_SECONDS)
const fill = computed((): number => Math.min(100, Math.max(0, (leftMs.value / (props.seconds * 1000)) * 100)))

function tick(): void {
  now.value = Date.now()
  if (props.deadline !== null && props.deadline <= now.value && expiredFor !== props.deadline) {
    expiredFor = props.deadline
    emit('expired')
  }
}

function onVisible(): void {
  if (document.visibilityState === 'visible') tick()
}

onMounted(() => {
  handle = setInterval(tick, TICK_MS)
  document.addEventListener('visibilitychange', onVisible)
  tick()
})

onBeforeUnmount(() => {
  if (handle !== null) clearInterval(handle)
  document.removeEventListener('visibilitychange', onVisible)
})

// A new deadline (every wrong match moves it) is read at once, not at the next tick.
watch(() => props.deadline, tick)

const flash = ref(false)
let flashTimer: ReturnType<typeof setTimeout> | null = null
watch(
  () => props.penalty.seq,
  (seq) => {
    if (seq === 0) return
    flash.value = true
    if (flashTimer !== null) clearTimeout(flashTimer)
    flashTimer = setTimeout(() => (flash.value = false), FLASH_MS)
  },
)
onBeforeUnmount(() => {
  if (flashTimer !== null) clearTimeout(flashTimer)
})

const penaltyText = computed((): string => t('practice.timed.penalty', { seconds: Math.round(props.penalty.ms / 1000) }))

const announce = computed((): string => (props.deadline !== null && ANNOUNCE_AT.includes(left.value) ? t('practice.timed.seconds_left', { seconds: left.value }) : ''))
</script>

<template>
  <div class="px-4 pt-2" data-testid="timed-bar" :data-warning="warning ? 'true' : 'false'">
    <div class="flex items-end justify-between gap-3">
      <div class="relative">
        <span
          class="block text-[48px] leading-[52px] font-bold tabular-nums"
          :class="warning ? 'text-danger-ink motion-safe:animate-pulse' : 'text-tg-text'"
          aria-hidden="true"
          data-testid="timed-seconds"
        >{{ left }}</span>
        <span class="sr-only" data-testid="timed-seconds-sr">{{ t('practice.timed.seconds_left', { seconds: left }) }}</span>
        <span
          v-if="flash"
          class="absolute top-1 left-full ml-2 rounded-full bg-danger-soft px-2 py-0.5 text-[15px] leading-5 font-semibold whitespace-nowrap text-danger-ink"
          data-testid="timed-penalty"
        >{{ penaltyText }}</span>
      </div>
      <p class="pb-1 text-[20px] leading-6 font-semibold tabular-nums" data-testid="timed-score">{{ t('practice.timed.score', { score }) }}</p>
    </div>
    <div class="progress mt-2" aria-hidden="true">
      <div class="progress-fill" :style="{ width: `${fill}%`, ...(warning ? { background: 'var(--app-danger)' } : {}) }" data-testid="timed-fill" />
    </div>
    <p class="sr-only" role="status" aria-live="polite" data-testid="timed-live">{{ announce }}</p>
  </div>
</template>
