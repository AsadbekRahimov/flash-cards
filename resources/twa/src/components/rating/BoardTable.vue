<script setup lang="ts">
import { computed } from 'vue'
import LeagueRow from '@/components/rating/LeagueRow.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { boardRowKey, type BoardTableMe, type BoardTableRow } from '@/composables/boardRows'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from '@/i18n'
import type { BoardRow } from '@/api/types'

/**
 * The table of the month and of "All time" (docs/04 A.19, A.19a), drawn as the table of the kit Muhrla (08-liga): LeagueRows on the page —
 * the place, a 32 px avatar with the initial, the first name with the emoji of Pro (`PlayerName`), the points; my row in the soft accent
 * with the avatar in the muhr colour, sticky while the page scrolls.
 *
 * - `rows` — the top and the pages loaded after it, in the order of the table.
 * - `participants` — the size of the table shown; null - not said (a v1 month).
 * - `cursor` / `loadingMore` / `busy` / `moreError` — "Show more" by `next_cursor`: shown while there is a cursor, inactive while a
 *   page (`loadingMore`) or another table (`busy`) is on its way; emits `more`.
 * - `near` — two rows above and below me in my own table (`neighbours`); drawn after "…" with my row between them when the rows shown
 *   do not reach me (`withNeighbours`: a v2 table that is not read only); a neighbour already shown is not repeated.
 * - `me` — my row: pinned under the list when the rows shown do not reach it and no neighbours are drawn (never over a read-only
 *   table).
 */
const props = defineProps<{
  title: string
  participants: number | null
  rows: BoardTableRow[]
  cursor: string | null
  loadingMore: boolean
  busy?: boolean
  moreError: string
  near: { above: BoardRow[]; below: BoardRow[] } | null | undefined
  withNeighbours: boolean
  readOnly: boolean
  me: BoardTableMe | null
}>()

const emit = defineEmits<{ more: [] }>()

const { t, tn } = useI18n()

const rowName = (name: string | null): string => name || t('league.anonymous')

/** My pinned row is named "вы"; its avatar takes the initial of my own first name (the auth store), the "вы" one without it. */
const auth = useAuthStore()
const myName = computed(() => (auth.learner?.first_name ?? '').trim() || t('league.you'))

const meShown = computed(() => props.rows.some((row) => row.is_me))

const neighbours = computed((): { above: BoardTableRow[]; below: BoardTableRow[] } | null => {
  const near = props.near
  const mine = props.me
  if (!props.withNeighbours || props.readOnly || !near || mine === null || mine.rank === null || meShown.value) return null
  const shown = new Set(props.rows.map(boardRowKey))
  return { above: near.above.filter((row) => !shown.has(boardRowKey(row))), below: near.below }
})

const pinnedMe = computed(() => {
  const mine = props.me
  if (props.readOnly || neighbours.value !== null) return null
  return mine !== null && mine.participant && mine.rank !== null && !meShown.value ? mine : null
})
</script>

<template>
  <section class="flex flex-col gap-1" :aria-label="title" data-testid="top">
    <div class="flex items-baseline justify-between gap-3 px-1 pb-1">
      <h2 class="t-title-m min-w-0">{{ title }}</h2>
      <span v-if="participants !== null" class="t-label-s shrink-0 text-muted tabular-nums" data-testid="participants">
        {{ tn('league.members', participants) }}
      </span>
    </div>
    <LeagueRow
      v-for="(row, index) in rows"
      :key="`row-${index}`"
      :rank="row.rank"
      :name="rowName(row.name)"
      :emoji="row.pro_emoji"
      :points="row.points"
      :me="row.is_me"
      sticky
      :suffix="row.is_me ? `· ${t('league.you')}` : undefined"
      :data-testid="row.is_me ? 'row-me' : 'row'"
    />

    <!-- "Show more": the next page by `next_cursor`; a tap while a page or another table is on its way does nothing. -->
    <button
      v-if="cursor !== null"
      type="button"
      class="flex min-h-11 w-full items-center justify-center px-3 py-2 text-sm font-semibold text-accent disabled:opacity-50"
      :disabled="loadingMore || busy"
      :aria-busy="loadingMore"
      data-testid="more"
      @click="emit('more')"
    >
      {{ t('month.more') }}
    </button>
    <InlineAlert v-if="moreError" tone="danger" :message="moreError" class="mb-1" data-testid="more-error" />

    <!-- My neighbours (my own table): two above, my row, two below, after "…". -->
    <div v-if="neighbours && me" class="flex flex-col gap-1" :aria-label="t('month.neighbours')" role="group" data-testid="neighbours">
      <div class="px-3 text-[13px] leading-4 text-muted" aria-hidden="true" data-testid="gap">…</div>
      <LeagueRow
        v-for="(row, index) in neighbours.above"
        :key="`above-${index}`"
        :rank="row.rank ?? ''"
        :name="rowName(row.name)"
        :emoji="row.pro_emoji"
        :points="row.points"
        data-testid="row-near"
      />
      <LeagueRow :rank="me.rank ?? ''" :name="t('league.you')" :avatarName="myName" :points="me.points" me sticky data-testid="row-near-me" />
      <LeagueRow
        v-for="(row, index) in neighbours.below"
        :key="`below-${index}`"
        :rank="row.rank ?? ''"
        :name="rowName(row.name)"
        :emoji="row.pro_emoji"
        :points="row.points"
        data-testid="row-near"
      />
    </div>
    <LeagueRow
      v-if="pinnedMe"
      :rank="pinnedMe.rank ?? ''"
      :name="t('league.you')"
      :avatarName="myName"
      :points="pinnedMe.points"
      me
      sticky
      class="mt-1"
      data-testid="row-pinned"
    />
  </section>
</template>
