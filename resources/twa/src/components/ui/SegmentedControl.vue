<script lang="ts">
export interface SegmentOption {
  value: string | number
  /** Already localized. */
  label: string
  /** `data-testid` of the segment button. */
  testid?: string
}
</script>

<script setup lang="ts">
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
    class="flex gap-0.5 rounded-[10px] bg-fill p-0.5 shadow-[inset_0_0_0_1px_var(--app-hairline)]"
    :class="{ 'opacity-50': disabled }"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="option.value === modelValue"
      :disabled="disabled"
      :data-testid="option.testid"
      class="tap-target min-h-9 min-w-0 flex-1 rounded-lg px-3 text-sm leading-5 transition-colors duration-150"
      :class="
        option.value === modelValue
          ? 'bg-surface font-semibold text-tg-text shadow-[0_1px_3px_rgb(0_0_0/0.12),0_0_0_1px_var(--app-hairline)]'
          : 'font-medium text-tg-subtitle'
      "
      @click="select(option.value)"
    >
      <span class="block truncate">{{ option.label }}</span>
    </button>
  </div>
</template>
