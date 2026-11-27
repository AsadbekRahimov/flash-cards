<script setup lang="ts">
import AppIcon from './AppIcon.vue'

/**
 * A step of the plan of the day (kit Muhrla, COMPONENTS.md "PlanStep"): a 28 px circle and the title with its meta under it.
 *
 * - `state` — `done` (the success circle with a check), `current` (the muhr circle with the number), `upcoming` (an outlined circle
 *   with the number; the title in the secondary colour).
 * - `number` — the place of the step (1, 2, 3); `title`, `meta` — already localized.
 * The state is told in words for a screen reader only by the caller (the title says what the step is); the check and the number
 * are decoration here. Slot `trailing` — a button or a chip on the right.
 *
 * @example <PlanStep state="current" :number="1" :title="t('today.plan.lesson')" :meta="meta" />
 */
defineProps<{ state: 'done' | 'current' | 'upcoming'; number: number; title: string; meta?: string }>()
</script>

<template>
  <div class="flex items-center gap-3 py-2" :data-state="state" data-testid="plan-step">
    <span
      class="flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] leading-[18px] font-semibold tabular-nums"
      :class="{
        'bg-success text-on-primary': state === 'done',
        'bg-muhr text-on-muhr': state === 'current',
        'text-muted shadow-[inset_0_0_0_1.5px_var(--app-hairline)]': state === 'upcoming',
      }"
      aria-hidden="true"
      data-testid="plan-step-circle"
    >
      <AppIcon v-if="state === 'done'" name="check" :size="16" />
      <template v-else>{{ number }}</template>
    </span>
    <span class="min-w-0 flex-1">
      <span class="block text-[17px] leading-6 font-semibold" :class="state === 'upcoming' ? 'text-muted' : 'text-body'">{{ title }}</span>
      <span v-if="meta" class="block text-[15px] leading-[22px] text-muted">{{ meta }}</span>
    </span>
    <slot name="trailing" />
  </div>
</template>
