<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import RegionPromptCard from '@/components/RegionPromptCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { formatDate } from '@/composables/format'
import { tierLabelKey } from '@/composables/leagueTiers'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useLeagueStore } from '@/stores/league'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { LeagueResult, LeagueStandingItem, LeagueZone } from '@/api/types'

/**
 * "Rating" tab, the League pane (docs/13 §7.3, docs/14 §6.7, docs/04 A.13, docs/design/2026-11-25-redesign League): the brand row and
 * the title "Weekly league"; the big tinted card — the week and how long is left, the league ("Silver league"), my place, my points in a
 * pill and how many it takes to pass the one above (what the server sends: `membership.rank` / `points` / `standings`); the standings
 * as rows (the place in a soft badge, the first name, the points), with a thin line of the promotion zone (and of the demotion zone,
 * where the league has one), my row tinted; a long group shows its top and my neighbours until "All the table" is tapped; then "How to
 * climb higher" with the way to the plan of the day, last week's result, the rules and the league settings.
 * What gives points is said as it is: lessons and reviews, and the Caravan only while the game is open to the learner (`/me.game`);
 * practice games and the lesson review give none, so "games" are never named.
 * A tab root (the other panes are Month and Friends): no system "Back" button.
 */
const auth = useAuthStore()
const billing = useBillingStore()
const league = useLeagueStore()
const { t, tn, locale } = useI18n()

useBackButton(null)

const failed = ref('')
const expanded = ref(false)

const data = computed(() => league.data)
const message = computed(() => failed.value || league.errorMessage)
const membership = computed(() => data.value?.membership ?? null)

const ratingTabs = useRatingTabs()

const LAST_WEEK: Record<LeagueResult, StringKey> = {
  promoted: 'league.last.promoted',
  stayed: 'league.last.stayed',
  demoted: 'league.last.demoted',
}

/** "Silver league" per tier; an unknown key falls back to the lowest tier, as `tierLabelKey` does. */
const HEADINGS: Readonly<Record<string, StringKey>> = {
  bronze: 'league.heading.bronze',
  silver: 'league.heading.silver',
  gold: 'league.heading.gold',
  platinum: 'league.heading.platinum',
  diamond: 'league.heading.diamond',
}

const tierName = (key: string): string => t(tierLabelKey(key))
const tierHeading = (key: string): string =>
  t(Object.prototype.hasOwnProperty.call(HEADINGS, key) ? (HEADINGS[key] as StringKey) : 'league.heading.bronze')

/** The league of the learner, in the big card: "Silver league". */
const heading = computed(() => (membership.value ? tierHeading(membership.value.tier_key) : ''))

/** The top league has nowhere to be promoted to, the lowest one nowhere to be demoted to (LeagueService). */
const canPromote = computed(() => {
  const m = membership.value
  const tiers = data.value?.rules.tiers ?? []
  return m !== null && m.tier < tiers.length - 1
})
const canDemote = computed(() => (membership.value?.tier ?? 0) > 0)
const isTopTier = computed(() => membership.value !== null && !canPromote.value)

/** The week as "26 Oct – 1 Nov" (calendar dates of the league's week). */
const weekRange = computed(() => {
  const week = data.value?.week
  if (!week) return ''
  return t('league.week', {
    from: formatDate(week.starts_on, 'dayMonthShort', locale.value),
    to: formatDate(week.ends_on, 'dayMonthShort', locale.value),
  })
})

const DAY_MS = 24 * 60 * 60 * 1000

/** How long the week has left, counted to Monday 00:00 of the league's zone: "2 days left", "last day". */
const leftLine = computed(() => {
  const iso = data.value?.week.closes_at
  const closes = iso ? Date.parse(iso) : NaN
  if (!Number.isFinite(closes)) return ''
  const days = Math.ceil((closes - Date.now()) / DAY_MS)
  // The close has passed (the week is being summed up, or the phone's clock runs ahead): no "last day" for a day that is over.
  if (days <= 0) return ''
  return days <= 1 ? t('league.last_day') : t('league.days_left', { count: days })
})

const eyebrow = computed(() => [weekRange.value, leftLine.value].filter((part) => part !== '').join(' · '))

/** The league closes on Monday 00:00 of its own zone; the phone shows that moment in its own time: "Mon 00:00" (under the rules). */
const closesLine = computed(() => {
  const iso = data.value?.week.closes_at
  const when = iso ? formatDate(iso, 'weekdayTime', locale.value) : ''
  return when === '' ? '' : t('league.closes', { when })
})

/** Place in the group: the promotion zone is the top `promote`, the demotion zone the bottom `demote` (promotion wins an overlap). */
function zoneOf(rank: number): LeagueZone {
  const m = membership.value
  const rules = data.value?.rules
  if (!m || !rules) return 'safe'
  if (canPromote.value && rank <= rules.promote) return 'promotion'
  if (canDemote.value && rank > m.members_count - rules.demote) return 'demotion'
  return 'safe'
}

const rowName = (row: LeagueStandingItem): string => row.name || t('league.anonymous')

/** The row right above mine: what it takes to pass it ("to 3rd place — 25 points"). */
const above = computed((): LeagueStandingItem | null => {
  const m = membership.value
  if (!m || m.rank <= 1) return null
  return m.standings.find((row) => row.rank === m.rank - 1) ?? null
})

/** Points to pass the row above: one more than the difference (an equal score does not pass it). */
const toNext = computed((): number | null => {
  const m = membership.value
  const row = above.value
  return m && row ? Math.max(1, row.points - m.points + 1) : null
})

/** "How to climb higher": the title by where the learner stands, the line by what gives points to this learner. */
const climbTitle = computed(() => {
  const m = membership.value
  if (!m) return t('league.climb.title_join')
  return m.rank <= 1 ? t('league.climb.title_first') : t('league.climb.title')
})
const climbText = computed(() => t(billing.gameAccess.available ? 'league.climb.points_caravan' : 'league.climb.points'))

/** A group longer than this is folded: the top and my neighbours, until "All the table". */
const FOLDED_ROWS = 7

interface TableLine {
  kind: 'row' | 'promotion' | 'demotion' | 'gap'
  key: string
  row?: LeagueStandingItem
  text?: string
}

const folds = computed(() => (membership.value?.standings.length ?? 0) > FOLDED_ROWS + 1)

const visibleRows = computed((): LeagueStandingItem[] => {
  const m = membership.value
  if (!m) return []
  if (!folds.value || expanded.value) return m.standings
  if (m.rank <= FOLDED_ROWS - 1) return m.standings.filter((row) => row.rank <= FOLDED_ROWS)
  return m.standings.filter((row) => row.rank <= 3 || Math.abs(row.rank - m.rank) <= 1)
})

/**
 * The rows with the zone lines between them: the promotion line under the last place that goes up, the demotion line above the first
 * that goes down — wherever the boundary falls between two shown rows, also across a fold ("…").
 */
const tableLines = computed((): TableLine[] => {
  const m = membership.value
  const rules = data.value?.rules
  if (!m || !rules) return []
  const promoteAfter = canPromote.value ? rules.promote : null
  const demoteFrom = canDemote.value ? Math.max(m.members_count - rules.demote + 1, (promoteAfter ?? 0) + 1) : null
  const upTier = rules.tiers[m.tier + 1]
  const downTier = rules.tiers[m.tier - 1]

  const lines: TableLine[] = []
  let previous: number | null = null
  for (const row of visibleRows.value) {
    if (previous !== null) {
      if (promoteAfter !== null && upTier !== undefined && previous <= promoteAfter && row.rank > promoteAfter) {
        lines.push({ kind: 'promotion', key: 'promotion', text: t('league.line.promotion', { tier: tierName(upTier) }) })
      }
      if (row.rank > previous + 1) lines.push({ kind: 'gap', key: `gap-${row.rank}` })
      if (demoteFrom !== null && downTier !== undefined && previous < demoteFrom && row.rank >= demoteFrom) {
        lines.push({ kind: 'demotion', key: 'demotion', text: t('league.line.demotion', { tier: tierName(downTier) }) })
      }
    }
    lines.push({ kind: 'row', key: `row-${row.rank}`, row })
    previous = row.rank
  }
  return lines
})

async function load(): Promise<void> {
  failed.value = ''
  try {
    await auth.ensure()
  } catch (err) {
    failed.value = errorMessage(err, 'settings')
    return
  }
  await league.load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page" data-testid="league-view">
    <PageHeader brand :title="t('league.weekly_title')" :subtitle="!membership && data?.enabled && !data.opted_out ? eyebrow || undefined : undefined" large />
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" class="mb-3.5" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <template v-else>
      <div class="space-y-3">
        <InlineAlert v-if="message" tone="danger" :message="message" data-testid="reload-error" />

        <section v-if="!data.enabled" class="card text-[15px] leading-[22px] text-muted" data-testid="disabled">
          {{ t('league.disabled') }}
        </section>

        <!-- The big card of the mock-up: the week, the league, my place, my points and the way to the place above. -->
        <section v-else-if="membership" class="relative flex flex-col gap-3 overflow-hidden rounded-hero bg-highlight p-4" data-testid="my-card">
          <!-- The picture of the mock-up: a cup with a star on a stand between branches, drawn in the tokens of the palette. -->
          <svg
            aria-hidden="true"
            width="150"
            height="150"
            viewBox="0 0 200 200"
            fill="none"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="pointer-events-none absolute top-2 right-1.5 stroke-accent max-[359px]:size-[112px]"
            data-testid="hero-art"
          >
            <path d="M100 4v14M72 10l6 12M128 10l-6 12" stroke-width="1.6" />
            <path d="M64 48c-22-4-28 30 6 38M136 48c22-4 28 30-6 38" stroke-width="4" />
            <path d="M64 38h72v40c0 22-14 38-36 38s-36-16-36-38z" class="fill-surface" />
            <ellipse cx="100" cy="38" rx="36" ry="7" class="fill-nav-active" />
            <path d="M100 58l5 10 11 2-8 8 2 11-10-5-10 5 2-11-8-8 11-2z" class="fill-primary/60" />
            <path d="M92 116v20h16v-20" class="fill-surface" />
            <path d="M78 136h44v12H78z" class="fill-nav-active" />
            <path d="M66 148h68a6 6 0 0 1 6 6v16H60v-16a6 6 0 0 1 6-6z" class="fill-surface" />
            <g class="fill-nav-active">
              <path d="M44 152c-14-8-20-22-18-38" fill="none" />
              <path d="M34 144c-10 0-16-6-18-14 8-2 16 4 18 14zM28 128c-10-2-14-10-14-18 8 0 14 8 14 18zM50 158c-8 6-18 6-24 0 6-6 16-6 24 0z" />
            </g>
            <g class="fill-nav-active" transform="translate(200 0) scale(-1 1)">
              <path d="M44 152c-14-8-20-22-18-38" fill="none" />
              <path d="M34 144c-10 0-16-6-18-14 8-2 16 4 18 14zM28 128c-10-2-14-10-14-18 8 0 14 8 14 18zM50 158c-8 6-18 6-24 0 6-6 16-6 24 0z" />
            </g>
            <path d="M40 178h120M22 184h156" stroke-width="1.4" />
          </svg>

          <div class="relative flex max-w-[210px] flex-col gap-1">
            <p v-if="eyebrow" class="text-sm leading-[18px] font-semibold" data-testid="eyebrow">{{ eyebrow }}</p>
            <h2 class="text-[26px] leading-8 font-extrabold tracking-[-0.5px] break-words" data-testid="tier">{{ heading }}</h2>
            <svg aria-hidden="true" width="150" height="10" viewBox="0 0 150 10" fill="none" class="max-w-full stroke-primary/60">
              <path d="M3 7c40-5 90-6 144-3" stroke-width="4" stroke-linecap="round" />
            </svg>
            <p class="mt-0.5 text-base leading-[22px]" data-testid="my-place">{{ t('league.my_rank', { rank: membership.rank }) }}</p>
          </div>

          <div class="relative flex items-center gap-3">
            <span
              class="flex h-[38px] shrink-0 items-center gap-1.5 rounded-control bg-primary px-3.5 text-[15px] leading-5 font-bold text-on-primary tabular-nums"
              data-testid="my-points"
            >
              <AppIcon name="star" solid :size="16" />
              {{ tn('league.points', membership.points) }}
            </span>
            <span class="h-[30px] w-px shrink-0 bg-accent-line" aria-hidden="true" />
            <p class="min-w-0 text-[13px] leading-[17px]" data-testid="to-next">
              <template v-if="toNext !== null">
                {{ t('league.to_next_short', { rank: membership.rank - 1 }) }}<br />{{ tn('league.points', toNext) }}
              </template>
              <template v-else>{{ t('league.first_place') }}</template>
            </p>
          </div>

          <p v-if="membership.points_to_safety !== null" class="relative text-[13px] leading-[18px] font-semibold text-danger-ink" data-testid="to-safety">
            {{ t('league.to_safety', { points: tn('league.points', membership.points_to_safety) }) }}
          </p>
          <p v-else-if="membership.points_to_promote !== null && membership.points_to_promote !== toNext" class="relative text-[13px] leading-[18px] text-muted" data-testid="to-promote">
            {{ t('league.to_promote', { points: tn('league.points', membership.points_to_promote) }) }}
          </p>
          <p v-else-if="isTopTier && membership.points_to_promote === null" class="relative text-[13px] leading-[18px] text-muted" data-testid="top-tier">
            {{ t('league.top_tier') }}
          </p>
        </section>

        <section v-else-if="data.opted_out" class="card text-[15px] leading-[22px] text-muted" data-testid="opted-out">
          {{ t('league.opted_out') }}
        </section>

        <section v-else class="card text-[15px] leading-[22px] text-muted" data-testid="not-placed">
          {{ t('league.not_placed') }}
        </section>

        <!-- "Choose your region" (docs/35 §2.3): only for a learner without one, hidden for 30 days by "Not now". -->
        <RegionPromptCard />

        <section v-if="data.enabled && membership" class="card px-2.5 pt-3.5 pb-2.5" :aria-label="t('league.standings')" data-testid="standings">
          <h2 class="px-2.5 pb-1.5 text-[19px] leading-6 font-extrabold">{{ t('league.standings') }}</h2>
          <template v-for="line in tableLines" :key="line.key">
            <div
              v-if="line.kind === 'row' && line.row"
              class="flex items-center gap-3.5 px-2.5 py-[9px]"
              :class="line.row.is_me ? 'my-1 rounded-control bg-nav-active' : 'border-b border-divider'"
              :data-testid="line.row.is_me ? 'row-me' : 'row'"
            >
              <span
                class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg text-[15px] font-bold text-accent tabular-nums"
                :class="line.row.is_me ? 'bg-surface' : 'bg-accent-soft'"
                :data-zone="zoneOf(line.row.rank)"
                data-testid="rank"
              >{{ line.row.rank }}</span>
              <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">
                {{ line.row.is_me ? `${rowName(line.row)} · ${t('league.you')}` : rowName(line.row) }}
              </span>
              <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ line.row.points }}</span>
            </div>
            <!-- The zone lines: thin captions between the rows, not part of the mock-up's table but the rules of the league. -->
            <div
              v-else-if="line.kind === 'promotion'"
              class="border-t border-success-line bg-success-soft px-2.5 py-1 text-xs leading-4 text-success-ink"
              data-testid="line-promotion"
            >
              {{ line.text }}
            </div>
            <div
              v-else-if="line.kind === 'demotion'"
              class="border-t border-danger-line bg-danger-soft px-2.5 py-1 text-xs leading-4 text-danger-ink"
              data-testid="line-demotion"
            >
              {{ line.text }}
            </div>
            <div v-else class="px-2.5 py-1 text-[13px] leading-4 text-muted" aria-hidden="true" data-testid="gap">…</div>
          </template>
          <button
            v-if="folds"
            type="button"
            class="flex min-h-11 w-full items-center px-2.5 py-2 text-left text-sm font-semibold text-accent"
            :aria-expanded="expanded"
            data-testid="table-toggle"
            @click="expanded = !expanded"
          >
            {{ expanded ? t('league.table_less') : t('league.table_all', { members: tn('league.members', membership.members_count) }) }}
          </button>
        </section>

        <!-- "How to climb higher": what gives points, and the way to the plan of the day (the Today screen). -->
        <RouterLink
          v-if="data.enabled && !data.opted_out"
          :to="{ name: 'today' }"
          class="flex min-h-12 items-center gap-2.5 rounded-option bg-accent-soft px-3.5 py-3 active:opacity-70"
          data-testid="climb"
        >
          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-[15px] leading-5 font-bold" data-testid="climb-title">{{ climbTitle }}</span>
            <span class="text-[13px] leading-[18px] text-muted" data-testid="climb-text">{{ climbText }}</span>
          </span>
          <span class="flex shrink-0 items-center gap-1 text-sm leading-5 font-bold text-accent" data-testid="climb-link">
            {{ t('league.climb.link') }}
            <AppIcon name="chevron-right" :size="18" />
          </span>
        </RouterLink>

        <ListGroup v-if="data.last_week" :header="t('league.last_week')" data-testid="last-week">
          <ListRow
            :title="
              t(LAST_WEEK[data.last_week.result], {
                tier: tierName(data.last_week.tier_key),
                rank: data.last_week.rank,
                points: tn('league.points', data.last_week.points),
              })
            "
          />
        </ListGroup>

        <details class="list group" data-testid="rules">
          <summary class="list-row cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
            <span class="min-w-0 flex-1 text-base leading-[22px] font-bold">{{ t('league.rules_title') }}</span>
            <AppIcon name="chevron-down" :size="16" class="text-muted transition-transform duration-150 group-open:rotate-180" />
          </summary>
          <div class="space-y-2 px-3.5 pt-1 pb-4 text-[15px] leading-[22px] text-muted">
            <p v-if="closesLine" data-testid="closes">{{ closesLine }}</p>
            <p>{{ t('league.rules', { capacity: data.rules.capacity, promote: data.rules.promote, demote: data.rules.demote }) }}</p>
          </div>
        </details>

        <RouterLink :to="{ name: 'settings' }" class="btn btn-plain btn-block" data-testid="settings-link">
          {{ t('league.settings.open') }}
        </RouterLink>
      </div>
    </template>
  </div>
</template>
