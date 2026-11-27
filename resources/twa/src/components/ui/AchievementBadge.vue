<script setup lang="ts">
import { computed } from 'vue'
import { achievementArtImage, type AchievementArt } from '@/composables/illustrations'

/**
 * AchievementBadge of the kit Muhrla (COMPONENTS.md, achievement-badge.png): a column 104 px wide, centred — the 88 x 88 art, the title
 * (13 / 600) and the meta (12) under it; in progress also a 64 x 4 bar (sunken track, muhr fill).
 *
 * - `art` — the picture of the kit (`first-lesson`, `streak-7`, ...); without one the slot `fallback` is drawn in its place (an icon of
 *   the app for the achievements the kit has no picture for).
 * - `state` — `unlocked` (the coloured art), `progress` (the coloured art with the bar), `locked` (the grey art, the title in the
 *   secondary colour).
 * - `title`, `meta` — localized; `progress` — 0..100, for `progress`.
 * The grid of the kit is 3 columns, `justify-content: space-between`; the caller lays it out.
 *
 * @example <AchievementBadge art="streak-7" state="progress" :progress="57" :title="item.title" :meta="t('achievements.progress', p)" />
 */
const props = withDefaults(
  defineProps<{ art?: AchievementArt | null; state: 'unlocked' | 'progress' | 'locked'; title: string; meta?: string; progress?: number }>(),
  { art: null, meta: undefined, progress: 0 },
)

const src = computed(() => (props.art ? achievementArtImage(props.art, props.state === 'locked') : null))
const percent = computed(() => (Number.isFinite(props.progress) ? Math.round(Math.min(100, Math.max(0, props.progress))) : 0))
</script>

<template>
  <div class="flex w-[104px] flex-col items-center text-center" :data-state="state" data-testid="achievement-badge">
    <img v-if="src" :src="src" width="88" height="88" alt="" loading="lazy" decoding="async" draggable="false" class="size-[88px] select-none" />
    <span v-else class="flex size-[88px] items-center justify-center rounded-full bg-sunken text-muted" aria-hidden="true"><slot name="fallback" /></span>
    <span class="mt-2 text-[13px] leading-[18px] font-semibold" :class="state === 'locked' ? 'text-muted' : 'text-body'">{{ title }}</span>
    <span v-if="state === 'progress'" class="progress progress-muhr mt-1.5 w-16" aria-hidden="true" data-testid="achievement-progress">
      <span class="progress-fill block" :style="{ width: `${percent}%` }" />
    </span>
    <span v-if="meta" class="mt-1 text-xs leading-4 text-muted">{{ meta }}</span>
  </div>
</template>
