<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import RegionPromptCard from '@/components/RegionPromptCard.vue'
import BoardFilters from '@/components/rating/BoardFilters.vue'
import BoardRegionState from '@/components/rating/BoardRegionState.vue'
import BoardSkeleton from '@/components/rating/BoardSkeleton.vue'
import BoardTable from '@/components/rating/BoardTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { nearestBetterRank, type BoardTableRow } from '@/composables/boardRows'
import { formatDate } from '@/composables/format'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAllTimeRatingStore } from '@/stores/allTimeRating'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import type { BoardArea } from '@/stores/ratingBoard'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { ScoreCategory } from '@/api/types'

/**
 * "Rating" tab, the All time pane (docs/32 §5, §12.11 "Контракт для экранов", docs/04 A.19a, docs/35 §2.4), drawn as the Month one:
 * the big tinted card with my place in the table shown, the points to the next place (`next_gap`), my place in my region, my own sum
 * over both tables (`my_total`, Free + Pro, in one line) and since when the points count; Free / Pro and "All / My region / Another
 * region…" (another one read only, picked without changing the learner's region); the table with the emoji of Pro, my neighbours and
 * "Show more" (`BoardTable` and `useBoardTable`, shared with the month). The server's `enabled: false` (the word points are not live):
 * the pane does not exist - back to the Month, and the segment is gone. A tab root: no system "Back" button.
 */
const auth = useAuthStore()
const store = useAllTimeRatingStore()
const settings = useSettingsStore()
const router = useRouter()
const { t, tn, locale } = useI18n()

useBackButton(null)

const failed = ref('')

const data = computed(() => store.data)
const message = computed(() => failed.value || store.errorMessage)
const me = computed(() => data.value?.me ?? null)
const ratingTabs = useRatingTabs()

const categoryName = (category: ScoreCategory): string => t(category === 'pro' ? 'score.category.pro' : 'score.category.free')

const countedSince = computed(() => {
  const since = data.value?.counted_since
  const date = since ? formatDate(since, 'dayMonthYear', locale.value) : ''
  return date === '' ? '' : t('alltime.counted_since', { date })
})

/** The table the switch shows: the one asked for (at once, before its answer), else the one the server shows. */
const shownCategory = computed((): ScoreCategory => store.category ?? data.value?.category ?? data.value?.my_category ?? 'free')
const readOnly = computed(() => data.value?.read_only === true)

/** "You compete in the Free table": only over the table of the other category. */
const readOnlyLine = computed(() => {
  const shown = data.value?.category
  const mine = data.value?.my_category
  if (shown === undefined || mine === undefined || shown === mine) return ''
  return t('month.read_only', { category: categoryName(shown), mine: categoryName(mine) })
})

const shownRegion = computed(() => (data.value?.scope === 'region' ? (data.value.region ?? null) : null))
const tableHidden = computed(() => store.regionNotSet || (shownRegion.value !== null && !shownRegion.value.shown))
const myRegionName = computed(() => {
  const region = me.value?.region
  if (region) return region.name
  const code = settings.data?.region_code
  return code ? (settings.data?.options.regions?.find((item) => item.code === code)?.name ?? null) : null
})
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

const optedOut = computed(
  () => (!!data.value?.opted_out && (me.value === null || !me.value.participant)) || me.value?.not_counted_reason === 'opted_out',
)
const blocked = computed(() => me.value !== null && !me.value.participant && me.value.not_counted_reason === 'blocked')

const shownRows = computed((): BoardTableRow[] => [...(data.value?.top ?? []), ...store.more])

/** "To 4th place — 8 points": the nearest better rank on screen, else "to the place above"; none over a read-only table. */
const nextGapLine = computed(() => {
  const mine = me.value
  const gap = mine?.next_gap
  const rankShown = tableMe.value?.rank ?? null
  if (readOnly.value || mine === null || rankShown === null || gap === null || gap === undefined) return ''
  const rank = nearestBetterRank([...shownRows.value, ...(data.value?.neighbours?.above ?? [])], rankShown)
  const points = tn('month.points', gap)
  return rank === null ? t('month.next_gap_plain', { points }) : t('league.to_next', { rank, points })
})

const regionRankLine = computed(() => {
  const region = me.value?.region
  if (!region || !region.shown || region.rank === null) return ''
  return t('board.region_rank', { name: region.name, rank: region.rank })
})

/** "My points of all time: 2 350 points (Free 1 400 + Pro 950)": the split only when both tables have points. */
const totalLine = computed(() => {
  const total = data.value?.my_total
  if (!total) return ''
  const points = tn('month.points', total.points)
  if (total.free !== null && total.pro !== null) return t('alltime.my_total_split', { points, free: total.free, pro: total.pro })
  return t('alltime.my_total', { points })
})

/** No row in the table shown: never studied, or no points in this category (the other table). */
const noRowLine = computed(() => {
  const shown = data.value?.category
  if (shown !== undefined && data.value?.my_category !== undefined && shown !== data.value.my_category) {
    return t('alltime.no_points_category', { category: categoryName(shown) })
  }
  return t('alltime.start_prompt')
})

function onCategory(value: ScoreCategory): void {
  void store.selectCategory(value)
}

function onArea(value: BoardArea): void {
  void store.selectArea(value)
}

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

// The word points are not live: there is no "All time" - the Month instead (the segment is hidden by the same answer).
watch(
  () => store.disabled,
  (disabled) => {
    if (disabled) void router.replace({ name: 'league-month' })
  },
  { immediate: true },
)

// A region chosen in the card "Choose your region" (the sheet saves it): the tables of a region are asked again.
watch(
  () => settings.data?.region_code,
  (now, before) => {
    if (before !== undefined && now !== before) void store.regionChanged()
  },
)

onMounted(() => {
  if (!store.disabled) void load()
})
</script>

<template>
  <div class="page" data-testid="all-time-view">
    <PageHeader brand :eyebrow="countedSince || undefined" :title="t('alltime.title')" large data-testid="all-time" />
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" scrollable class="mb-3.5" />

    <BoardSkeleton v-if="!data && !message && !store.regionNotSet" />

    <ErrorState v-else-if="!data && message" :message="message" @retry="load" />

    <div v-else-if="!data || data.enabled" class="space-y-3">
      <InlineAlert v-if="message" tone="danger" :message="message" data-testid="reload-error" />

      <RegionPromptCard />

      <BoardFilters
        :category="shownCategory"
        :categoryLabel="t('alltime.category_switch')"
        :area="store.area"
        :showArea="true"
        @category="onCategory"
        @area="onArea"
      />
      <p v-if="readOnlyLine" class="card t-body-m text-muted" data-testid="read-only">{{ readOnlyLine }}</p>
      <BoardRegionState :notSet="store.regionNotSet" :region="shownRegion" :foreign="foreign" :mine="myRegionName" />

      <template v-if="data">
        <section class="flex flex-col gap-1.5 rounded-hero bg-highlight p-5" data-testid="my-card">
          <template v-if="optedOut">
            <p class="t-body-m text-muted" data-testid="opted-out">{{ t('month.opted_out') }}</p>
          </template>
          <p v-else-if="me === null" class="t-title-m" data-testid="no-row">{{ noRowLine }}</p>
          <template v-else>
            <p class="t-title-m" data-testid="my-place">
              {{
                me.rank !== null
                  ? t('month.my_place', { rank: me.rank, points: tn('month.points', me.points) })
                  : t('month.my_noplace', { points: tn('month.points', me.points) })
              }}
            </p>
            <p v-if="nextGapLine" class="text-[15px] leading-5" data-testid="next-gap">{{ nextGapLine }}</p>
            <p v-if="regionRankLine" class="text-[15px] leading-5" data-testid="region-rank">{{ regionRankLine }}</p>
            <p v-if="me.long_words > 0" class="text-[13px] leading-[18px] text-muted" data-testid="long-words">
              {{ t('month.long_words', { count: me.long_words }) }}
            </p>
            <p v-if="blocked" class="text-[13px] leading-[18px] text-muted" data-testid="not-counted">{{ t('month.not_counted_blocked') }}</p>
          </template>
          <p v-if="totalLine" class="text-[13px] leading-[18px]" data-testid="my-total">{{ totalLine }}</p>
          <RouterLink v-if="optedOut" :to="{ name: 'settings' }" class="btn btn-secondary btn-block mt-1.5" data-testid="settings-link">
            {{ t('league.settings.open') }}
          </RouterLink>
          <RouterLink v-else-if="me === null" :to="{ name: 'today' }" class="btn btn-primary btn-block mt-1.5" data-testid="start-btn">
            {{ t('league.daily_plan') }}
          </RouterLink>
        </section>

        <template v-if="!tableHidden">
          <BoardTable
            v-if="(data.top?.length ?? 0) > 0"
            :title="t('alltime.top')"
            :participants="data.participants ?? null"
            :rows="shownRows"
            :cursor="store.cursor"
            :loadingMore="store.loadingMore"
            :busy="store.switching"
            :moreError="store.moreError"
            :near="data.neighbours"
            :withNeighbours="true"
            :readOnly="readOnly"
            :me="tableMe"
            @more="store.loadMore()"
          />
          <EmptyState v-else mascot="trophy" :text="t('alltime.empty')" data-testid="empty" />
        </template>
      </template>
    </div>
  </div>
</template>
