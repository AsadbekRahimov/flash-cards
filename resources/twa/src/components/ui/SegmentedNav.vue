<script lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface SegmentedNavItem {
  to: RouteLocationRaw
  /** Already localized. */
  label: string
  /** `data-testid` of the link. */
  testid?: string
}
</script>

<script setup lang="ts">
import { RouterLink } from 'vue-router'

/**
 * Switch between neighbouring screens of one tab, drawn like `SegmentedControl` (Courses | My decks, League | Friends).
 * Each item is a link; the one whose route matches the current route is highlighted and gets `aria-current="page"`.
 *
 * - `items` — `{ to, label, testid? }[]`.
 *
 * @example <SegmentedNav :items="[{ to: { name: 'courses' }, label: t('catalog.title') }, { to: { name: 'decks' }, label: t('decks.title') }]" />
 */
defineProps<{ items: SegmentedNavItem[] }>()
</script>

<template>
  <nav class="flex gap-0.5 rounded-[10px] bg-fill p-0.5 shadow-[inset_0_0_0_1px_var(--app-hairline)]">
    <RouterLink v-for="(item, index) in items" :key="index" v-slot="{ href, navigate, isActive }" :to="item.to" custom>
      <a
        :href="href"
        :aria-current="isActive ? 'page' : undefined"
        :data-testid="item.testid"
        class="flex min-h-9 min-w-0 flex-1 items-center justify-center rounded-lg px-3 text-center text-sm leading-5 transition-colors duration-150"
        :class="
          isActive
            ? 'bg-surface font-semibold text-tg-text shadow-[0_1px_3px_rgb(0_0_0/0.12),0_0_0_1px_var(--app-hairline)]'
            : 'font-medium text-tg-subtitle'
        "
        @click="navigate"
      >
        <span class="block truncate">{{ item.label }}</span>
      </a>
    </RouterLink>
  </nav>
</template>
