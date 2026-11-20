<script setup lang="ts">
import { computed } from 'vue'
import FlaskIcons from '@/components/game/FlaskIcons.vue'
import NumberText from '@/components/game/NumberText.vue'
import { NUMBER_MARK } from '@/components/game/numberMark'
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
  <header class="px-5 pt-3 short:pt-1.5" data-testid="run-header">
    <!-- The header of the lesson (docs/design Question): ✕, a 3 px line in the primary colour, "3 / 10" in Plex Mono; under it the run's own row. -->
    <div class="flex items-center gap-3.5">
      <button type="button" class="-ml-1 flex size-11 shrink-0 items-center justify-center text-muted" :aria-label="t('common.close')" data-testid="close-btn" @click="$emit('close')">
        <AppIcon name="x-mark" :size="20" />
      </button>
      <div
        class="progress h-[3px] flex-1"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="percent"
        :aria-label="t('gameRun.progress', { done: position, total })"
        data-testid="progress-bar"
      >
        <div class="progress-fill progress-primary" :style="{ width: `${percent}%` }" />
      </div>
      <span class="min-w-12 text-right font-mono text-[13px] leading-[18px] text-muted" :class="{ invisible: total === 0 }" data-testid="position">
        {{ position }} / {{ total }}
      </span>
    </div>

    <div class="mt-0.5 flex min-h-7 items-center justify-between gap-2">
      <!-- A fight with a boss has no flasks: nothing to draw (and nothing for a screen reader to read as "0 of 0"). -->
      <FlaskIcons v-if="flasksMax > 0" :flasks="flasks" :max="flasksMax" />
      <span v-else />
      <NumberText v-if="combo >= 3" class="chip chip-accent" :text="t('gameRun.combo', { count: NUMBER_MARK })" :value="combo" data-testid="combo" />
      <span class="font-mono text-[15px] leading-5 font-medium" :aria-label="`${t('gameRun.score')}: ${score}`" data-testid="score">{{ score }}</span>
    </div>

    <p v-if="caption" class="mt-0.5 text-center text-[13px] leading-[18px] text-muted short:hidden" data-testid="caption">{{ caption }}</p>
  </header>
</template>
