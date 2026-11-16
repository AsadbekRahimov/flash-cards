<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import InitialAvatar from '@/components/InitialAvatar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SegmentedNav from '@/components/ui/SegmentedNav.vue'
import { formatMonthYear } from '@/composables/format'
import { useRatingTabs } from '@/composables/ratingTabs'
import { useBackButton } from '@/composables/useBackButton'
import { useAuthStore } from '@/stores/auth'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'

/**
 * "Rating" tab, the Month pane (docs/13 §7.6, docs/14 §6.7, docs/04 A.19): the month's prizes, my place, the top (first names and
 * points only), how the points work and last month's winners. The points are the league's. A tab root: no system "Back" button.
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

const MEDALS = ['🥇', '🥈', '🥉']
const medal = (place: number): string => MEDALS[place - 1] ?? ''

const monthTitle = computed(() => (data.value ? formatMonthYear(data.value.month.starts_on, locale.value) : ''))

/** The month is "open" until the server says it is closing / waiting for the admin's review; no row yet = still open. */
const isOpen = computed(() => (data.value?.month.status ?? 'open') === 'open')

const daysLine = computed(() => {
  const month = data.value?.month
  if (!month) return ''
  if (!isOpen.value) return t('month.closing')
  return month.days_left <= 1 ? t('month.last_day') : t('month.days_left', { count: month.days_left })
})

const hasPrizes = computed(() => (data.value?.prizes.length ?? 0) > 0)
const optedOut = computed(() => !!data.value?.opted_out && (me.value === null || !me.value.participant))

const rowName = (name: string | null): string => name || t('league.anonymous')

/** The learner has a place, but the top does not show it: the row is pinned under the list. */
const pinnedMe = computed(() => {
  const mine = me.value
  const top = data.value?.top ?? []
  return mine !== null && mine.participant && mine.rank !== null && !top.some((row) => row.is_me) ? mine : null
})

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
    <PageHeader :title="t('league.rating_title')" large />
    <SegmentedNav :items="ratingTabs" :ariaLabel="t('league.rating_title')" class="mb-4" />

    <LoadingState v-if="!data && !message" />

    <ErrorState v-else-if="!data" :message="message" @retry="load" />

    <div v-else class="space-y-3">
      <InlineAlert v-if="message" tone="danger" :message="message" data-testid="reload-error" />

      <ListGroup v-if="!data.enabled" data-testid="disabled">
        <ListRow icon="lock-closed" tile="gray" :title="t('month.disabled')" />
      </ListGroup>

      <template v-else>
        <ListGroup data-testid="month">
          <ListRow icon="calendar-days" tile="blue" :title="monthTitle" :subtitle="daysLine" />
        </ListGroup>

        <section v-if="hasPrizes" class="card" data-testid="prizes">
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('month.prizes') }}</h2>
          <ul class="mt-2 space-y-1">
            <li v-for="prize in data.prizes" :key="prize.place" class="text-base" data-testid="prize">
              {{ medal(prize.place) }} {{ t('month.prize_place', { place: prize.place, title: prize.title }) }}
            </li>
          </ul>
          <p class="mt-2 text-[13px] leading-[18px] text-tg-hint" data-testid="prizes-note">{{ t('month.prizes_note') }}</p>
        </section>

        <section v-if="optedOut" class="card" data-testid="opted-out">
          <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('month.opted_out') }}</p>
          <RouterLink :to="{ name: 'settings' }" class="btn btn-tinted btn-block mt-3" data-testid="settings-link">
            {{ t('league.settings.open') }}
          </RouterLink>
        </section>

        <section v-else-if="me === null" class="card" data-testid="not-started">
          <p class="text-[17px] leading-[22px] font-semibold">{{ t('month.start_prompt') }}</p>
          <RouterLink :to="{ name: 'today' }" class="btn btn-primary btn-block mt-3" data-testid="start-btn">
            {{ t('today.review.start') }}
          </RouterLink>
        </section>

        <section v-else class="card" data-testid="my-card">
          <p class="text-[20px] leading-7 font-bold tracking-tight" data-testid="my-place">
            {{
              me.rank !== null
                ? t('month.my_place', { rank: me.rank, points: tn('month.points', me.points) })
                : t('month.my_noplace', { points: tn('month.points', me.points) })
            }}
          </p>
          <p v-for="line in prizeLines" :key="line" class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="prize-line" :data-eligible="me.eligible">
            {{ line }}
          </p>
        </section>

        <ListGroup v-if="data.top.length > 0" :header="t('month.top')" data-testid="top">
          <div
            v-for="row in data.top"
            :key="row.rank"
            class="list-row"
            :class="{ 'bg-accent-soft': row.is_me }"
            :data-testid="row.is_me ? 'row-me' : 'row'"
          >
            <span class="w-7 shrink-0 text-center text-[15px] font-semibold tabular-nums" data-testid="rank">
              {{ medal(row.rank) || row.rank }}
            </span>
            <InitialAvatar :name="rowName(row.name)" size="sm" :accent="row.is_me" />
            <span class="min-w-0 flex-1 truncate text-base" :class="{ 'font-semibold': row.is_me }">{{ rowName(row.name) }}</span>
            <span v-if="row.is_me" class="chip chip-accent" data-testid="me-chip">{{ t('league.you') }}</span>
            <span class="shrink-0 text-[15px] text-tg-subtitle tabular-nums">{{ tn('month.points', row.points) }}</span>
          </div>
          <div v-if="pinnedMe" class="list-row bg-accent-soft" data-testid="row-pinned">
            <span class="w-7 shrink-0 text-center text-[15px] font-semibold tabular-nums" data-testid="rank">{{ pinnedMe.rank }}</span>
            <InitialAvatar :name="t('league.you')" size="sm" accent />
            <span class="min-w-0 flex-1 truncate text-base font-semibold">{{ t('league.you') }}</span>
            <span class="shrink-0 text-[15px] text-tg-subtitle tabular-nums">{{ tn('month.points', pinnedMe.points) }}</span>
          </div>
        </ListGroup>
        <ListGroup v-else data-testid="empty">
          <ListRow icon="trophy" tile="gray" :title="t('month.empty')" />
        </ListGroup>

        <ListGroup v-if="lastMonth && lastMonth.status === 'confirmed'" :header="t('month.last_winners', { month: lastTitle })" data-testid="last-month">
          <div v-for="winner in lastMonth.winners" :key="winner.place" class="list-row" data-testid="winner">
            <span class="w-7 shrink-0 text-center text-[15px] font-semibold tabular-nums">{{ medal(winner.place) || winner.place }}</span>
            <InitialAvatar :name="rowName(winner.name)" size="sm" />
            <span class="min-w-0 flex-1 truncate text-base">{{ rowName(winner.name) }}</span>
            <span class="shrink-0 text-[15px] text-tg-subtitle tabular-nums">{{ tn('month.points', winner.points) }}</span>
          </div>
          <ListRow v-if="lastMonth.me" icon="trophy" tile="blue" :title="t('month.last_me', { rank: lastMonth.me.rank })" data-testid="last-me" />
        </ListGroup>
        <ListGroup v-else-if="lastMonth" data-testid="last-month-pending">
          <ListRow icon="clock" tile="gray" :title="t('month.last_pending', { month: lastTitle })" />
        </ListGroup>

        <details class="list group" data-testid="rules">
          <summary class="list-row list-row-icon cursor-pointer list-none select-none active:bg-fill [&::-webkit-details-marker]:hidden">
            <span class="icon-tile tile-gray"><AppIcon name="information-circle" :size="18" /></span>
            <span class="min-w-0 flex-1 text-base">{{ t('month.rules_title') }}</span>
            <AppIcon name="chevron-down" :size="16" class="text-tg-hint transition-transform duration-150 group-open:rotate-180" />
          </summary>
          <div class="space-y-2 px-4 pt-1 pb-4 text-[15px] leading-5 text-tg-subtitle">
            <p data-testid="rules-points">{{ t('month.rules_points', { cap: data.rules.daily_cap }) }}</p>
            <p v-if="hasPrizes" data-testid="rules-prize">
              {{ t('month.rules_prize', { places: data.rules.places, days: data.rules.min_active_days, account: data.rules.min_account_days }) }}
            </p>
          </div>
        </details>
      </template>
    </div>
  </div>
</template>
