<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import MascotArt from './MascotArt.vue'
import type { IconName } from './icons'
import type { MascotPose } from '@/composables/illustrations'

/**
 * "Nothing here yet" of the kit Muhrla (SCREENS.md "Bo'sh holatlar", ASSETS.md): the mascot, a short text and an optional action, on
 * the centre axis.
 *
 * - `mascot` — the pose of the mascot above the text (ASSETS.md: `hello` for the empty states, `reading` for an empty dictionary,
 *   `trophy` for a league that has not started). Not given: `hello` unless the caller chose an `icon` (a lock of a closed feature keeps
 *   its meaning). `false` — no mascot (a small empty part inside a list, where a 96 px picture would be too much).
 * - `icon` — Heroicons / Lucide name shown above the text when there is no mascot (plain, in the secondary colour: no circle).
 * - `title` — optional line above `text` (Title/M of the kit).
 * - `text` — the explanation, already localized.
 * - slot `action` — the button that fixes the emptiness (`btn-primary` when it is the one action of the screen, else `btn-secondary`).
 *
 * `data-testid="empty"` on the root (a parent's `data-testid` replaces it).
 *
 * @example
 * <EmptyState :title="t('league.empty.title')" :text="t('league.empty.text')" mascot="trophy" />
 * <EmptyState :mascot="false" icon="users" :text="t('friends.list.empty')" />
 */
const props = withDefaults(
  defineProps<{
    icon?: IconName
    title?: string
    text: string
    mascot?: MascotPose | false
  }>(),
  // `undefined` keeps "not given" apart from `false` (Vue would turn an absent `MascotPose | false` prop into `false`).
  { icon: undefined, title: undefined, mascot: undefined },
)

const pose = computed((): MascotPose | null => {
  if (props.mascot === false) return null
  return props.mascot ?? (props.icon ? null : 'hello')
})
</script>

<template>
  <div class="flex flex-col items-center gap-2 px-6 py-8 text-center" data-testid="empty">
    <MascotArt v-if="pose" :pose="pose" :height="96" class="mb-2" />
    <AppIcon v-else-if="icon" :name="icon" :size="28" class="mb-1 text-muted" />
    <h2 v-if="title" class="t-title-m">{{ title }}</h2>
    <p class="t-body-m text-muted">{{ text }}</p>
    <div v-if="$slots.action" class="mt-3">
      <slot name="action" />
    </div>
  </div>
</template>
