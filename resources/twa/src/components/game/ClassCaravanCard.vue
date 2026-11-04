<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { formatDate } from '@/composables/format'
import { useI18n } from '@/i18n'
import type { GameClassCard } from '@/api/gameClassTypes'

/**
 * The entry to the caravan of a class (docs/05 §5b.7): "Class caravan * N / M - until Fri 18:00" on the map and on "Today", drawn only where
 * the server sent `class_caravan` (the caller checks). The deadline is shown in the learner's time zone when it is known, else the device's.
 * It is a link: the data is already in the answer of the map / of `/today`, nothing is read here.
 */
const props = defineProps<{
  caravan: GameClassCard
  /** IANA id of the learner (`/today.timezone`); empty or null: the device's zone. */
  timezone?: string | null
}>()

const { t, locale } = useI18n()

/** "until Fri 18:00" */
const until = computed((): string => t('gameClass.until', { when: formatDate(props.caravan.ends_at, 'weekdayTime', locale.value, props.timezone ?? undefined) }))
const text = computed((): string => t('gameClass.card.text', { stars: props.caravan.stars_total, target: props.caravan.target_stars, until: until.value }))
</script>

<template>
  <RouterLink
    :to="`/game/class/${caravan.id}`"
    class="card flex min-h-11 items-center gap-3 transition-transform active:scale-[0.99]"
    :aria-label="t('gameClass.card.aria', { stars: caravan.stars_total, target: caravan.target_stars, until })"
    data-testid="class-caravan-card"
  >
    <span class="icon-tile size-10 rounded-xl tile-yellow"><AppIcon name="users" :size="20" /></span>
    <span class="min-w-0 flex-1">
      <span class="block text-[15px] leading-5 font-semibold">{{ t('gameClass.title') }}</span>
      <span class="flex items-center gap-1 text-[13px] leading-[18px] text-tg-subtitle">
        <AppIcon name="star" solid :size="12" class="shrink-0 text-warning" />
        <span class="min-w-0 break-words" data-testid="class-caravan-text">{{ text }}</span>
      </span>
    </span>
    <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
  </RouterLink>
</template>
