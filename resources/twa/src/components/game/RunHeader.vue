<script setup lang="ts">
import { computed } from 'vue'
import FlaskIcons from '@/components/game/FlaskIcons.vue'
import NumberText from '@/components/game/NumberText.vue'
import { NUMBER_MARK } from '@/components/game/numberMark'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import SegmentProgress from '@/components/ui/SegmentProgress.vue'
import { useI18n } from '@/i18n'

/**
 * The header of a run (docs/05 §5b.3): ✕, the segments of the run and the position "3 / 10", the flasks, the score and - from the third
 * answer in a row - the combo. ✕ is a 44 px target; the rest only tells.
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

/** The segments of the kit while each still has room to be seen (about 12 px at 320 px); a longer run is a line. */
const SEGMENTS_MAX = 20
const segmented = computed((): boolean => props.total > 0 && props.total <= SEGMENTS_MAX)
</script>

<template>
  <header class="px-5 pt-3 short:pt-1.5" data-testid="run-header">
    <!--
      The header of a lesson of the kit (04-urok-vopros): ✕, the segments of the run (passed - ink, this task - muhr, the rest - sunken)
      and "3 / 10" in tabular figures; a run longer than the segments can show stays a thin line in ink. Under it the run's own row.
    -->
    <div class="flex items-center gap-3">
      <button type="button" class="-ml-1 flex size-11 shrink-0 items-center justify-center text-muted" :aria-label="t('common.close')" data-testid="close-btn" @click="$emit('close')">
        <AppIcon name="x-mark" :size="20" />
      </button>
      <div
        class="min-w-0 flex-1"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="percent"
        :aria-label="t('gameRun.progress', { done: position, total })"
        data-testid="progress-bar"
      >
        <SegmentProgress v-if="segmented" :total="total" :current="position - 1" />
        <ProgressLine v-else :value="percent" tone="primary" />
      </div>
      <span class="min-w-12 text-right text-[15px] leading-5 text-muted tabular-nums" :class="{ invisible: total === 0 }" data-testid="position">
        {{ position }} / {{ total }}
      </span>
    </div>

    <div class="mt-1 flex min-h-7 items-center justify-between gap-2">
      <!-- A fight with a boss has no flasks: nothing to draw (and nothing for a screen reader to read as "0 of 0"). -->
      <FlaskIcons v-if="flasksMax > 0" :flasks="flasks" :max="flasksMax" />
      <span v-else />
      <span v-if="combo >= 3" class="chip chip-accent" data-testid="combo"><NumberText :text="t('gameRun.combo', { count: NUMBER_MARK })" :value="combo" /></span>
      <span class="font-display text-[17px] leading-[22px] font-semibold tabular-nums" :aria-label="`${t('gameRun.score')}: ${score}`" data-testid="score">{{ score }}</span>
    </div>

    <p v-if="caption" class="t-label-s mt-0.5 text-center text-muted short:hidden" data-testid="caption">{{ caption }}</p>
  </header>
</template>
