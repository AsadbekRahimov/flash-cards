<script setup lang="ts">
import { useI18n } from '@/i18n'

/**
 * The kind of a duel of a cup (docs/33 §5.7, §7.1): the chip "Rated" / "Friendly", shown before the challenge and before the acceptance,
 * and, with `hint`, what it means - a rated duel adds a point of the cup for each of the player's own right answers (one rated duel a
 * day); a friendly one gives no points of the cup and takes one of the free battles of the day of the challenger (Free). The kind is the
 * server's (`rated_offered` / `rated`); the screen never decides it.
 */
defineProps<{
  rated: boolean
  hint?: boolean
  /** On the central axis of a hero (the duel screen); left-aligned otherwise. */
  center?: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <span class="flex flex-col gap-1" :class="center ? 'items-center text-center' : 'items-start'">
    <span class="chip" :class="rated ? 'chip-accent' : 'chip-neutral'" :data-rated="rated ? 'true' : 'false'" data-testid="duel-kind">
      {{ rated ? t('cup.duel.kind.rated') : t('cup.duel.kind.friendly') }}
    </span>
    <span v-if="hint" class="text-[13px] leading-[18px] text-muted" data-testid="duel-kind-hint">
      {{ rated ? t('cup.duel.kind.rated_hint') : t('cup.duel.kind.friendly_hint') }}
    </span>
  </span>
</template>
