<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { TabId } from '@/router'

/**
 * Bottom navigation with the four tabs of docs/14 §5.1: Today, Course, League, Profile (kit Muhrla 2026-11-27, COMPONENTS.md "TabBar").
 *
 * No props. The active tab is `route.meta.tab` (set per route in `router/index.ts`), so inner screens
 * (a course, the settings) keep their tab highlighted. Every tab is a 24 px icon over its label (Label/S); the active one has the label
 * semibold in ink, the icon with a 2 px stroke and, under the label, a 16 x 3 mark in the muhr colour, and `aria-current="page"`;
 * the others are in the secondary colour. Switching to another tab gives a selection haptic. The navigation landmark is named
 * (`aria-label`, "Sections"): a screen reader lists it next to the other `nav` of the screen (the Courses | Decks switch).
 *
 * The icons are the Lucide drawings of the kit (`house`, `book-open`, `trophy`, `user`), inline, `currentColor`.
 *
 * Fixed to the bottom: `--app-tabbar-h` (64) + the home-indicator inset high, the surface colour with a hairline on top (flat: no blur,
 * no shadow). App.vue shows it only for routes that have `meta.tab` and pads the content under it.
 */
interface Tab {
  id: TabId
  to: RouteLocationRaw
  label: StringKey
  /** The `d` of each `<path>` of the icon (24 × 24 view box); `circle` is given as a path too. */
  paths: readonly string[]
}

const TABS: readonly Tab[] = [
  // Lucide house
  {
    id: 'today',
    to: { name: 'today' },
    label: 'nav.today',
    paths: ['M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8', 'M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
  },
  // Lucide book-open; the tab opens the learner's own course (the catalog without one), docs/14 §6.5
  {
    id: 'courses',
    to: { name: 'my-course' },
    label: 'nav.courses',
    paths: ['M12 5v16', 'M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z'],
  },
  // Lucide trophy
  {
    id: 'rating',
    to: { name: 'league' },
    label: 'nav.rating',
    paths: [
      'M10 14.66V17a1 1 0 0 1-1 1 2 2 0 0 0-2 2v2',
      'M14 14.66V17a1 1 0 0 0 1 1 2 2 0 0 1 2 2v2',
      'M17.916 10H19.5A2.5 2.5 0 0 0 22 7.5V5a1 1 0 0 0-1-1h-3',
      'M4 22h16',
      'M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z',
      'M6.084 10H4.5A2.5 2.5 0 0 1 2 7.5V5a1 1 0 0 1 1-1h3',
    ],
  },
  // Lucide user
  { id: 'profile', to: { name: 'profile' }, label: 'nav.profile', paths: ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M8 7a4 4 0 1 0 8 0a4 4 0 1 0-8 0'] },
]

const route = useRoute()
const { selectionHaptic } = useTelegram()
const { t } = useI18n()

const activeTab = computed(() => route.meta.tab ?? null)

function onSelect(tab: Tab): void {
  if (tab.id !== activeTab.value) selectionHaptic()
}
</script>

<template>
  <nav
    role="navigation"
    :aria-label="t('nav.label')"
    class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-surface pb-[env(safe-area-inset-bottom,0px)]"
    data-testid="tab-bar"
  >
    <ul class="mx-auto grid h-[var(--app-tabbar-h)] max-w-xl grid-cols-4 px-2">
      <li v-for="tab in TABS" :key="tab.id" class="min-w-0">
        <RouterLink
          :to="tab.to"
          class="relative flex h-full flex-col items-center justify-center gap-0.5 rounded-option px-1 pb-1.5 text-[13px] leading-[18px] transition-colors duration-200"
          :class="activeTab === tab.id ? 'font-semibold text-body' : 'font-medium text-muted'"
          :aria-current="activeTab === tab.id ? 'page' : undefined"
          :data-testid="`tab-${tab.id}`"
          @click="onSelect(tab)"
        >
          <svg
            aria-hidden="true"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            :stroke-width="activeTab === tab.id ? 2 : 1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="shrink-0"
            :data-icon="tab.id"
          >
            <path v-for="(d, i) in tab.paths" :key="i" :d="d" />
          </svg>
          <span class="max-w-full truncate">{{ t(tab.label) }}</span>
          <!-- The mark of the active tab: 16 x 3 in the muhr colour, under the label. -->
          <span
            v-if="activeTab === tab.id"
            class="absolute bottom-1.5 left-1/2 h-[3px] w-4 -translate-x-1/2 rounded-full bg-muhr"
            aria-hidden="true"
            data-testid="tab-mark"
          />
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
