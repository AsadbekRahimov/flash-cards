<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppToast from '@/components/ui/AppToast.vue'
import TabBar from '@/components/ui/TabBar.vue'

const route = useRoute()

/** The bottom navigation is for screens that belong to a tab (docs/14 §5.1); training, exam and the landing have none. */
const showTabBar = computed(() => route.meta.tab !== undefined)
</script>

<template>
  <!-- `with-tabbar` pads the content under the fixed tab bar and lifts the toast above it. -->
  <div class="flex flex-1 flex-col" :class="{ 'with-tabbar': showTabBar }" data-testid="app-shell">
    <main class="flex flex-1 flex-col">
      <RouterView />
    </main>
    <TabBar v-if="showTabBar" />
    <AppToast />
  </div>
</template>
