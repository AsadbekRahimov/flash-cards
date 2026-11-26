<script setup lang="ts">
import { computed } from 'vue'
import PlayerName from '@/components/PlayerName.vue'
import { useI18n } from '@/i18n'
import type { CupStandingRow } from '@/api/types'

/**
 * The table of a cup (docs/33 §7.1, docs/04 A.23 `standings`), drawn as the tables of the league (`BoardTable`): the place in a soft
 * badge, the name in the tournament with the emoji of Pro (`PlayerName`, docs/31 §5а), the points; my row tinted with "· you". A shared
 * place (a tie: 1, 1, 3) is the server's `rank` repeated and is said so to a screen reader; "left" under the name of one who left the
 * chat - on its own line, so that a narrow screen (320 px) cuts the name, never the emoji or the points.
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
  <section class="card px-2.5 pt-3.5 pb-2.5" :aria-label="title" data-testid="cup-standings">
    <div class="flex items-baseline justify-between gap-3 px-2.5 pb-1.5">
      <h2 class="min-w-0 text-[19px] leading-6 font-extrabold">{{ title }}</h2>
      <span class="shrink-0 text-[13px] leading-[18px] text-muted tabular-nums" data-testid="participants">{{ tn('league.members', rows.length) }}</span>
    </div>
    <p v-if="rows.length === 0" class="px-2.5 py-2 text-[15px] leading-5 text-muted" data-testid="standings-empty">{{ t('cup.table.empty') }}</p>
    <ol v-else class="flex flex-col">
      <li
        v-for="row in rows"
        :key="row.participant_id"
        class="flex items-center gap-3.5 px-2.5 py-[9px]"
        :class="row.me ? 'my-1 rounded-control bg-nav-active' : 'border-b border-divider'"
        :aria-label="rowLabel(row)"
        :data-shared="shared.has(row.rank) ? 'true' : undefined"
        :data-left="row.left ? 'true' : undefined"
        :data-testid="row.me ? 'row-me' : 'row'"
      >
        <span
          class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg text-[15px] font-bold text-accent tabular-nums"
          :class="row.me ? 'bg-surface' : 'bg-accent-soft'"
          aria-hidden="true"
          data-testid="rank"
        >{{ row.rank }}</span>
        <span class="flex min-w-0 flex-1 flex-col" aria-hidden="true">
          <PlayerName
            :name="rowName(row)"
            :emoji="row.pro_emoji"
            :suffix="row.me ? `· ${t('league.you')}` : undefined"
            class="text-base leading-[22px] font-bold"
            :class="row.left ? 'text-muted' : ''"
          />
          <span v-if="row.left" class="text-xs leading-4 text-muted" data-testid="left">{{ t('cup.table.left') }}</span>
        </span>
        <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" aria-hidden="true" data-testid="points">{{ row.total }}</span>
      </li>
    </ol>
  </section>
</template>
