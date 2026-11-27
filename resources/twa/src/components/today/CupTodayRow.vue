<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'

/**
 * The row of the friends' cup in the list "For today" (docs/33 §7.1), a row of the kit's ListRow: the plate with a trophy and the status
 * dot in its corner, "Cup «Group»", the state of today's round as the server has it (`GET /cups` → `me.today.state`) and the call to act -
 * only when there is something to do. The texts are built by `TodayView` (`cupCard`); the row only draws them.
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
    class="flex min-h-[60px] items-center gap-3 px-3 py-2.5 active:bg-fill"
    :data-state="card.state"
    :data-played="card.state === 'done' ? 'true' : undefined"
    data-testid="cup-card"
  >
    <!-- The plate of the kit's ListRow with the status dot in its corner. -->
    <span class="icon-tile relative">
      <AppIcon name="trophy" :size="20" />
      <span class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full ring-2 ring-surface" :class="card.dot" aria-hidden="true" />
    </span>
    <span class="flex min-w-0 flex-1 flex-col">
      <span class="t-body-m-strong truncate" data-testid="cup-card-title">{{ card.title }}</span>
      <span class="t-label-s text-muted" data-testid="cup-card-text">{{ card.text }}</span>
    </span>
    <span v-if="card.action" class="t-label-s shrink-0 font-semibold text-accent" data-testid="cup-card-btn">{{ card.action }}</span>
  </RouterLink>
</template>
