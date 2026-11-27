<script setup lang="ts">
import { computed } from 'vue'
import InitialAvatar from '@/components/InitialAvatar.vue'
import PlayerName from '@/components/PlayerName.vue'
import { useI18n } from '@/i18n'
import type { CupStandingRow } from '@/api/types'

/**
 * The table of a cup (docs/33 §7.1, docs/04 A.23 `standings`), drawn as the table of the league of the kit Muhrla (LeagueRow, 08-liga):
 * a row of 52 px - the place, an avatar of 32 px with the first letter (`InitialAvatar`, the one of `LeagueRow`), the name in the
 * tournament with the emoji of Pro (`PlayerName`, docs/31 §5а), the points in tabular figures. My row is the "you" of the kit: the soft accent under it, my avatar in the muhr colour,
 * "· you" after the name and my place and points in the accent of text. A shared place (a tie: 1, 1, 3) is the server's `rank` repeated
 * and is said so to a screen reader; "left" under the name of one who left the chat - on its own line, so that a narrow screen (320 px)
 * cuts the name, never the emoji or the points.
 */
const props = defineProps<{
  title: string
  rows: CupStandingRow[]
}>()

const { t, tn } = useI18n()

/** The ranks more than one row holds. */
const shared = computed((): Set<number> => {
  const seen = new Map<number, number>()
  for (const row of props.rows) seen.set(row.rank, (seen.get(row.rank) ?? 0) + 1)
  return new Set([...seen].filter(([, count]) => count > 1).map(([rank]) => rank))
})

const rowName = (row: CupStandingRow): string => row.display_name || t('league.anonymous')

/** What a screen reader hears for a row: "2nd place (shared), Aziza, 14 points, left". */
function rowLabel(row: CupStandingRow): string {
  const place = shared.value.has(row.rank) ? t('cup.table.place_shared', { rank: row.rank }) : t('cup.table.place', { rank: row.rank })
  const parts = [place, rowName(row), tn('cup.points', row.total)]
  if (row.me) parts.push(t('league.you'))
  if (row.left) parts.push(t('cup.table.left'))
  return parts.join(', ')
}
</script>

<template>
  <section class="card px-2 pt-4 pb-2" :aria-label="title" data-testid="cup-standings">
    <div class="flex items-baseline justify-between gap-3 px-3 pb-2">
      <h2 class="t-title-m min-w-0">{{ title }}</h2>
      <span class="t-label-s shrink-0 text-muted tabular-nums" data-testid="participants">{{ tn('league.members', rows.length) }}</span>
    </div>
    <p v-if="rows.length === 0" class="t-body-m px-3 py-2 text-muted" data-testid="standings-empty">{{ t('cup.table.empty') }}</p>
    <ol v-else class="flex flex-col gap-0.5">
      <li
        v-for="row in rows"
        :key="row.participant_id"
        class="league-row"
        :data-me="row.me ? 'true' : undefined"
        :aria-label="rowLabel(row)"
        :data-shared="shared.has(row.rank) ? 'true' : undefined"
        :data-left="row.left ? 'true' : undefined"
        :data-testid="row.me ? 'row-me' : 'row'"
      >
        <span class="w-6 shrink-0 text-center font-display text-[15px] leading-5 font-semibold" :class="row.me ? 'text-accent' : 'text-muted'" aria-hidden="true" data-testid="rank">{{ row.rank }}</span>
        <InitialAvatar :name="rowName(row)" size="sm" :muhr="row.me" data-testid="avatar" />
        <span class="flex min-w-0 flex-1 flex-col py-1.5" aria-hidden="true">
          <PlayerName
            :name="rowName(row)"
            :emoji="row.pro_emoji"
            :suffix="row.me ? `· ${t('league.you')}` : undefined"
            class="t-body-m-strong"
            :class="row.left ? 'text-muted' : ''"
          />
          <span v-if="row.left" class="t-caption text-muted" data-testid="left">{{ t('cup.table.left') }}</span>
        </span>
        <span class="t-body-m-strong shrink-0 tabular-nums" :class="row.me ? 'text-accent' : ''" aria-hidden="true" data-testid="points">{{ row.total }}</span>
      </li>
    </ol>
  </section>
</template>
