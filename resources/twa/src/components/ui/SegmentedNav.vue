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
 * - `ariaLabel` — the name of the `<nav>` landmark (the tab bar is another one), required. Written camelCase in a
 *   template (`:ariaLabel`), like the one of `SegmentedControl`.
 *
 * - `scrollable` — the items keep their whole labels and the row scrolls sideways when they do not fit (four panes of the Rating tab
 *   at 320 px); without it the items share the width and a long label is cut.
 *
 * Every link keeps its look but has a touch area of at least 44 px (`.tap-target`, docs/14 §3).
 *
 * @example <SegmentedNav :items="[{ to: { name: 'courses' }, label: t('catalog.title') }, { to: { name: 'decks' }, label: t('decks.title') }]" :ariaLabel="t('catalog.title')" />
 */
defineProps<{ items: SegmentedNavItem[]; ariaLabel: string; scrollable?: boolean }>()
</script>

<template>
  <nav
    :aria-label="ariaLabel"
    class="flex gap-0.5 rounded-control border border-hairline bg-surface p-0.5"
    :class="{ 'overflow-x-auto': scrollable }"
    :data-scrollable="scrollable ? 'true' : undefined"
  >
    <RouterLink v-for="(item, index) in items" :key="index" v-slot="{ href, navigate, isActive }" :to="item.to" custom>
      <a
        :href="href"
        :aria-current="isActive ? 'page' : undefined"
        :data-testid="item.testid"
        class="tap-target flex min-h-9 items-center justify-center rounded-lg px-3 text-center text-sm leading-5 transition-colors duration-150"
        :class="[
          scrollable ? 'flex-[1_0_auto] whitespace-nowrap' : 'min-w-0 flex-1',
          isActive ? 'bg-accent-soft font-semibold text-body shadow-[inset_0_0_0_1px_var(--app-primary)]' : 'text-muted',
        ]"
        @click="navigate"
      >
        <span class="block" :class="{ truncate: !scrollable }">{{ item.label }}</span>
      </a>
    </RouterLink>
  </nav>
</template>
