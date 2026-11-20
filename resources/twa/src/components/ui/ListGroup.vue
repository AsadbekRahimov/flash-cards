<script setup lang="ts">
/**
 * Grouped list: an optional label above, the rows in one flat card (hairline frame, radius 12, full-width lines between the
 * rows), an optional footnote below. Put `ListRow`s (or any `.list-row` elements) in the default slot.
 *
 * - `header` — the group label (`.section-label`: 13 px, secondary colour, normal case).
 * - `footer` — a hint under the group.
 *
 * @example
 * <ListGroup :header="t('settings.title')" :footer="t('settings.reminder.hint')">
 *   <ListRow :to="{ name: 'settings' }" icon="cog-6-tooth" tile="gray" :title="t('settings.title')" />
 * </ListGroup>
 */
defineProps<{
  header?: string
  footer?: string
}>()
</script>

<template>
  <section>
    <h2 v-if="header" class="section-label">{{ header }}</h2>
    <div class="list">
      <slot />
    </div>
    <p v-if="footer" class="mt-2 px-[18px] text-[13px] leading-[18px] text-muted">{{ footer }}</p>
  </section>
</template>
