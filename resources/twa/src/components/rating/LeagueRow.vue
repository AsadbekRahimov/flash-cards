<script setup lang="ts">
import InitialAvatar from '@/components/InitialAvatar.vue'
import PlayerName from '@/components/PlayerName.vue'

/**
 * LeagueRow of the kit Muhrla (COMPONENTS.md "LeagueRow", league-row.png): one row of a rating table — the place (20 px column,
 * tabular), a 32 px avatar with the initial, the first name with the emoji of Pro (`PlayerName`) and the points on the right
 * (tabular). `.league-row` of the design system: at least 52 px, radius sm.
 *
 * - `rank`, `name` (already localized: the first name, "Ученик", or "вы" for my pinned row), `emoji` (`pro_emoji` of the row),
 *   `points`.
 * - `avatarName` — the name the initial is taken from when it is not `name` (my pinned row is named "вы", its avatar is mine).
 * - `me` — my own row: the soft accent (`accent-soft`), the avatar in the muhr colour, the place and the points in the accent of text;
 *   with `sticky` it stays in sight while the table scrolls (pinned under the top of the screen and above the tab bar, inside the
 *   table only).
 * - `suffix` — after the name, never cut ("· вы").
 * - `facts` — a short line under the name (the tie-break facts of a v2 week), cut with an ellipsis.
 * - `zone` — the zone of the place (`data-zone` of the rank): `promotion`, `demotion` or `safe`.
 *
 * Test ids: `rank`, `points`, `row-facts`; the root takes the parent's `data-testid` (`row`, `row-me`, ...).
 */
withDefaults(
  defineProps<{
    rank: number | string
    name: string
    points: number
    emoji?: string | null
    avatarName?: string
    me?: boolean
    sticky?: boolean
    suffix?: string
    facts?: string
    zone?: string
  }>(),
  { emoji: null, avatarName: undefined, me: false, sticky: false, suffix: undefined, facts: '', zone: undefined },
)
</script>

<template>
  <div
    class="league-row"
    :class="{ 'league-row-sticky z-10': me && sticky }"
    :data-me="me ? 'true' : undefined"
  >
    <span
      class="w-5 shrink-0 text-center text-[15px] leading-5 font-semibold tabular-nums"
      :class="me ? 'text-accent' : 'text-muted'"
      :data-zone="zone"
      data-testid="rank"
    >{{ rank }}</span>
    <InitialAvatar :name="avatarName || name" size="sm" :muhr="me" />
    <span class="flex min-w-0 flex-1 flex-col">
      <PlayerName :name="name" :emoji="emoji" :suffix="suffix" class="t-body-m-strong" />
      <span v-if="facts" class="truncate text-xs leading-4 text-muted" data-testid="row-facts">{{ facts }}</span>
    </span>
    <span
      class="shrink-0 text-[15px] leading-5 font-semibold tabular-nums"
      :class="me ? 'text-accent' : 'text-body'"
      data-testid="points"
    >{{ points }}</span>
  </div>
</template>

<style scoped>
/* My row stays in sight while the page scrolls: under the top of the window and above the tab bar (and its home-indicator inset). */
.league-row-sticky {
  position: sticky;
  top: 8px;
  bottom: calc(var(--app-tabbar-h) + env(safe-area-inset-bottom, 0px) + 8px);
}
</style>
