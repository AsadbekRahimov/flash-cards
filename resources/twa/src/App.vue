<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type WatchStopHandle } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import AccountRestoredSheet from '@/components/AccountRestoredSheet.vue'
import OfflineScreen from '@/components/OfflineScreen.vue'
import AppToast from '@/components/ui/AppToast.vue'
import TabBar from '@/components/ui/TabBar.vue'
import { mascotImage, prefetchImage } from '@/composables/illustrations'
import { offlineContext, useOnline } from '@/composables/useOnline'
import { useI18n } from '@/i18n'

const route = useRoute()

/** The bottom navigation is for screens that belong to a tab (docs/14 §5.1); training, exam and the landing have none. */
const showTabBar = computed(() => route.meta.tab !== undefined)

/**
 * The move between screens (kit Muhrla, ANIMATIONS.md §7): a tab root fades in (160 ms), an inner screen fades in a little slower
 * (260 ms). Only the opacity: a transform on <main> would make it the containing block of the fixed sheets and sticky buttons of the
 * screen while it runs, and nothing is moved, so the scroll and the safe zones stay as they are. Not on the first screen of the open;
 * a change of the query or the params of the same screen is no move. The class goes when its animation ends, so the next move starts
 * it again; under `prefers-reduced-motion` the class is not put at all (and the CSS turns the animation off too).
 */
const enter = ref<'' | 'tab' | 'inner'>('')

watch(
  () => route.name,
  (name, previous) => {
    if (previous === undefined || name === previous) return
    // Without motion there is no animation, so no `animationend` would ever take the class off again: no class at all then.
    if (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    enter.value = route.meta.tab !== undefined ? 'tab' : 'inner'
  },
)

/**
 * F5 "Нет сети" (docs/40 §2.6): over the whole app when the device says it is offline, or when a load of an ordinary screen was lost to
 * the network (`navigator.onLine` is not to be trusted). An immersive screen (lesson, exam, battle, run) keeps its own error state for a
 * lost request and gets F5 only when the device is offline; the lesson tells F5 what to retry and what is saved (`useOfflineContext`).
 */
const { t } = useI18n()
const { isOnline, loadFailed, clearLoadFailed, recheck } = useOnline()
const immersive = computed(() => route.meta.immersive === true)
const offline = computed(() => !isOnline.value || (loadFailed.value && !immersive.value))

/** The chip of F5 only when it is true: what the screen says, else - on a tab - that the progress is on the server (it always is there). */
const offlineChip = computed((): string | null => {
  if (offlineContext.value) return offlineContext.value.chip()
  return route.meta.tab !== undefined ? t('offline.chip_server') : null
})

const offlinePending = computed((): boolean => offlineContext.value?.pendingAnswer() ?? false)

/** Bumped to load the screen of the route again (a new instance asks the server for its data). */
const viewKey = ref(0)

/** The screen's own retry; an ordinary screen whose load was lost is loaded again; an immersive one without a retry keeps its state. */
function retryScreen(): void {
  if (offlineContext.value) {
    offlineContext.value.retry()
  } else if (loadFailed.value && !immersive.value) {
    clearLoadFailed()
    viewKey.value += 1
  }
}

function onOfflineRetry(): void {
  // A press on an ordinary screen always loads it again: the screen under F5 may have failed before the device went offline.
  if (!offlineContext.value && !immersive.value) {
    recheck()
    clearLoadFailed()
    viewKey.value += 1
    return
  }
  const was = isOnline.value
  recheck()
  // The device says it is back: the watcher below repeats what was lost, once.
  if (!was && isOnline.value) return
  retryScreen()
}

// The network is back: what was lost is repeated without a press, and F5 goes.
watch(isOnline, (online, was) => {
  if (online && !was) retryScreen()
})

// A new screen starts clean: a load lost on the previous one says nothing about this one.
watch(() => route.fullPath, () => clearLoadFailed())

const router = useRouter()
let stopStreakMoments: WatchStopHandle | null = null
let unmounted = false

onMounted(() => {
  // The mascot of F5 is needed exactly when nothing can be loaded: it is fetched into the cache once, in an idle moment after the start.
  prefetchImage(mascotImage('thinking'))
  // F4 "Серия спасена / прервалась" (docs/40 §2.5): opened by itself once per event, from the `/today` the screen "Today" has loaded.
  // A lazy module: the watch and the store of "Today" stay out of the main chunk; it starts with what the store already holds.
  void import('@/composables/useStreakMoments').then(({ watchStreakMoments }) => {
    if (!unmounted) stopStreakMoments = watchStreakMoments(router)
  })
})

onBeforeUnmount(() => {
  unmounted = true
  stopStreakMoments?.()
})
</script>

<template>
  <!-- `with-tabbar` pads the content under the fixed tab bar and lifts the toast above it. -->
  <div class="flex flex-1 flex-col" :class="{ 'with-tabbar': showTabBar }" data-testid="app-shell">
    <main
      class="flex flex-1 flex-col"
      :class="enter ? `screen-enter-${enter}` : undefined"
      :data-enter="enter || undefined"
      :inert="offline || undefined"
      @animationend.self="enter = ''"
    >
      <RouterView :key="viewKey" />
    </main>
    <TabBar v-if="showTabBar" :inert="offline || undefined" />
    <AppToast />
    <AccountRestoredSheet />
    <OfflineScreen v-if="offline" :chip="offlineChip" :pending-answer="offlinePending" @retry="onOfflineRetry" />
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
