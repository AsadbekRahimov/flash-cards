<script setup lang="ts">
/**
 * Grouped list: an optional label above, the rows in one flat card (hairline frame, radius 16, full-width lines between the
 * rows), an optional footnote below. Put `ListRow`s (or any `.list-row` elements) in the default slot.
 *
 * - `header` — the group label (`.section-label`: 13 px, secondary colour, normal case).
 * - `footer` — a hint under the group.
 * - `highlight` — the group of the Profile mock-up (docs/design/2026-11-25-redesign Profile): the white list lies on a tinted plate
 *   (`bg-highlight`, radius 20) with the label inside it, 18 px extra bold. Off by default: the plain group above.
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
  <section v-if="highlight" class="rounded-hero bg-highlight" data-variant="highlight">
    <h2 v-if="header" class="px-4 pt-3 pb-2.5 text-lg leading-6 font-extrabold">{{ header }}</h2>
    <div class="list">
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
