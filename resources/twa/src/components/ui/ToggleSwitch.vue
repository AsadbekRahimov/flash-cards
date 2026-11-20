<script setup lang="ts">
/**
 * On/off switch (`role="switch"`): the exercise types, leagues.
 *
 * - `v-model` (`modelValue`) — the state; a click emits `update:modelValue` with the opposite value.
 * - `label` — what the switch controls, for screen readers (the visible label is usually the `ListRow` title).
 * - `disabled` — inactive (e.g. while saving).
 *
 * Flat (no shadow): on — the primary colour with the knob in `on-primary`; off — the track with the knob in the secondary text
 * colour, which stands out from the track in both palettes. `data-testid` and other attributes go to the button.
 * The switch is 31 px high, its touch area at least 44 px (`.tap-target`, docs/14 §3).
 *
 * @example <ToggleSwitch :model-value="data.exercises.reverse" :label="t('settings.exercises.reverse')" @update:model-value="onExercise('reverse', $event)" />
 */
const props = defineProps<{
  modelValue: boolean
  disabled?: boolean
  label: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

function toggle(): void {
  if (!props.disabled) emit('update:modelValue', !props.modelValue)
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :aria-label="label"
    :disabled="disabled"
    class="tap-target relative inline-flex h-[31px] w-[51px] shrink-0 items-center rounded-full transition-colors duration-200 disabled:opacity-50"
    :class="modelValue ? 'bg-primary' : 'bg-track'"
    @click="toggle"
  >
    <span
      class="pointer-events-none absolute left-0.5 size-[27px] rounded-full transition-[transform,background-color] duration-200"
      :class="modelValue ? 'translate-x-5 bg-on-primary' : 'translate-x-0 bg-muted'"
    />
  </button>
</template>
