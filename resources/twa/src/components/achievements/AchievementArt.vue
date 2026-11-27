<script lang="ts">
import type { IconName } from '@/components/ui/icons'

/**
 * The icon of an achievement the kit has no picture for (illustrations.ts `ACHIEVEMENT_ART_BY_KEY`): the Lucide drawing closest to its
 * meaning; an unknown key of a newer server gets the award.
 */
const FALLBACK_ICONS: Readonly<Record<string, IconName>> = {
  streak_30: 'fire',
  streak_100: 'fire',
  words_500: 'book-open',
  words_1000: 'book-open',
  first_friend: 'users',
  game_first_boss: 'swords',
  long_recall_25: 'clock',
}

export function achievementIcon(key: string): IconName {
  return Object.prototype.hasOwnProperty.call(FALLBACK_ICONS, key) ? (FALLBACK_ICONS[key] as IconName) : 'award'
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { achievementImage } from '@/composables/illustrations'

/**
 * The round art of an achievement (kit Muhrla, ASSETS.md "Achievement"): the picture of the kit (`achievements/<name>[-locked].webp`)
 * when it has one for the key, else one look for all the others, so a grid of them stays even — a circle of the same size with a thick
 * ring and a thin dashed one inside, like the stamps of the kit, and a Lucide icon in the middle: the soft accent and the muhr rings
 * while it is received, the sunken paper and grey rings while it is ahead.
 *
 * - `itemKey` — the key of the server (`first_session`, `streak_30`, ...).
 * - `locked` — not received yet: the grey picture, or the grey fallback.
 * - `size` — px, default 88 (the grid); 112 for the hero of the Achievements screen.
 *
 * Decorative: the title is written next to it.
 *
 * @example <AchievementArt :item-key="item.key" :locked="item.unlocked_at === null" :size="112" />
 */
const props = withDefaults(defineProps<{ itemKey: string; locked?: boolean; size?: number }>(), { locked: false, size: 88 })

const src = computed(() => achievementImage(props.itemKey, props.locked))
const icon = computed(() => achievementIcon(props.itemKey))
/** The rings and the icon grow with the circle: 88 -> 5 px ring, 36 px icon. */
const ring = computed(() => Math.max(3, Math.round(props.size / 18)))
</script>

<template>
  <img
    v-if="src"
    :src="src"
    :width="size"
    :height="size"
    alt=""
    loading="lazy"
    decoding="async"
    draggable="false"
    class="shrink-0 select-none"
    :style="{ width: `${size}px`, height: `${size}px` }"
    data-testid="achievement-art"
  />
  <span
    v-else
    class="relative inline-flex shrink-0 items-center justify-center rounded-full border-solid"
    :class="locked ? 'border-tertiary/45 bg-sunken text-muted' : 'border-muhr bg-accent-soft text-accent'"
    :style="{ width: `${size}px`, height: `${size}px`, borderWidth: `${ring}px` }"
    aria-hidden="true"
    :data-locked="locked ? 'true' : 'false'"
    data-testid="achievement-fallback"
  >
    <span
      class="pointer-events-none absolute rounded-full border border-dashed"
      :class="locked ? 'border-tertiary/45' : 'border-muhr'"
      :style="{ inset: `${ring}px` }"
    />
    <AppIcon :name="icon" :size="Math.round(size * 0.4)" />
  </span>
</template>
