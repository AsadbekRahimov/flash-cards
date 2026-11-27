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
 * - `size` — `md` (40 px, default), `sm` (32 px: the LeagueRow of the kit, dense rows) or `lg` (72 px: the centred avatar of the
 *   Profile, the letter in Unbounded).
 * - `accent` — filled with the primary colour (the ink; the learner themself in a battle) instead of the quiet fill colour.
 * - `muhr` — the learner themself in a table: the muhr colour of the kit's "you" row (league-row.png) with its label (`on-muhr`).
 *
 * Decorative (`aria-hidden`): the name is in the text beside it.
 *
 * @example <InitialAvatar :name="friend.first_name || t('friends.list.anonymous')" />
 */
const props = withDefaults(
  defineProps<{
    name: string
    size?: 'sm' | 'md' | 'lg'
    accent?: boolean
    muhr?: boolean
  }>(),
  { size: 'md', accent: false, muhr: false },
)

const initial = computed(() => (Array.from(props.name.trim())[0] ?? '').toUpperCase())

const SIZES = {
  sm: { box: 'size-8 text-sm', icon: 18 },
  md: { box: 'size-10 text-base', icon: 22 },
  lg: { box: 'size-[72px] font-display text-[30px]', icon: 36 },
} as const
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center rounded-full leading-none font-semibold select-none"
    :class="[SIZES[size].box, muhr ? 'bg-muhr text-on-muhr' : accent ? 'bg-primary text-on-primary' : 'bg-fill text-muted']"
    :data-size="size"
    aria-hidden="true"
  >
    <template v-if="initial">{{ initial }}</template>
    <AppIcon v-else name="user-circle" :size="SIZES[size].icon" />
  </span>
</template>
