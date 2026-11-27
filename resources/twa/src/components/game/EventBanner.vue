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
  <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center short:py-4" role="status" aria-live="polite" :data-event="event" data-testid="event-banner">
    <!-- A calm note in the middle of the screen: the sign of the event in the accent, no coloured disc under it. -->
    <AppIcon :name="icon" :size="32" class="mb-1 text-accent" />
    <h2 class="t-display-m" data-testid="event-title">{{ t(`gameRun.event.${event}`) }}</h2>
    <p class="t-body-m text-muted" data-testid="event-text">{{ t(`gameRun.event.${event}_text`) }}</p>
  </div>
</template>
