<script setup lang="ts">
import { computed } from 'vue'
import PlayerName from '@/components/PlayerName.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { boardRowKey, type BoardTableMe, type BoardTableRow } from '@/composables/boardRows'
import { useI18n } from '@/i18n'
import type { BoardRow } from '@/api/types'

/**
 * The table of the month and of "All time" (docs/04 A.19, A.19a; the League mock-up of docs/design/2026-11-25-redesign): rows with a
 * soft badge of the place, the first name with the emoji of Pro (`PlayerName`), the points, my row tinted.
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
  <section class="card px-2.5 pt-3.5 pb-2.5" :aria-label="title" data-testid="top">
    <div class="flex items-baseline justify-between gap-3 px-2.5 pb-1.5">
      <h2 class="min-w-0 text-[19px] leading-6 font-extrabold">{{ title }}</h2>
      <span v-if="participants !== null" class="shrink-0 text-[13px] leading-[18px] text-muted tabular-nums" data-testid="participants">
        {{ tn('league.members', participants) }}
      </span>
    </div>
    <div
      v-for="(row, index) in rows"
      :key="`row-${index}`"
      class="flex items-center gap-3.5 px-2.5 py-[9px]"
      :class="row.is_me ? 'my-1 rounded-control bg-nav-active' : 'border-b border-divider'"
      :data-testid="row.is_me ? 'row-me' : 'row'"
    >
      <span
        class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg text-[15px] font-bold text-accent tabular-nums"
        :class="{ 'bg-surface': row.is_me, 'bg-accent-soft': !row.is_me }"
        data-testid="rank"
      >{{ row.rank }}</span>
      <PlayerName
        :name="rowName(row.name)"
        :emoji="row.pro_emoji"
        :suffix="row.is_me ? `· ${t('league.you')}` : undefined"
        class="flex-1 text-base leading-[22px] font-bold"
      />
      <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ row.points }}</span>
    </div>

    <!-- "Show more": the next page by `next_cursor`; a tap while a page or another table is on its way does nothing. -->
    <button
      v-if="cursor !== null"
      type="button"
      class="flex min-h-11 w-full items-center px-2.5 py-2 text-left text-sm font-semibold text-accent disabled:opacity-50"
      :disabled="loadingMore || busy"
      :aria-busy="loadingMore"
      data-testid="more"
      @click="emit('more')"
    >
      {{ t('month.more') }}
    </button>
    <InlineAlert v-if="moreError" tone="danger" :message="moreError" class="mx-2.5 mb-1" data-testid="more-error" />

    <!-- My neighbours (my own table): two above, my row, two below, after "…". -->
    <div v-if="neighbours && me" :aria-label="t('month.neighbours')" role="group" data-testid="neighbours">
      <div class="px-2.5 py-1 text-[13px] leading-4 text-muted" aria-hidden="true" data-testid="gap">…</div>
      <div v-for="(row, index) in neighbours.above" :key="`above-${index}`" class="flex items-center gap-3.5 border-b border-divider px-2.5 py-[9px]" data-testid="row-near">
        <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-accent-soft text-[15px] font-bold text-accent tabular-nums" data-testid="rank">{{ row.rank }}</span>
        <PlayerName :name="rowName(row.name)" :emoji="row.pro_emoji" class="flex-1 text-base leading-[22px] font-bold" />
        <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ row.points }}</span>
      </div>
      <div class="my-1 flex items-center gap-3.5 rounded-control bg-nav-active px-2.5 py-[9px]" data-testid="row-near-me">
        <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-surface text-[15px] font-bold text-accent tabular-nums" data-testid="rank">{{ me.rank }}</span>
        <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">{{ t('league.you') }}</span>
        <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ me.points }}</span>
      </div>
      <div v-for="(row, index) in neighbours.below" :key="`below-${index}`" class="flex items-center gap-3.5 border-b border-divider px-2.5 py-[9px]" data-testid="row-near">
        <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-accent-soft text-[15px] font-bold text-accent tabular-nums" data-testid="rank">{{ row.rank }}</span>
        <PlayerName :name="rowName(row.name)" :emoji="row.pro_emoji" class="flex-1 text-base leading-[22px] font-bold" />
        <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ row.points }}</span>
      </div>
    </div>
    <div v-if="pinnedMe" class="mt-1 flex items-center gap-3.5 rounded-control bg-nav-active px-2.5 py-[9px]" data-testid="row-pinned">
      <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-surface text-[15px] font-bold text-accent tabular-nums" data-testid="rank">{{ pinnedMe.rank }}</span>
      <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">{{ t('league.you') }}</span>
      <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ pinnedMe.points }}</span>
    </div>
  </section>
</template>
