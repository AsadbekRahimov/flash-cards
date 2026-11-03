<script setup lang="ts">
import { computed } from 'vue'
import FlaskIcons from '@/components/game/FlaskIcons.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useI18n } from '@/i18n'

/**
 * The header of a run (docs/05 §5b.3): ✕, the position "3 / 10" with a bar under it, the flasks, the score and - from the third answer
 * in a row - the combo. ✕ is a 44 px target; the rest only tells.
 */
const props = defineProps<{
  position: number
  total: number
  flasks: number
  flasksMax: number
  score: number
  combo: number
  /** The line under the bar: the city and the lesson. */
  caption?: string
}>()

defineEmits<{ close: [] }>()

const { t } = useI18n()

const percent = computed((): number => (props.total > 0 ? Math.min(100, Math.max(0, Math.round(((props.position - 1) / props.total) * 100))) : 0))
</script>

<template>
  <header class="px-4 pt-3" data-testid="run-header">
    <div class="flex items-center gap-2">
      <button type="button" class="-ml-1 flex size-11 shrink-0 items-center justify-center" :aria-label="t('common.close')" data-testid="close-btn" @click="$emit('close')">
        <span class="flex size-9 items-center justify-center rounded-full bg-track text-tg-subtitle">
          <AppIcon name="x-mark" :size="20" />
        </span>
      </button>
      <div
        class="progress flex-1"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="percent"
        :aria-label="t('gameRun.progress', { done: position, total })"
        data-testid="progress-bar"
      >
        <div class="progress-fill" :style="{ width: `${percent}%` }" />
      </div>
      <span class="min-w-14 text-right text-[15px] leading-5 font-medium text-tg-subtitle tabular-nums" :class="{ invisible: total === 0 }" data-testid="position">
        {{ position }} / {{ total }}
      </span>
    </div>

    <div class="mt-1 flex items-center justify-between gap-2">
      <FlaskIcons :flasks="flasks" :max="flasksMax" />
      <span v-if="combo >= 3" class="chip chip-warning" data-testid="combo">{{ t('gameRun.combo', { count: combo }) }}</span>
      <span class="text-[15px] leading-5 font-semibold tabular-nums" :aria-label="`${t('gameRun.score')}: ${score}`" data-testid="score">{{ score }}</span>
    </div>

    <p v-if="caption" class="mt-1 text-center text-[13px] leading-[18px] font-medium text-tg-subtitle" data-testid="caption">{{ caption }}</p>
  </header>
</template>
