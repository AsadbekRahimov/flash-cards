<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow, { type TileColor } from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import type { IconName } from '@/components/ui/icons'
import { formatDate } from '@/composables/format'
import { tierColor, tierLabelKey } from '@/composables/leagueTiers'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useLeagueStore } from '@/stores/league'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { LeagueResult, LeagueStandingItem, LeagueZone } from '@/api/types'

/**
 * "Rating" tab, the League pane (docs/13 §7.3, docs/14 §6.7, docs/04 A.13): my group this week — place, points,
 * what it takes to move up or stay safe, the standings (first names and points only) — and last week's result.
 * A tab root (the other pane is Friends): no system "Back" button.
 */
const auth = useAuthStore()
const league = useLeagueStore()
const { t, tn, locale } = useI18n()

useBackButton(null)

const failed = ref('')

const data = computed(() => league.data)
const message = computed(() => failed.value || league.errorMessage)
const membership = computed(() => data.value?.membership ?? null)

const ratingTabs = computed(() => [
  { to: { name: 'league' }, label: t('league.title'), testid: 'seg-league' },
  { to: { name: 'friends' }, label: t('friends.title'), testid: 'seg-friends' },
])

const ZONES: Record<LeagueZone, { label: StringKey; chip: string; icon: IconName | null }> = {
  promotion: { label: 'league.zone.promotion', chip: 'chip-success', icon: 'arrow-trending-up' },
  safe: { label: 'league.zone.safe', chip: 'chip-neutral', icon: null },
  demotion: { label: 'league.zone.demotion', chip: 'chip-danger', icon: 'arrow-trending-down' },
}

const LAST_WEEK: Record<LeagueResult, { label: StringKey; icon: IconName; tile: TileColor }> = {
  promoted: { label: 'league.last.promoted', icon: 'arrow-trending-up', tile: 'green' },
  stayed: { label: 'league.last.stayed', icon: 'arrow-right', tile: 'gray' },
  demoted: { label: 'league.last.demoted', icon: 'arrow-trending-down', tile: 'red' },
}

const tierName = (key: string): string => t(tierLabelKey(key))

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

/** The league closes on Monday 00:00 of its own zone; the phone shows that moment in its own time: "Mon 00:00". */
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

const RANK_TONES: Record<LeagueZone, string> = {
  promotion: 'text-success-ink',
  safe: 'text-tg-hint',
  demotion: 'text-danger-ink',
}

const rowName = (row: LeagueStandingItem): string => row.name || t('league.anonymous')

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
    <PageHeader :title="t('league.rating_title')" large />
    <SegmentedNav :items="ratingTabs" class="mb-4" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <p
        v-if="message"
        class="flex items-start gap-2 rounded-xl bg-danger-soft px-3.5 py-3 text-[15px] leading-5 text-danger-ink"
        role="alert"
        data-testid="reload-error"
      >
        <AppIcon name="exclamation-circle" :size="20" class="mt-px" />
        <span>{{ message }}</span>
      </p>

      <ListGroup data-testid="week">
        <ListRow icon="calendar-days" tile="blue" :title="weekRange" :subtitle="closesLine || undefined" />
      </ListGroup>

      <ListGroup v-if="!data.enabled" data-testid="disabled">
        <ListRow icon="lock-closed" tile="gray" :title="t('league.disabled')" />
      </ListGroup>

      <template v-else-if="membership">
        <section class="card" data-testid="my-card">
          <div class="flex items-center gap-4">
            <span
              class="icon-tile size-14 rounded-2xl"
              :style="{ background: tierColor(membership.tier_key) }"
              data-testid="tier-tile"
            >
              <AppIcon name="trophy" solid :size="30" />
            </span>
            <div class="min-w-0 flex-1">
              <div class="text-[13px] leading-[18px] font-medium text-tg-hint" data-testid="tier-name">
                {{ tierName(membership.tier_key) }}
              </div>
              <div class="text-[26px] leading-8 font-bold tracking-tight" data-testid="my-place">
                {{ t('league.my_place', { rank: membership.rank, members: membership.members_count }) }}
              </div>
              <div class="text-[15px] leading-5 text-tg-subtitle" data-testid="my-points">
                {{ tn('league.points', membership.points) }}
              </div>
            </div>
          </div>

          <div class="mt-3 flex flex-col items-start gap-2">
            <span class="chip" :class="ZONES[membership.zone].chip" data-testid="zone">
              <AppIcon v-if="ZONES[membership.zone].icon" :name="ZONES[membership.zone].icon!" :size="14" />
              {{ t(ZONES[membership.zone].label) }}
            </span>
            <p v-if="membership.points_to_promote !== null" class="text-[15px] leading-5 text-tg-subtitle" data-testid="to-promote">
              {{ t('league.to_promote', { points: tn('league.points', membership.points_to_promote) }) }}
            </p>
            <p v-else-if="isTopTier" class="text-[15px] leading-5 text-tg-subtitle" data-testid="top-tier">
              {{ t('league.top_tier') }}
            </p>
            <p v-if="membership.points_to_safety !== null" class="text-[15px] leading-5 text-danger-ink" data-testid="to-safety">
              {{ t('league.to_safety', { points: tn('league.points', membership.points_to_safety) }) }}
            </p>
          </div>
        </section>

        <ListGroup :header="t('league.standings')" data-testid="standings">
          <div
            v-for="row in membership.standings"
            :key="row.rank"
            class="list-row"
            :class="{ 'bg-accent-soft': row.is_me }"
            :data-testid="row.is_me ? 'row-me' : 'row'"
          >
            <span
              class="w-7 shrink-0 text-center text-[15px] font-semibold tabular-nums"
              :class="RANK_TONES[zoneOf(row.rank)]"
              :data-zone="zoneOf(row.rank)"
              data-testid="rank"
            >
              {{ row.rank }}
            </span>
            <InitialAvatar :name="rowName(row)" size="sm" :accent="row.is_me" />
            <span class="min-w-0 flex-1 truncate text-base" :class="{ 'font-semibold': row.is_me }">{{ rowName(row) }}</span>
            <span v-if="row.is_me" class="chip chip-accent" data-testid="me-chip">{{ t('league.you') }}</span>
            <span class="shrink-0 text-[15px] text-tg-subtitle tabular-nums">{{ tn('league.points', row.points) }}</span>
          </div>
        </ListGroup>
      </template>

      <ListGroup v-else-if="data.opted_out" data-testid="opted-out">
        <ListRow icon="information-circle" tile="gray" :title="t('league.opted_out')" />
      </ListGroup>

      <ListGroup v-else data-testid="not-placed">
        <ListRow icon="trophy" tile="blue" :title="t('league.not_placed')" />
      </ListGroup>

      <ListGroup v-if="data.last_week" :header="t('league.last_week')" data-testid="last-week">
        <ListRow
          :icon="LAST_WEEK[data.last_week.result].icon"
          :tile="LAST_WEEK[data.last_week.result].tile"
          :title="
            t(LAST_WEEK[data.last_week.result].label, {
              tier: tierName(data.last_week.tier_key),
              rank: data.last_week.rank,
              points: tn('league.points', data.last_week.points),
            })
          "
        />
      </ListGroup>

      <details class="list group" data-testid="rules">
        <summary class="list-row list-row-icon cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
          <span class="icon-tile tile-gray"><AppIcon name="information-circle" :size="18" /></span>
          <span class="min-w-0 flex-1 text-base">{{ t('league.rules_title') }}</span>
          <AppIcon name="chevron-down" :size="16" class="text-tg-hint transition-transform duration-150 group-open:rotate-180" />
        </summary>
        <p class="px-4 pt-1 pb-4 text-[15px] leading-5 text-tg-subtitle">
          {{ t('league.rules', { capacity: data.rules.capacity, promote: data.rules.promote, demote: data.rules.demote }) }}
        </p>
      </details>

      <RouterLink :to="{ name: 'settings' }" class="btn btn-plain btn-block" data-testid="settings-link">
        {{ t('league.settings.open') }}
      </RouterLink>
    </div>
  </div>
</template>
