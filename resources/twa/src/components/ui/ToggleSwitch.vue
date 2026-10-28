<script setup lang="ts">
/**
 * On/off switch (`role="switch"`): the exercise types, leagues.
 *
 * - `v-model` (`modelValue`) — the state; a click emits `update:modelValue` with the opposite value.
 * - `label` — what the switch controls, for screen readers (the visible label is usually the `ListRow` title).
 * - `disabled` — inactive (e.g. while saving).
 *
 * The "on" colour is the accent; the knob is the text colour of a button (`--tg-button-text`: white in every Telegram
 * theme), the one token that is always readable on the accent. `data-testid` and other attributes go to the button.
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
    :class="modelValue ? 'bg-accent' : 'bg-track'"
    @click="toggle"
  >
    <span
      class="pointer-events-none absolute left-0.5 size-[27px] rounded-full bg-tg-button-text shadow-[0_2px_4px_rgb(0_0_0/0.25)] transition-transform duration-200"
      :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
    />
  </button>
</template>
