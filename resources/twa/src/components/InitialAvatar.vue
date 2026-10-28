<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'

/**
 * Round avatar with the first letter of a name. Of other people the API sends a first name only (no photo), so this
 * is the face of a league member, a friend or an invited learner (docs/14 §6.7).
 *
 * - `name` — the name written next to the avatar (the caller passes the displayed one, i.e. the "anonymous" label
 *   when the member has no name). The first letter is a whole code point, so an emoji is not cut in half; a blank
 *   name shows a person icon.
 * - `size` — `md` (40 px, default) or `sm` (32 px, for dense rows such as the league table).
 * - `accent` — filled with the accent colour (the learner themself) instead of the neutral track colour.
 *
 * Decorative (`aria-hidden`): the name is in the text beside it.
 *
 * @example <InitialAvatar :name="friend.first_name || t('friends.list.anonymous')" />
 */
const props = withDefaults(
  defineProps<{
    name: string
    size?: 'sm' | 'md'
    accent?: boolean
  }>(),
  { size: 'md', accent: false },
)

const initial = computed(() => (Array.from(props.name.trim())[0] ?? '').toUpperCase())
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center rounded-full leading-none font-semibold select-none"
    :class="[
      size === 'sm' ? 'size-8 text-sm' : 'size-10 text-base',
      accent ? 'bg-accent text-tg-button-text' : 'bg-track text-tg-subtitle',
    ]"
    aria-hidden="true"
  >
    <template v-if="initial">{{ initial }}</template>
    <AppIcon v-else name="user-circle" :size="size === 'sm' ? 18 : 22" />
  </span>
</template>
