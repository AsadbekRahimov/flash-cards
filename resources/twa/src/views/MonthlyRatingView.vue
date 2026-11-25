<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import PlayerName from '@/components/PlayerName.vue'
import RegionPromptCard from '@/components/RegionPromptCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedControl, { type SegmentOption } from '@/components/ui/SegmentedControl.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { formatMonthYear } from '@/composables/format'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { MonthlyWinner, ScoreCategory } from '@/api/types'

/**
 * "Rating" tab, the Month pane (docs/13 §7.6, docs/14 §6.7, docs/04 A.19): the month's prizes, my place, the top (first names and
 * points only), how the points work and last month's winners. The points are the league's. A tab root: no system "Back" button.
 * Drawn in the language of the League screen (docs/design/2026-11-25-redesign League): the brand row, the days left above the title, my
 * place in the big tinted card, the top as rows of the group table — the place in a soft badge, the first name, the points, my row
 * tinted — no medals and no avatars.
 * A month of the word points v2 (`rules_version: 2`, docs/32 §8.3, §12.9) has two tables, Free and Pro: a switch above the prizes (one
 * request of the month, nothing else asked again), the prizes and the participants of the table shown, my place with what it takes to
 * pass the next one (`next_gap`), two neighbours above and below me when the top does not reach me, "Show more" by `next_cursor` (a stale
 * cursor reloads the table from its start) and last month's winners per table. The other table is read only: a line says which table
 * the learner competes in, no neighbours, my own card stays. The emoji of Pro follows the names. A v1 month is drawn as before.
 */
const auth = useAuthStore()
const store = useMonthlyRatingStore()
const { t, tn, locale } = useI18n()

useBackButton(null)

const failed = ref('')

const data = computed(() => store.data)
const message = computed(() => failed.value || store.errorMessage)
const me = computed(() => data.value?.me ?? null)
const ratingTabs = useRatingTabs()

const monthTitle = computed(() => (data.value ? formatMonthYear(data.value.month.starts_on, locale.value) : ''))

/** The title: the month ("November 2026") once it is known and the rating is on; "Monthly rating" otherwise. */
const title = computed(() => (data.value?.enabled && monthTitle.value !== '' ? monthTitle.value : t('month.title')))

/** The month is "open" until the server says it is closing / waiting for the admin's review; no row yet = still open. */
const isOpen = computed(() => (data.value?.month.status ?? 'open') === 'open')

const daysLine = computed(() => {
  const month = data.value?.month
  if (!month) return ''
  if (!isOpen.value) return t('month.closing')
  return month.days_left <= 1 ? t('month.last_day') : t('month.days_left', { count: month.days_left })
})

const hasPrizes = computed(() => (data.value?.prizes.length ?? 0) > 0)
const optedOut = computed(
  () => (!!data.value?.opted_out && (me.value === null || !me.value.participant)) || me.value?.not_counted_reason === 'opted_out',
)

/** A blocked account keeps its points but has no place: a neutral line instead of a bare "no place yet" (docs/34 §3). */
const blocked = computed(() => me.value !== null && !me.value.participant && me.value.not_counted_reason === 'blocked')

const rowName = (name: string | null): string => name || t('league.anonymous')

/** What is still missing for a prize, in words ("eligible" is never shown). */
const prizeLines = computed(() => {
  const mine = me.value
  const rules = data.value?.rules
  if (!mine || !rules || !mine.participant || !hasPrizes.value) return []
  if (mine.eligible) return [t('month.eligible')]
  const lines: string[] = []
  if (mine.missing.active_days > 0) lines.push(t('month.missing_days', { count: mine.missing.active_days }))
  if (mine.missing.account_days > 0) lines.push(t('month.missing_account', { count: rules.min_account_days }))
  return lines
})

const lastMonth = computed(() => data.value?.last_month ?? null)
const lastTitle = computed(() => (lastMonth.value ? formatMonthYear(lastMonth.value.starts_on, locale.value) : ''))

/** The month counts by the word points v2: two tables and the keys of v2. */
const v2 = computed(() => (data.value?.rules_version ?? 0) >= 2)
const readOnly = computed(() => v2.value && data.value?.read_only === true)

const categoryName = (category: ScoreCategory): string => t(category === 'pro' ? 'score.category.pro' : 'score.category.free')

const categoryOptions = computed((): SegmentOption[] => [
  { value: 'free', label: categoryName('free'), testid: 'category-free' },
  { value: 'pro', label: categoryName('pro'), testid: 'category-pro' },
])
/** The table the switch shows: the one asked for (at once, before its answer), else the one the server shows. */
const shownCategory = computed((): ScoreCategory => store.category ?? data.value?.category ?? 'free')

function onCategory(value: string | number): void {
  if (value === 'free' || value === 'pro') void store.selectCategory(value)
}

/** "You compete in the Free table": only over the other table. */
const readOnlyLine = computed(() => {
  const shown = data.value?.category
  const mine = data.value?.my_category
  if (!readOnly.value || shown === undefined || mine === undefined) return ''
  return t('month.read_only', { category: categoryName(shown), mine: categoryName(mine) })
})

const prizesTitle = computed(() =>
  v2.value && data.value?.category !== undefined ? t('month.prizes_category', { category: categoryName(data.value.category) }) : t('month.prizes'),
)

/** A row of the table: the top and the pages after it share these fields (`pro_emoji` absent on an older server). */
interface TableRow {
  rank: number
  name: string | null
  points: number
  is_me: boolean
  pro_emoji?: string | null
  long_words?: number
  active_days?: number
}

/** The top and the pages loaded after it, in the order of the table. */
const shownRows = computed((): TableRow[] => [...(data.value?.top ?? []), ...store.more])

const rowKey = (row: TableRow): string => `${row.rank}|${row.points}|${row.long_words ?? ''}|${row.active_days ?? ''}|${row.name ?? ''}`

/**
 * Two above and two below me in my own table (v2), when the rows shown do not reach my row: drawn under the list after "…", with my row
 * between them. A neighbour already among the rows shown is not repeated.
 */
const neighbours = computed((): { above: TableRow[]; below: TableRow[] } | null => {
  const near = data.value?.neighbours
  const mine = me.value
  if (!v2.value || readOnly.value || !near || mine === null || mine.rank === null || shownRows.value.some((row) => row.is_me)) return null
  const shown = new Set(shownRows.value.map(rowKey))
  return { above: near.above.filter((row) => !shown.has(rowKey(row))), below: near.below }
})

/** The learner has a place, but the rows shown do not reach it (and no neighbours are drawn): the row is pinned under the list. */
const pinnedMe = computed(() => {
  const mine = me.value
  if (readOnly.value || neighbours.value !== null) return null
  return mine !== null && mine.participant && mine.rank !== null && !shownRows.value.some((row) => row.is_me) ? mine : null
})

/** "To 4th place — 8 points" (v2, my own table): the nearest better place among the rows on screen; without one - "to the next place". */
const nextGapLine = computed(() => {
  const mine = me.value
  const gap = mine?.next_gap
  if (!v2.value || readOnly.value || mine === null || mine.rank === null || gap === null || gap === undefined) return ''
  let rank: number | null = null
  for (const row of [...shownRows.value, ...(data.value?.neighbours?.above ?? [])]) {
    if (row.points > mine.points && row.rank < mine.rank && (rank === null || row.rank > rank)) rank = row.rank
  }
  const points = tn('month.points', gap)
  return rank === null ? t('month.next_gap_plain', { points }) : t('league.to_next', { rank, points })
})

/** Last month's winners: per table in a v2 month (`winners_by_category`, a table without winners left out), one list in a v1 month. */
const winnerGroups = computed((): Array<{ key: string; label: string; winners: MonthlyWinner[] }> => {
  const last = lastMonth.value
  if (!last) return []
  const byCategory = last.winners_by_category
  if (!byCategory) return [{ key: 'all', label: '', winners: last.winners }]
  return (['free', 'pro'] as const)
    .filter((category) => byCategory[category].length > 0)
    .map((category) => ({ key: category, label: categoryName(category), winners: byCategory[category] }))
})

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await store.load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="month-view">
    <PageHeader brand :eyebrow="data?.enabled ? daysLine || undefined : undefined" :title="title" large data-testid="month" />
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" class="mb-3.5" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <InlineAlert v-if="message" tone="danger" :message="message" data-testid="reload-error" />

      <section v-if="!data.enabled" class="card text-[15px] leading-[22px] text-muted" data-testid="disabled">
        {{ t('month.disabled') }}
      </section>

      <template v-else>
        <!-- "Choose your region" (docs/35 §2.3): the rating of the region will be a part of this pane. -->
        <RegionPromptCard />

        <!-- The two tables of a v2 month (docs/32 §8.3): Free and Pro; the other one is read only. -->
        <SegmentedControl
          v-if="v2"
          :modelValue="shownCategory"
          :options="categoryOptions"
          :ariaLabel="t('month.category_switch')"
          data-testid="category-switch"
          @update:modelValue="onCategory"
        />
        <p v-if="readOnlyLine" class="card text-[15px] leading-[22px] text-muted" data-testid="read-only">{{ readOnlyLine }}</p>

        <section v-if="hasPrizes" class="list" data-testid="prizes">
          <h2 class="px-3.5 pt-3.5 pb-1 text-[19px] leading-6 font-extrabold">{{ prizesTitle }}</h2>
          <ul>
            <li
              v-for="prize in data.prizes"
              :key="prize.place"
              class="border-b border-divider px-3.5 py-2.5 text-[15px] leading-5"
              data-testid="prize"
            >
              {{ t('month.prize_place', { place: prize.place, title: prize.title }) }}
            </li>
          </ul>
          <p class="px-3.5 py-3 text-[13px] leading-[18px] text-muted" data-testid="prizes-note">{{ t('month.prizes_note') }}</p>
        </section>

        <section v-if="optedOut" class="card" data-testid="opted-out">
          <p class="text-[15px] leading-[22px] text-muted">{{ t('month.opted_out') }}</p>
          <RouterLink :to="{ name: 'settings' }" class="btn btn-tinted btn-block mt-3" data-testid="settings-link">
            {{ t('league.settings.open') }}
          </RouterLink>
        </section>

        <section v-else-if="me === null" class="rounded-hero bg-highlight p-4" data-testid="not-started">
          <p class="text-base leading-[22px] font-bold">{{ t('month.start_prompt') }}</p>
          <RouterLink :to="{ name: 'today' }" class="btn btn-primary btn-block mt-3" data-testid="start-btn">
            {{ t('league.daily_plan') }}
          </RouterLink>
        </section>

        <section v-else class="flex flex-col gap-1.5 rounded-hero bg-highlight p-4" data-testid="my-card">
          <p class="text-lg leading-6 font-extrabold tracking-[-0.2px]" data-testid="my-place">
            {{
              me.rank !== null
                ? t('month.my_place', { rank: me.rank, points: tn('month.points', me.points) })
                : t('month.my_noplace', { points: tn('month.points', me.points) })
            }}
          </p>
          <p v-if="nextGapLine" class="text-[15px] leading-5" data-testid="next-gap">{{ nextGapLine }}</p>
          <p v-if="v2 && (me.long_words ?? 0) > 0" class="text-[13px] leading-[18px] text-muted" data-testid="long-words">
            {{ t('month.long_words', { count: me.long_words ?? 0 }) }}
          </p>
          <p v-if="blocked" class="text-[13px] leading-[18px] text-muted" data-testid="not-counted">
            {{ t('month.not_counted_blocked') }}
          </p>
          <p v-for="line in prizeLines" :key="line" class="text-[13px] leading-[18px] text-muted" data-testid="prize-line" :data-eligible="me.eligible">
            {{ line }}
          </p>
        </section>

        <!-- The top as the group table of the League mock-up: rows with a soft badge of the place, my row tinted. -->
        <section v-if="data.top.length > 0" class="card px-2.5 pt-3.5 pb-2.5" :aria-label="t('month.top')" data-testid="top">
          <div class="flex items-baseline justify-between gap-3 px-2.5 pb-1.5">
            <h2 class="min-w-0 text-[19px] leading-6 font-extrabold">{{ t('month.top') }}</h2>
            <span v-if="v2" class="shrink-0 text-[13px] leading-[18px] text-muted tabular-nums" data-testid="participants">
              {{ tn('league.members', data.month.participants) }}
            </span>
          </div>
          <div
            v-for="(row, index) in shownRows"
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

          <!-- "Show more" (v2): the next page by `next_cursor`; a tap while one is on its way does nothing. -->
          <button
            v-if="store.cursor !== null"
            type="button"
            class="flex min-h-11 w-full items-center px-2.5 py-2 text-left text-sm font-semibold text-accent disabled:opacity-50"
            :disabled="store.loadingMore"
            :aria-busy="store.loadingMore"
            data-testid="more"
            @click="store.loadMore()"
          >
            {{ t('month.more') }}
          </button>
          <InlineAlert v-if="store.moreError" tone="danger" :message="store.moreError" class="mx-2.5 mb-1" data-testid="more-error" />

          <!-- My neighbours (v2, my own table): two above, my row, two below, after "…". -->
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
        <section v-else class="card text-[15px] leading-[22px] text-muted" data-testid="empty">
          {{ t('month.empty') }}
        </section>

        <ListGroup v-if="lastMonth && lastMonth.status === 'confirmed'" :header="t('month.last_winners', { month: lastTitle })" data-testid="last-month">
          <template v-for="group in winnerGroups" :key="group.key">
            <p v-if="group.label" class="px-3.5 pt-3 pb-1 text-[13px] leading-[18px] font-bold text-muted" data-testid="winners-category">{{ group.label }}</p>
            <div v-for="(winner, index) in group.winners" :key="`${group.key}-${index}`" class="list-row" data-testid="winner">
              <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-accent-soft text-[15px] font-bold text-accent tabular-nums">{{ winner.place }}</span>
              <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">{{ rowName(winner.name) }}</span>
              <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums">{{ winner.points }}</span>
            </div>
          </template>
          <ListRow v-if="lastMonth.me" :title="t('month.last_me', { rank: lastMonth.me.rank })" data-testid="last-me" />
        </ListGroup>
        <section v-else-if="lastMonth" class="card text-[15px] leading-[22px] text-muted" data-testid="last-month-pending">
          {{ t('month.last_pending', { month: lastTitle }) }}
        </section>

        <details class="list group" data-testid="rules">
          <summary class="list-row cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
            <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('month.rules_title') }}</span>
            <AppIcon name="chevron-down" :size="16" class="text-muted transition-transform duration-150 group-open:rotate-180" />
          </summary>
          <div class="space-y-2 px-3.5 pt-1 pb-4 text-[15px] leading-[22px] text-muted">
            <p data-testid="rules-points">{{ v2 ? t('month.rules_points_v2') : t('month.rules_points', { cap: data.rules.daily_cap }) }}</p>
            <p v-if="hasPrizes" data-testid="rules-prize">
              {{ t('month.rules_prize', { places: data.rules.places, days: data.rules.min_active_days, account: data.rules.min_account_days }) }}
            </p>
          </div>
        </details>
      </template>
    </div>
  </div>
</template>
