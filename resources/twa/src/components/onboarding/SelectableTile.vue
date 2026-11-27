<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'

/**
 * "Selectable tile" of the kit (COMPONENTS.md, the onboarding): the surface in a 1.5 px hairline; chosen - by `look`:
 * - `frame` (default) - a 2 px frame in `border/strong`; with `multiple`, a 22 px ink check in the top left corner (O4 goals, O5 levels,
 *   O6 goals of the day);
 * - `ink` - the tile itself turns ink (`bg/inverse`) with the text on it (O3 age, O7 time).
 *
 * - `selected` — chosen; `multiple` — one of several that may be chosen (`role="checkbox"`), else one of a group (`role="radio"`).
 * - `locked` — not available (the plan): `aria-disabled`, the tap still comes through (`click`), so the screen can say why.
 * - default slot — the content; the size, the radius and the padding come from the parent's `class`.
 *
 * @example <SelectableTile :selected="age === '18_24'" look="ink" class="h-[60px] rounded-md" @click="pick('18_24')">18–24</SelectableTile>
 */
withDefaults(defineProps<{ selected: boolean; multiple?: boolean; look?: 'frame' | 'ink'; locked?: boolean }>(), {
  multiple: false,
  look: 'frame',
  locked: false,
})

defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    :role="multiple ? 'checkbox' : 'radio'"
    :aria-checked="selected"
    :aria-disabled="locked ? 'true' : undefined"
    class="tile pressable relative"
    :data-look="look"
    :data-selected="selected ? 'true' : undefined"
    :data-locked="locked ? 'true' : undefined"
    @click="$emit('click')"
  >
    <span
      v-if="multiple && selected"
      class="absolute top-2 left-2 inline-flex size-[22px] items-center justify-center rounded-full bg-inverse text-on-inverse"
      aria-hidden="true"
      data-testid="tile-check"
    >
      <AppIcon name="check" :size="14" />
    </span>
    <slot />
  </button>
</template>

<style scoped>
.tile {
  background-color: var(--app-surface);
  box-shadow: inset 0 0 0 1.5px var(--app-hairline);
  color: var(--app-text);
  transition:
    background-color var(--dur-state) var(--ease-out),
    box-shadow var(--dur-state) var(--ease-out),
    color var(--dur-state) var(--ease-out),
    transform var(--dur-press) var(--ease-out);
  touch-action: manipulation;
}
.tile[data-look='frame'][data-selected='true'] {
  box-shadow: inset 0 0 0 2px var(--app-border-strong);
}
.tile[data-look='ink'][data-selected='true'] {
  background-color: var(--app-inverse);
  box-shadow: inset 0 0 0 2px var(--app-inverse);
  color: var(--app-on-inverse);
}
.tile[data-locked='true'] {
  color: var(--app-text-tertiary);
}
.tile:focus-visible {
  outline: 2px solid var(--app-accent);
  outline-offset: 2px;
}
@media (prefers-reduced-motion: reduce) {
  .tile {
    transition-duration: 1ms;
  }
}
</style>
