<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AccountRestoredSheet from '@/components/AccountRestoredSheet.vue'
import AppToast from '@/components/ui/AppToast.vue'
import TabBar from '@/components/ui/TabBar.vue'

const route = useRoute()

/** The bottom navigation is for screens that belong to a tab (docs/14 §5.1); training, exam and the landing have none. */
const showTabBar = computed(() => route.meta.tab !== undefined)

/**
 * The move between screens (kit Muhrla, ANIMATIONS.md §7): a tab root fades in (160 ms), an inner screen fades in a little slower
 * (260 ms). Only the opacity: a transform on <main> would make it the containing block of the fixed sheets and sticky buttons of the
 * screen while it runs, and nothing is moved, so the scroll and the safe zones stay as they are. Not on the first screen of the open;
 * a change of the query or the params of the same screen is no move. The class goes when its animation ends, so the next move starts
 * it again; `prefers-reduced-motion` turns it off.
 */
const enter = ref<'' | 'tab' | 'inner'>('')

watch(
  () => route.name,
  (name, previous) => {
    if (previous === undefined || name === previous) return
    enter.value = route.meta.tab !== undefined ? 'tab' : 'inner'
  },
)
</script>

<template>
  <!-- `with-tabbar` pads the content under the fixed tab bar and lifts the toast above it. -->
  <div class="flex flex-1 flex-col" :class="{ 'with-tabbar': showTabBar }" data-testid="app-shell">
    <main
      class="flex flex-1 flex-col"
      :class="enter ? `screen-enter-${enter}` : undefined"
      :data-enter="enter || undefined"
      @animationend.self="enter = ''"
    >
      <RouterView />
    </main>
    <TabBar v-if="showTabBar" />
    <AppToast />
    <AccountRestoredSheet />
  </div>
</template>

<style scoped>
.screen-enter-tab {
  animation: screen-fade 160ms var(--ease-out) both;
}
.screen-enter-inner {
  animation: screen-fade var(--dur-enter) var(--ease-out) both;
}
@keyframes screen-fade {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}
@media (prefers-reduced-motion: reduce) {
  .screen-enter-tab,
  .screen-enter-inner {
    animation: none;
  }
}
</style>
