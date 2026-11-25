<script lang="ts">
export interface SegmentOption {
  value: string | number
  /** Already localized. */
  label: string
  /** `data-testid` of the segment button. */
  testid?: string
  /**
   * Behind Pro (a daily goal above the Free limit): a lock beside the label and `aria-disabled`, but the segment stays pressable - the tap
   * is the parent's to answer (the paywall), so it is still emitted.
   */
  locked?: boolean
}
</script>

<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'

/**
 * Switch between a few values shown side by side (language, daily goal, billing period, import format).
 * Use it for up to four short options; more belong in a `select.field`.
 *
 * - `v-model` (`modelValue`) — the selected `value`; a click emits `update:modelValue` (not for the selected one).
 * - `options` — `{ value, label, testid? }[]`.
 * - `disabled` — the whole control is inactive (e.g. while saving).
 * - `ariaLabel` — what the control sets, for screen readers (required: the options alone do not say it). Written
 *   camelCase in a template (`:ariaLabel`): `vue-tsc` does not match `:aria-label` to this prop and reports it missing.
 *
 * Markup: `role="radiogroup"` with one `role="radio"` button per option (`aria-checked`). A segment is 36 px high, its
 * touch area at least 44 px (`.tap-target`, docs/14 §3): the look stays, the area around it is pressable too.
 * Look (redesign 2026-11): a flat white box with a hairline; the selected segment is the "selected" of the palette — the soft
 * accent fill with a thin primary frame (as a selected answer option or plan card), no shadow.
 *
 * @example
 * <SegmentedControl v-model="period" :options="[{ value: 'month', label: t('billing.period.month') }, …]" :ariaLabel="t('billing.title')" />
 */
const props = defineProps<{
  modelValue: string | number
  options: SegmentOption[]
  disabled?: boolean
  ariaLabel: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>()

function select(value: string | number): void {
  if (!props.disabled && value !== props.modelValue) emit('update:modelValue', value)
}
</script>

<template>
  <div
    role="radiogroup"
    :aria-label="ariaLabel"
    class="flex gap-0.5 rounded-control border border-hairline bg-surface p-0.5"
    :class="{ 'opacity-50': disabled }"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="option.value === modelValue"
      :aria-disabled="option.locked ? 'true' : undefined"
      :disabled="disabled"
      :data-testid="option.testid"
      class="tap-target min-h-9 min-w-0 flex-1 rounded-lg px-3 text-sm leading-5 transition-colors duration-150"
      :class="
        option.value === modelValue
          ? 'bg-accent-soft font-semibold text-body shadow-[inset_0_0_0_1px_var(--app-primary)]'
          : 'text-muted'
      "
      @click="select(option.value)"
    >
      <span class="flex items-center justify-center gap-1 truncate">
        <AppIcon v-if="option.locked" name="lock-closed" :size="14" data-testid="segment-lock" />
        <span class="truncate">{{ option.label }}</span>
      </span>
    </button>
  </div>
</template>
