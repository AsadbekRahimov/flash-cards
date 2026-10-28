<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, type RouteLocationRaw } from 'vue-router'
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { TabId } from '@/router'

/**
 * Bottom navigation with the four tabs of docs/14 §5.1: Home, Courses, Rating, Profile.
 *
 * No props. The active tab is `route.meta.tab` (set per route in `router/index.ts`), so inner screens
 * (a course, the settings) keep their tab highlighted. The active tab gets the solid icon, the accent colour and
 * `aria-current="page"`. Switching to another tab gives a selection haptic. The navigation landmark is named
 * (`aria-label`, "Sections"): a screen reader lists it next to the other `nav` of the screen (the Courses | Decks switch).
 *
 * Fixed to the bottom: `--app-tabbar-h` + the home-indicator inset high, a translucent bar background with blur
 * and a hairline on top. App.vue shows it only for routes that have `meta.tab` and pads the content under it.
 */
interface Tab {
  id: TabId
  to: RouteLocationRaw
  icon: IconName
  label: StringKey
}

const TABS: readonly Tab[] = [
  { id: 'today', to: { name: 'today' }, icon: 'home', label: 'nav.today' },
  { id: 'courses', to: { name: 'courses' }, icon: 'academic-cap', label: 'nav.courses' },
  { id: 'rating', to: { name: 'league' }, icon: 'trophy', label: 'nav.rating' },
  { id: 'profile', to: { name: 'profile' }, icon: 'user-circle', label: 'nav.profile' },
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
    class="fixed inset-x-0 bottom-0 z-40 border-t bg-[color-mix(in_srgb,var(--tg-bottom-bar-bg)_88%,transparent)] pb-[env(safe-area-inset-bottom,0px)] backdrop-blur-xl"
    data-testid="tab-bar"
  >
    <ul class="mx-auto flex h-[var(--app-tabbar-h)] max-w-xl">
      <li v-for="tab in TABS" :key="tab.id" class="min-w-0 flex-1">
        <RouterLink
          :to="tab.to"
          class="flex h-full flex-col items-center justify-center gap-0.5 text-[11px] leading-[14px] font-medium transition-colors duration-150"
          :class="activeTab === tab.id ? 'text-accent' : 'text-tg-hint'"
          :aria-current="activeTab === tab.id ? 'page' : undefined"
          :data-testid="`tab-${tab.id}`"
          @click="onSelect(tab)"
        >
          <AppIcon :name="tab.icon" :solid="activeTab === tab.id" :size="24" />
          <span class="max-w-full truncate px-1">{{ t(tab.label) }}</span>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
