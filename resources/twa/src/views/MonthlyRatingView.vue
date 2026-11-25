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
 * Drawn in the language of the League screen (docs/design/2026-11-25-redesign League): the brand row, the days left above the title, my
 * place in the big tinted card, the top as rows of the group table — the place in a soft badge, the first name, the points, my row
 * tinted — no medals and no avatars.
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

        <section v-if="hasPrizes" class="list" data-testid="prizes">
          <h2 class="px-3.5 pt-3.5 pb-1 text-[19px] leading-6 font-extrabold">{{ t('month.prizes') }}</h2>
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
          <p v-if="blocked" class="text-[13px] leading-[18px] text-muted" data-testid="not-counted">
            {{ t('month.not_counted_blocked') }}
          </p>
          <p v-for="line in prizeLines" :key="line" class="text-[13px] leading-[18px] text-muted" data-testid="prize-line" :data-eligible="me.eligible">
            {{ line }}
          </p>
        </section>

        <!-- The top as the group table of the League mock-up: rows with a soft badge of the place, my row tinted. -->
        <section v-if="data.top.length > 0" class="card px-2.5 pt-3.5 pb-2.5" :aria-label="t('month.top')" data-testid="top">
          <h2 class="px-2.5 pb-1.5 text-[19px] leading-6 font-extrabold">{{ t('month.top') }}</h2>
          <div
            v-for="row in data.top"
            :key="row.rank"
            class="flex items-center gap-3.5 px-2.5 py-[9px]"
            :class="row.is_me ? 'my-1 rounded-control bg-nav-active' : 'border-b border-divider'"
            :data-testid="row.is_me ? 'row-me' : 'row'"
          >
            <span
              class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg text-[15px] font-bold text-accent tabular-nums"
              :class="row.is_me ? 'bg-surface' : 'bg-accent-soft'"
              data-testid="rank"
            >{{ row.rank }}</span>
            <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">
              {{ row.is_me ? `${rowName(row.name)} · ${t('league.you')}` : rowName(row.name) }}
            </span>
            <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums" data-testid="points">{{ row.points }}</span>
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
          <div v-for="winner in lastMonth.winners" :key="winner.place" class="list-row" data-testid="winner">
            <span class="flex h-7 w-[34px] shrink-0 items-center justify-center rounded-lg bg-accent-soft text-[15px] font-bold text-accent tabular-nums">{{ winner.place }}</span>
            <span class="min-w-0 flex-1 truncate text-base leading-[22px] font-bold">{{ rowName(winner.name) }}</span>
            <span class="shrink-0 text-base leading-[22px] font-bold tabular-nums">{{ winner.points }}</span>
          </div>
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
