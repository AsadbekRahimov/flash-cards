<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { formatDate } from '@/composables/format'
import { tierLabelKey } from '@/composables/leagueTiers'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useLeagueStore } from '@/stores/league'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { LeagueResult, LeagueStandingItem, LeagueZone } from '@/api/types'

/**
 * "Rating" tab, the League pane (docs/13 §7.3, docs/14 §6.7, docs/04 A.13, the League mock-up of docs/design): above the title the
 * week and how long is left, the title is the league ("Silver league"); my card — the place, the points, a thin line and how many
 * points it takes to pass the one above; the standings as plain rows (the place in mono, the first name, the points), with the line of
 * the promotion zone (and of the demotion zone, where the league has one), my row highlighted; a long group shows its top and my
 * neighbours until "All the table" is tapped; then "Start a lesson", last week's result, the rules and the league settings.
 * A tab root (the other panes are Month and Friends): no system "Back" button.
 */
const auth = useAuthStore()
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

/** The title: the league of the learner; "League" while there is none (not placed, opted out, switched off). */
const title = computed(() => (membership.value ? tierHeading(membership.value.tier_key) : t('league.title')))

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

/** The row right above mine: what it takes to pass it ("To 3rd place — 25 points"), and how close I am to it (the line). */
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

const nextPercent = computed((): number => {
  const m = membership.value
  const row = above.value
  if (!m) return 0
  if (!row) return 100
  return row.points > 0 ? (m.points / row.points) * 100 : 0
})

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
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" class="mt-3.5" />

    <LoadingState v-if="!data && !message" class="mt-4" />

    <ErrorState v-else-if="!data" :message="message" class="mt-4" @retry="load" />

    <template v-else>
      <PageHeader :eyebrow="eyebrow || undefined" :title="title" large data-testid="week" />

      <div class="space-y-3.5">
        <InlineAlert v-if="message" tone="danger" :message="message" data-testid="reload-error" />

        <section v-if="!data.enabled" class="card text-[15px] leading-[22px] text-muted" data-testid="disabled">
          {{ t('league.disabled') }}
        </section>

        <template v-else-if="membership">
          <section class="card flex flex-col gap-2.5 px-[18px] py-4" data-testid="my-card">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-[15px] leading-5 font-semibold" data-testid="my-place">{{ t('league.my_rank', { rank: membership.rank }) }}</span>
              <span class="font-mono text-sm" data-testid="my-points">{{ membership.points }}</span>
            </div>
            <ProgressLine :value="nextPercent" :label="t('league.next_progress')" data-testid="my-line" />
            <p class="text-[13px] leading-[18px] text-muted" data-testid="to-next">
              {{
                toNext !== null
                  ? t('league.to_next', { rank: membership.rank - 1, points: tn('league.points', toNext) })
                  : t('league.first_place')
              }}
            </p>
            <p v-if="membership.points_to_safety !== null" class="text-[13px] leading-[18px] text-danger-ink" data-testid="to-safety">
              {{ t('league.to_safety', { points: tn('league.points', membership.points_to_safety) }) }}
            </p>
            <p v-else-if="membership.points_to_promote !== null" class="text-[13px] leading-[18px] text-muted" data-testid="to-promote">
              {{ t('league.to_promote', { points: tn('league.points', membership.points_to_promote) }) }}
            </p>
            <p v-else-if="isTopTier" class="text-[13px] leading-[18px] text-muted" data-testid="top-tier">
              {{ t('league.top_tier') }}
            </p>
          </section>

          <section class="list" :aria-label="t('league.standings')" data-testid="standings">
            <template v-for="line in tableLines" :key="line.key">
              <div
                v-if="line.kind === 'row' && line.row"
                class="flex items-center gap-3.5 px-[18px] py-3"
                :class="line.row.is_me ? 'bg-accent-soft' : ''"
                :data-zone="zoneOf(line.row.rank)"
                :data-testid="line.row.is_me ? 'row-me' : 'row'"
              >
                <span
                  class="w-[18px] shrink-0 font-mono text-[13px]"
                  :class="line.row.is_me ? 'text-accent-ink' : 'text-muted'"
                  data-testid="rank"
                >{{ line.row.rank }}</span>
                <span class="min-w-0 flex-1 truncate text-[15px] leading-5" :class="{ 'font-semibold': line.row.is_me }">
                  {{ line.row.is_me ? `${rowName(line.row)} · ${t('league.you')}` : rowName(line.row) }}
                </span>
                <span class="shrink-0 font-mono text-sm" :class="{ 'font-medium': line.row.is_me }" data-testid="points">{{ line.row.points }}</span>
              </div>
              <div
                v-else-if="line.kind === 'promotion'"
                class="border-t border-success-line bg-success-soft px-[18px] py-1.5 text-xs leading-4 text-success-ink"
                data-testid="line-promotion"
              >
                {{ line.text }}
              </div>
              <div
                v-else-if="line.kind === 'demotion'"
                class="border-t border-danger-line bg-danger-soft px-[18px] py-1.5 text-xs leading-4 text-danger-ink"
                data-testid="line-demotion"
              >
                {{ line.text }}
              </div>
              <div v-else class="px-[18px] py-1 font-mono text-[13px] leading-4 text-muted" aria-hidden="true" data-testid="gap">…</div>
            </template>
            <button
              v-if="folds"
              type="button"
              class="flex min-h-11 w-full items-center px-[18px] py-3 text-left text-sm text-accent"
              :aria-expanded="expanded"
              data-testid="table-toggle"
              @click="expanded = !expanded"
            >
              {{ expanded ? t('league.table_less') : t('league.table_all', { members: tn('league.members', membership.members_count) }) }}
            </button>
          </section>
        </template>

        <section v-else-if="data.opted_out" class="card text-[15px] leading-[22px] text-muted" data-testid="opted-out">
          {{ t('league.opted_out') }}
        </section>

        <section v-else class="card text-[15px] leading-[22px] text-muted" data-testid="not-placed">
          {{ t('league.not_placed') }}
        </section>

        <RouterLink v-if="data.enabled && !data.opted_out" :to="{ name: 'today' }" class="btn btn-primary btn-block" data-testid="start-btn">
          {{ t('today.review.start') }}
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
            <span class="min-w-0 flex-1 text-[15px] leading-5 font-semibold">{{ t('league.rules_title') }}</span>
            <AppIcon name="chevron-down" :size="16" class="text-muted transition-transform duration-150 group-open:rotate-180" />
          </summary>
          <div class="space-y-2 px-[18px] pt-1 pb-4 text-[15px] leading-[22px] text-muted">
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
