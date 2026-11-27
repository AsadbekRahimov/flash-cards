<script lang="ts">
/** Colour of the icon tile of a `ListRow` (`.tile-*` in `style.css`). */
export type TileColor = 'blue' | 'orange' | 'green' | 'purple' | 'red' | 'teal' | 'yellow' | 'pink' | 'gray'
</script>

<script setup lang="ts">
import { computed, getCurrentInstance } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import AppIcon from './AppIcon.vue'
import type { IconName } from './icons'

/**
 * One row of a `ListGroup` (kit Muhrla, COMPONENTS.md "ListRow"): at least 60 px, padding 10 / 12; an optional 40 x 40 plate
 * (radius sm, the sunken paper) with a 20 px icon or a custom leading element, a title (Body/M Strong, 15 / 22, 600) with an optional
 * subtitle (13 px, secondary colour), a value (15 px, secondary colour) and a chevron on the right.
 *
 * Root element (decided by the props and the parent's listeners):
 * - `to` — a `RouterLink`, with the chevron unless `chevron` is `false`;
 * - otherwise, when the parent listens to `@click` — a `<button type="button">`;
 * - otherwise, or while `disabled` — a plain `<div>` (`aria-disabled` while disabled).
 *
 * Props: `to`; `icon` (Heroicons name) with `tile` (colour of the tile, default gray); `title`; `subtitle`
 * (hint under the title); `value` (text on the right); `chevron` (force the `›` on or off); `danger` (red title,
 * for destructive rows); `disabled`. The plate is the same for all rows: `tile` only picks the colour of the icon.
 * Event: `click` (not emitted while disabled).
 * Slots: `leading` (replaces the icon tile), `trailing` (replaces `value`; e.g. a `ToggleSwitch` or a `select.field-inline`;
 * keep a row with an interactive trailing control free of `@click`).
 * `data-testid` and other attributes go to the root element.
 *
 * @example
 * <ListRow :to="{ name: 'billing' }" icon="credit-card" tile="purple" :title="t('billing.title')" value="Pro" data-testid="row-billing" />
 * <ListRow :title="t('friends.list.remove', { name })" danger @click="askConfirm" />
 */
const props = withDefaults(
  defineProps<{
    to?: RouteLocationRaw
    icon?: IconName
    tile?: TileColor
    title: string
    subtitle?: string
    value?: string
    chevron?: boolean
    danger?: boolean
    disabled?: boolean
  }>(),
  // Vue turns an absent Boolean prop into `false`; `undefined` keeps "not set" apart from "off" (a link shows `›` by default).
  { chevron: undefined },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const instance = getCurrentInstance()

/** A declared `click` emit is not part of `$attrs`, so the parent's listener is looked up on the vnode (per render). */
function hasClickListener(): boolean {
  return Boolean(instance?.vnode.props?.onClick)
}

const asLink = computed(() => props.to !== undefined && !props.disabled)

/** The root: RouterLink for `to`, `button` for a clickable row, `div` otherwise. */
function rootTag() {
  if (asLink.value) return RouterLink
  return !props.disabled && hasClickListener() ? 'button' : 'div'
}

const showChevron = computed(() => props.chevron ?? asLink.value)

function onClick(event: MouseEvent): void {
  if (!props.disabled) emit('click', event)
}
</script>

<template>
  <component
    :is="rootTag()"
    :to="asLink ? to : undefined"
    :type="!asLink && !disabled && hasClickListener() ? 'button' : undefined"
    :aria-disabled="disabled ? 'true' : undefined"
    class="list-row"
    :class="{ 'list-row-icon': icon || $slots.leading, 'opacity-50': disabled }"
    @click="onClick"
  >
    <slot name="leading">
      <span v-if="icon" class="icon-tile" :class="`tile-${tile ?? 'gray'}`"><AppIcon :name="icon" :size="20" /></span>
    </slot>
    <span class="min-w-0 flex-1">
      <span class="block text-[15px] leading-[22px] font-semibold" :class="{ 'text-danger': danger }">{{ title }}</span>
      <span v-if="subtitle" class="mt-0.5 block text-[13px] leading-[18px] text-muted">{{ subtitle }}</span>
    </span>
    <slot name="trailing">
      <span v-if="value" class="shrink-0 text-[15px] leading-5 text-muted">{{ value }}</span>
    </slot>
    <AppIcon v-if="showChevron" name="chevron-right" :size="20" class="shrink-0 text-muted" />
  </component>
</template>
