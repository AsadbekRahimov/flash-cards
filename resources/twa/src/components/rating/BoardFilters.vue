<script setup lang="ts">
import { computed, ref } from 'vue'
import RegionSheet from '@/components/RegionSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import { useI18n } from '@/i18n'
import type { BoardArea } from '@/stores/ratingBoard'
import type { ScoreCategory } from '@/api/types'

/**
 * The switches of a rating table (docs/32 §12.11, docs/35 §2.4): Free / Pro and "All / My region / Another region…".
 *
 * - `category` — the table chosen; a tap on the other emits `category`.
 * - `area` — the part chosen; `showArea: false` hides its switch (a v1 month: `region_scope_unavailable`). "All" and "My region" emit
 *   `area` at once; "Another region…" opens the region sheet in its `browse` mode (nothing is saved, the learner's region stays) and
 *   emits the region picked. The chip of another region shows its name once chosen.
 *
 * The chips keep their whole labels and scroll sideways when they do not fit (320 px).
 */
const props = defineProps<{
  category: ScoreCategory
  categoryLabel: string
  area: BoardArea
  showArea: boolean
}>()

const emit = defineEmits<{ category: [value: ScoreCategory]; area: [value: BoardArea] }>()

const { t } = useI18n()

const sheetOpen = ref(false)

const categoryName = (category: ScoreCategory): string => t(category === 'pro' ? 'score.category.pro' : 'score.category.free')

const categoryOptions = computed((): SegmentOption[] => [
  { value: 'free', label: categoryName('free'), testid: 'category-free' },
  { value: 'pro', label: categoryName('pro'), testid: 'category-pro' },
])

function onCategory(value: string | number): void {
  if (value === 'free' || value === 'pro') emit('category', value)
}

function onArea(kind: 'all' | 'mine'): void {
  if (props.area.kind !== kind) emit('area', { kind })
}

function onPick(code: string, name: string): void {
  sheetOpen.value = false
  if (props.area.kind !== 'other' || props.area.code !== code) emit('area', { kind: 'other', code, name })
}

const otherLabel = computed(() => (props.area.kind === 'other' ? props.area.name : t('board.area.other')))
const chip = (active: boolean): string =>
  active ? 'bg-accent-soft font-semibold text-body shadow-[inset_0_0_0_1px_var(--app-primary)]' : 'border border-hairline bg-surface text-muted'
</script>

<template>
  <div class="space-y-2">
    <SegmentedControl
      :modelValue="category"
      :options="categoryOptions"
      :ariaLabel="categoryLabel"
      data-testid="category-switch"
      @update:modelValue="onCategory"
    />
    <div v-if="showArea" role="radiogroup" :aria-label="t('board.area_switch')" class="-mx-5 flex gap-2 overflow-x-auto px-5 py-0.5" data-testid="area-switch">
      <button
        type="button"
        role="radio"
        :aria-checked="area.kind === 'all'"
        class="tap-target min-h-9 shrink-0 rounded-full px-3.5 text-sm leading-5 whitespace-nowrap"
        :class="chip(area.kind === 'all')"
        data-testid="area-all"
        @click="onArea('all')"
      >
        {{ t('board.area.all') }}
      </button>
      <button
        type="button"
        role="radio"
        :aria-checked="area.kind === 'mine'"
        class="tap-target min-h-9 shrink-0 rounded-full px-3.5 text-sm leading-5 whitespace-nowrap"
        :class="chip(area.kind === 'mine')"
        data-testid="area-mine"
        @click="onArea('mine')"
      >
        {{ t('board.area.mine') }}
      </button>
      <button
        type="button"
        role="radio"
        :aria-checked="area.kind === 'other'"
        aria-haspopup="dialog"
        class="tap-target flex min-h-9 shrink-0 items-center gap-1 rounded-full px-3.5 text-sm leading-5 whitespace-nowrap"
        :class="chip(area.kind === 'other')"
        data-testid="area-other"
        @click="sheetOpen = true"
      >
        {{ otherLabel }}
        <AppIcon name="chevron-down" :size="14" />
      </button>
    </div>

    <RegionSheet
      v-if="sheetOpen"
      browse
      :selected="area.kind === 'other' ? area.code : null"
      @pick="onPick"
      @close="sheetOpen = false"
    />
  </div>
</template>
