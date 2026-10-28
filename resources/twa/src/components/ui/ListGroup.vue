<script setup lang="ts">
/**
 * Grouped list in the style of Telegram settings: an optional label above, rounded rows inside, an optional
 * footnote below. Put `ListRow`s (or any `.list-row` elements) in the default slot.
 *
 * - `header` — the group label (rendered uppercase by `.section-label`).
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
    <p v-if="footer" class="mt-1.5 px-4 text-[13px] leading-[18px] text-tg-hint">{{ footer }}</p>
  </section>
</template>
