<script setup lang="ts">
/**
 * Grouped list (kit ListRow / ListGroup): an optional label above, the rows in one flat card (hairline frame, radius lg 20, lines
 * between the rows inset to the text), an optional footnote below. Put `ListRow`s (or any `.list-row` elements) in the default slot.
 *
 * - `header` — the group label (`.section-label`: 13 px, secondary colour, normal case).
 * - `footer` — a hint under the group.
 * - `highlight` — the group with its title inside one plate (`bg-highlight`: the surface with a hairline, radius xl 28; the rows
 *   lie in it without a frame of their own), the title in Title/M of the kit (Unbounded 500, 18 / 24). Off by default: the plain group.
 *
 * @example
 * <ListGroup :header="t('settings.title')" :footer="t('settings.reminder.hint')">
 *   <ListRow :to="{ name: 'settings' }" icon="cog-6-tooth" tile="gray" :title="t('settings.title')" />
 * </ListGroup>
 * <ListGroup highlight :header="t('profile.account')">…</ListGroup>
 */
defineProps<{
  header?: string
  footer?: string
  highlight?: boolean
}>()
</script>

<template>
  <section v-if="highlight" class="overflow-hidden rounded-hero bg-highlight" data-variant="highlight">
    <h2 v-if="header" class="t-title-m px-4 pt-3.5 pb-1">{{ header }}</h2>
    <!-- One frame: the plate's hairline; the rows lie on the same surface without a second card. -->
    <div class="list rounded-none border-0">
      <slot />
    </div>
    <p v-if="footer" class="px-4 py-2.5 text-[13px] leading-[18px] text-muted">{{ footer }}</p>
  </section>
  <section v-else>
    <h2 v-if="header" class="section-label">{{ header }}</h2>
    <div class="list">
      <slot />
    </div>
    <p v-if="footer" class="mt-2 px-[18px] text-[13px] leading-[18px] text-muted">{{ footer }}</p>
  </section>
</template>
