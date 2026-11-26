<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { TabId } from '@/router'

/**
 * Bottom navigation with the four tabs of docs/14 §5.1: Today, Course, League, Profile (docs/design/2026-11-25-redesign).
 *
 * No props. The active tab is `route.meta.tab` (set per route in `router/index.ts`), so inner screens
 * (a course, the settings) keep their tab highlighted. Every tab is an icon over its label; the active one is a "pill" (a 14 px
 * rounded block in `nav-active`) with the icon and the bold label in the accent, and `aria-current="page"`; the others are in the
 * body colour. Switching to another tab gives a selection haptic. The navigation landmark is named (`aria-label`, "Sections"): a
 * screen reader lists it next to the other `nav` of the screen (the Courses | Decks switch).
 *
 * The icons are the outline drawings of the mock-ups, inline (24 px, stroke 1.8, `currentColor`), not the shared icon set.
 *
 * Fixed to the bottom: `--app-tabbar-h` + the home-indicator inset high, the card colour with a hairline on top (flat: no blur,
 * no shadow). App.vue shows it only for routes that have `meta.tab` and pads the content under it.
 */
interface Tab {
  id: TabId
  to: RouteLocationRaw
  label: StringKey
  /** The `d` of each `<path>` of the icon (24 × 24 view box); `rect` / `circle` are given as paths too. */
  paths: readonly string[]
}

const TABS: readonly Tab[] = [
  // a calendar
  { id: 'today', to: { name: 'today' }, label: 'nav.today', paths: ['M7 6h10a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V9a3 3 0 0 1 3-3z', 'M8 3v4M16 3v4M4 11h16'] },
  // an open book; the tab opens the learner's own course (the catalog without one), docs/14 §6.5
  { id: 'courses', to: { name: 'my-course' }, label: 'nav.courses', paths: ['M12 7c-2-1.5-5-2-8-2v12c3 0 6 .5 8 2 2-1.5 5-2 8-2V5c-3 0-6 .5-8 2zM12 7v12'] },
  // a cup
  { id: 'rating', to: { name: 'league' }, label: 'nav.rating', paths: ['M8 4h8v5a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8 20h8M10 17h4'] },
  // a person
  { id: 'profile', to: { name: 'profile' }, label: 'nav.profile', paths: ['M8 8a4 4 0 1 0 8 0a4 4 0 1 0-8 0', 'M4 20c1.5-4 4.5-5 8-5s6.5 1 8 5'] },
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
    <ul class="mx-auto grid h-[var(--app-tabbar-h)] max-w-xl grid-cols-4 gap-1 px-3 py-2">
      <li v-for="tab in TABS" :key="tab.id" class="min-w-0">
        <RouterLink
          :to="tab.to"
          class="flex h-full flex-col items-center justify-center gap-[3px] rounded-option px-1 text-[13px] leading-[18px] transition-colors duration-150"
          :class="activeTab === tab.id ? 'bg-nav-active font-bold text-accent' : 'font-medium text-body'"
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
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="shrink-0"
            :data-icon="tab.id"
          >
            <path v-for="(d, i) in tab.paths" :key="i" :d="d" />
          </svg>
          <span class="max-w-full truncate">{{ t(tab.label) }}</span>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
