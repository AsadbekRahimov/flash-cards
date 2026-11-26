<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'

/**
 * The row of the friends' cup in the list "For today" (docs/33 §7.1): the status dot, "Cup «Group»", the state of today's round as the
 * server has it (`GET /cups` → `me.today.state`) and the call to act - only when there is something to do. The texts are built by
 * `TodayView` (`cupCard`); the row only draws them.
 */
export interface CupTodayCard {
  to: RouteLocationRaw
  title: string
  text: string
  /** `not_started` / `active` / `done` / `not_available`; `unknown` - an older server without `me.today`. */
  state: string
  /** The call on the right ("Play", "Continue", "Open"); null - nothing to do today. */
  action: string | null
  /** The class of the status dot. */
  dot: string
}

defineProps<{ card: CupTodayCard }>()
</script>

<template>
  <RouterLink
    :to="card.to"
    class="flex min-h-11 items-center gap-3 px-[18px] py-3 active:bg-fill"
    :data-state="card.state"
    :data-played="card.state === 'done' ? 'true' : undefined"
    data-testid="cup-card"
  >
    <span class="size-2 shrink-0 rounded-full" :class="card.dot" aria-hidden="true" />
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
      <span class="truncate text-[15px] leading-5 font-semibold" data-testid="cup-card-title">{{ card.title }}</span>
      <span class="text-[13px] leading-[18px] text-muted" data-testid="cup-card-text">{{ card.text }}</span>
    </span>
    <span v-if="card.action" class="shrink-0 text-[13px] leading-[18px] text-accent" data-testid="cup-card-btn">{{ card.action }}</span>
  </RouterLink>
</template>
