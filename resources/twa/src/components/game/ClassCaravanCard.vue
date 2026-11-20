<script setup lang="ts">
import { computed } from 'vue'
import NumberText from '@/components/game/NumberText.vue'
import { NUMBER_MARK } from '@/components/game/numberMark'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
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
const percent = computed((): number => (props.caravan.target_stars > 0 ? (props.caravan.stars_total / props.caravan.target_stars) * 100 : 0))
/** "12 / 40 · until Fri 18:00", the numbers marked for `NumberText`. */
const text = computed((): string => t('gameClass.card.text', { stars: NUMBER_MARK, target: NUMBER_MARK, until: until.value }))
</script>

<template>
  <RouterLink
    :to="`/game/class/${caravan.id}`"
    class="card flex min-h-11 items-center gap-3 transition-transform active:scale-[0.99] active:bg-fill"
    :aria-label="t('gameClass.card.aria', { stars: caravan.stars_total, target: caravan.target_stars, until })"
    data-testid="class-caravan-card"
  >
    <span class="flex min-w-0 flex-1 flex-col gap-1">
      <span class="block text-[15px] leading-5 font-semibold">{{ t('gameClass.title') }}</span>
      <span class="flex items-center gap-1 text-[13px] leading-[18px] text-muted">
        <AppIcon name="star" solid :size="12" class="shrink-0 text-warning" />
        <NumberText class="min-w-0 break-words" :text="text" :value="[caravan.stars_total, caravan.target_stars]" data-testid="class-caravan-text" />
      </span>
      <!-- The star track of the week, as a thin line (the numbers are written above it). -->
      <ProgressLine :value="percent" :tone="percent >= 100 ? 'success' : 'accent'" class="mt-1" />
    </span>
    <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
  </RouterLink>
</template>
