<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import type { IconName } from '@/components/ui/icons'
import type { MascotPose } from '@/composables/illustrations'

/**
 * "This session is over" instead of the training / exam content (a 410 from the server), and the centred hero of a state that is not an
 * error (kit Muhrla, DESIGN.md §8: illustration → title → text on the central axis): a quiet icon (no circle under it) or the mascot, an
 * optional title (Title/M), an optional explanation and the buttons in the default slot (one `btn-primary` at most). Centered in the free height
 * of the screen. `data-testid="closed"` on the root (a parent's `data-testid` replaces it).
 *
 * - `icon` — a Lucide icon over the title (28 px, the secondary colour).
 * - `mascot` — the mascot instead of the icon (kit: `hello` / `thinking` for waiting, a closed door, an empty list; never on a loss).
 *   It enters once (MascotArt); in a short window it is smaller, so the buttons stay in view.
 *
 * @example
 * <ClosedState icon="lock-closed" :title="t('training.closed_title')" :text="errorMessage">
 *   <button class="btn btn-primary btn-block" @click="close()">…</button>
 * </ClosedState>
 * <ClosedState mascot="hello" :title="t('cup.list.title')" :text="t('cup.list.empty')" />
 */
defineProps<{
  icon?: IconName
  mascot?: MascotPose
  title?: string
  text?: string
}>()
</script>

<template>
  <div class="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center short:py-6" data-testid="closed">
    <MascotArt v-if="mascot" :pose="mascot" :height="140" class="mb-2 short:h-24 short:w-auto" />
    <AppIcon v-else-if="icon" :name="icon" :size="28" class="mb-1 text-muted" />
    <h2 v-if="title" class="t-title-m break-words" data-testid="closed-title">{{ title }}</h2>
    <p v-if="text" class="t-body-m text-muted">{{ text }}</p>
    <div v-if="$slots.default" class="mt-3 flex w-full max-w-64 flex-col gap-2">
      <slot />
    </div>
  </div>
</template>
