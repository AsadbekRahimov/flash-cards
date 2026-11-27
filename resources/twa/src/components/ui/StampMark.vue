<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useTelegram } from '@/composables/useTelegram'

/**
 * The stamp ("muhr"), the signature element of the kit Muhrla (COMPONENTS.md "Stamp", stamp.png): 92 x 92, an outer ring of 2.5 px
 * in the muhr colour and an inner dashed ring of 1 px (2 / 3), turned by -8°, the value in Unbounded and the unit in capitals under it.
 * Used for the streak ("12 ДНЕЙ" on Today), a saved streak and the result of a lesson.
 *
 * - `value` — the big text ("12"); `unit` — the line under it ("ДНЕЙ"), already localized.
 * - `animate` — the "stamp press" when it appears (ANIMATIONS.md §3; a fade only under `prefers-reduced-motion`); default true.
 *   One stamp at a time on a screen.
 * - `haptic` — the press is felt too: `impactOccurred('medium')` once, when the stamp appears (TELEGRAM.md "Muhr bosildi",
 *   ANIMATIONS.md §3 "at the same time"); default false. On the screens of a result (a lesson passed, the end of a battle, a duel, a cup,
 *   a run) - not on Today, whose stamp is there every day. A screen that turns it on gives no other haptic of the result.
 *
 * The rings are in the muhr colour; the value too (a large text: 3:1 is enough, 3.3:1 on the page); the small unit takes the accent
 * of text (`text-accent`, 4.5:1). For a screen reader the stamp is one image named "12 ДНЕЙ".
 *
 * @example <StampMark :value="String(streak)" :unit="t('today.streak.unit', { count: streak })" />
 */
const props = withDefaults(defineProps<{ value: string | number; unit?: string; animate?: boolean; haptic?: boolean }>(), {
  unit: '',
  animate: true,
  haptic: false,
})

const label = computed(() => [String(props.value), props.unit].filter((part) => part !== '').join(' '))

onMounted(() => {
  if (props.haptic) useTelegram().haptic('medium')
})
</script>

<template>
  <div
    class="stamp relative flex size-[92px] shrink-0 flex-col items-center justify-center rounded-full text-muhr"
    :class="{ 'stamp--enter': animate }"
    role="img"
    :aria-label="label"
    data-testid="stamp"
  >
    <svg class="absolute inset-0" width="92" height="92" viewBox="0 0 92 92" fill="none" stroke="currentColor" aria-hidden="true">
      <circle cx="46" cy="46" r="44.75" stroke-width="2.5" data-ring="outer" />
      <circle cx="46" cy="46" r="38" stroke-width="1" stroke-dasharray="2 3" data-ring="inner" />
    </svg>
    <span class="relative font-display text-[28px] leading-8 font-semibold tracking-[-0.02em] tabular-nums" aria-hidden="true" data-testid="stamp-value">{{ value }}</span>
    <span
      v-if="unit"
      class="relative -mt-0.5 max-w-[64px] truncate font-display text-[11px] leading-[14px] font-medium tracking-[0.06em] text-accent uppercase"
      aria-hidden="true"
      data-testid="stamp-unit"
    >{{ unit }}</span>
  </div>
</template>
