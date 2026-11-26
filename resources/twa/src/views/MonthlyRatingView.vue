<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import RegionPromptCard from '@/components/RegionPromptCard.vue'
import BoardFilters from '@/components/rating/BoardFilters.vue'
import BoardRegionState from '@/components/rating/BoardRegionState.vue'
import BoardTable from '@/components/rating/BoardTable.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { nearestBetterRank, type BoardTableRow } from '@/composables/boardRows'
import { formatMonthYear } from '@/composables/format'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { useSettingsStore } from '@/stores/settings'
import type { BoardArea } from '@/stores/ratingBoard'
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
 * The tables of a region (docs/35 §2.4, docs/31 §5г; v2 only): "All / My region / Another region…" - my own region with my place in
 * it, any other one read only (picked in the region sheet, the learner's region is not changed); no prizes there; no region - the card
 * "Choose your region"; fewer participants than the threshold - no rows, the way to the invitations; a v1 month answering
 * `region_scope_unavailable` - no switch of the region. The table, its pages and the races between them are `BoardTable` and
 * `useBoardTable` (shared with "All time").
 */
const auth = useAuthStore()
const store = useMonthlyRatingStore()
const settings = useSettingsStore()
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

/** The table the switch shows: the one asked for (at once, before its answer), else the one the server shows. */
const shownCategory = computed((): ScoreCategory => store.category ?? data.value?.category ?? 'free')

/** "You compete in the Free table": only over the table of the other category. */
const readOnlyLine = computed(() => {
  const shown = data.value?.category
  const mine = data.value?.my_category
  if (!readOnly.value || shown === undefined || mine === undefined || shown === mine) return ''
  return t('month.read_only', { category: categoryName(shown), mine: categoryName(mine) })
})

const prizesTitle = computed(() =>
  v2.value && data.value?.category !== undefined ? t('month.prizes_category', { category: categoryName(data.value.category) }) : t('month.prizes'),
)

/** The region switch: a v2 month, unless the server said this month has no tables of a region. */
const showArea = computed(() => v2.value && !store.regionUnavailable)
/** The table of a region shown (null: the whole category). */
const shownRegion = computed(() => (data.value?.scope === 'region' ? (data.value.region ?? null) : null))
/** No region of the learner: "my region" answered 422 `region_not_set`. */
const regionNotSet = computed(() => store.regionNotSet)
/** The table is hidden: no region to show, or too few participants in it. */
const tableHidden = computed(() => regionNotSet.value || (shownRegion.value !== null && !shownRegion.value.shown))
/** The learner's region (of the month, else of the settings): "You compete in Y". */
const myRegionName = computed(() => {
  const region = me.value?.region
  if (region) return region.name
  const code = settings.data?.region_code
  return code ? (settings.data?.options.regions?.find((item) => item.code === code)?.name ?? null) : null
})
/** Another region than the learner's own: read only. */
const myRegionCode = computed(() => me.value?.region?.code ?? settings.data?.region_code ?? null)
const foreign = computed(() => store.area.kind === 'other' && shownRegion.value !== null && shownRegion.value.code !== myRegionCode.value)
/**
 * My row as the table shown counts it: `me.rank` is the place in the whole category; over the table of my region the neighbours and
 * `next_gap` are of the region (docs/32 §12.11) - the place there is `me.region.rank`.
 */
const tableMe = computed(() => {
  const mine = me.value
  if (mine === null || shownRegion.value === null || foreign.value) return mine
  return { rank: mine.region?.rank ?? null, points: mine.points, participant: mine.participant }
})
/** "Region «Tashkent»: 112th place" - my place in my region, once its table is shown. */
const regionRankLine = computed(() => {
  const region = me.value?.region
  if (!v2.value || !region || !region.shown || region.rank === null) return ''
  return t('board.region_rank', { name: region.name, rank: region.rank })
})

/** The top and the pages loaded after it, in the order of the table. */
const shownRows = computed((): BoardTableRow[] => [...(data.value?.top ?? []), ...store.more])

/** "To 4th place — 8 points" (v2, my own table): the nearest better rank on screen, else "to the place above". */
const nextGapLine = computed(() => {
  const mine = me.value
  const gap = mine?.next_gap
  const rankShown = tableMe.value?.rank ?? null
  if (!v2.value || readOnly.value || mine === null || rankShown === null || gap === null || gap === undefined) return ''
  const rank = nearestBetterRank([...shownRows.value, ...(data.value?.neighbours?.above ?? [])], rankShown)
  const points = tn('month.points', gap)
  return rank === null ? t('month.next_gap_plain', { points }) : t('league.to_next', { rank, points })
})

function onCategory(value: ScoreCategory): void {
  void store.selectCategory(value)
}

function onArea(value: BoardArea): void {
  void store.selectArea(value)
}

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

// A region chosen in the card "Choose your region" (the sheet saves it): the tables of a region are asked again.
watch(
  () => settings.data?.region_code,
  (now, before) => {
    if (before !== undefined && now !== before) void store.regionChanged()
  },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="month-view">
    <PageHeader brand :eyebrow="data?.enabled ? daysLine || undefined : undefined" :title="title" large data-testid="month" />
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" scrollable class="mb-3.5" />

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

        <!-- The two tables of a v2 month (docs/32 §8.3): Free and Pro; the other one is read only. And the tables of a region. -->
        <BoardFilters
          v-if="v2"
          :category="shownCategory"
          :categoryLabel="t('month.category_switch')"
          :area="store.area"
          :showArea="showArea"
          @category="onCategory"
          @area="onArea"
        />
        <p v-if="readOnlyLine" class="card text-[15px] leading-[22px] text-muted" data-testid="read-only">{{ readOnlyLine }}</p>
        <BoardRegionState v-if="v2" :notSet="regionNotSet" :region="shownRegion" :foreign="foreign" :mine="myRegionName" />

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
          <p v-if="regionRankLine" class="text-[15px] leading-5" data-testid="region-rank">{{ regionRankLine }}</p>
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
        <template v-if="!tableHidden">
          <BoardTable
            v-if="data.top.length > 0"
            :title="t('month.top')"
            :participants="v2 ? data.month.participants : null"
            :rows="shownRows"
            :cursor="store.cursor"
            :loadingMore="store.loadingMore"
            :busy="store.switching"
            :moreError="store.moreError"
            :near="data.neighbours"
            :withNeighbours="v2"
            :readOnly="readOnly"
            :me="tableMe"
            @more="store.loadMore()"
          />
          <section v-else class="card text-[15px] leading-[22px] text-muted" data-testid="empty">
            {{ t('month.empty') }}
          </section>
        </template>

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
