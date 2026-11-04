<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/icons'
import { useI18n } from '@/i18n'
import type { GameRunEvent } from '@/api/gameRunTypes'

/**
 * The banner of an event of a run (docs/18 §12.3: a sandstorm, an oasis, a bazaar) before the task that carries it. The screen shows it
 * for about a second and a half and then the task; it is a live region, so a screen reader reads it as it appears.
 */
const props = defineProps<{ event: GameRunEvent }>()

const { t } = useI18n()

const ICONS: Record<GameRunEvent, IconName> = { sandstorm: 'speaker-wave', oasis: 'sparkles', bazaar: 'gift' }
const icon = computed((): IconName => ICONS[props.event])
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center" role="status" aria-live="polite" :data-event="event" data-testid="event-banner">
    <span class="mb-1 flex size-16 items-center justify-center rounded-full bg-warning-soft text-warning-ink">
      <AppIcon :name="icon" :size="32" />
    </span>
    <h2 class="text-[22px] leading-7 font-bold" data-testid="event-title">{{ t(`gameRun.event.${event}`) }}</h2>
    <p class="text-[15px] leading-5 text-tg-hint" data-testid="event-text">{{ t(`gameRun.event.${event}_text`) }}</p>
  </div>
</template>
