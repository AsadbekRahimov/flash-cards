<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue'
import { GAME_RUN_RULES } from '@/composables/gameRunRules'
import { useI18n } from '@/i18n'

/** "How to play Karvon": what the map is, what a run is, what the rewards are. Opened on the first visit of the map and by "How to play". */
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const rules = { min: GAME_RUN_RULES.tasksMin, max: GAME_RUN_RULES.tasksMax, flasks: GAME_RUN_RULES.flasks }
</script>

<template>
  <BottomSheet :label="t('gameIntro.title')" data-testid="game-intro-sheet" @close="emit('close')">
    <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('gameIntro.title') }}</h2>
    <ul class="space-y-2 text-[15px] leading-5 text-tg-subtitle">
      <li data-testid="game-intro-p1">{{ t('gameIntro.p1') }}</li>
      <li data-testid="game-intro-p2">{{ t('gameIntro.p2', rules) }}</li>
      <li data-testid="game-intro-p3">{{ t('gameIntro.p3') }}</li>
    </ul>
    <button type="button" class="btn btn-primary btn-block" data-testid="game-intro-ok" @click="emit('close')">
      {{ t('gameIntro.ok') }}
    </button>
  </BottomSheet>
</template>
