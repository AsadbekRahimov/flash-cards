<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useLeagueStore } from '@/stores/league'
import { useTelegram } from '@/composables/useTelegram'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { LeagueResult, LeagueZone } from '@/api/types'

/**
 * Leagues (docs/13 §7.3, docs/04 A.13): my group this week — place, points, what it takes to move
 * up or stay safe, the standings (first names and points only) — and last week's result.
 */
const router = useRouter()
const auth = useAuthStore()
const league = useLeagueStore()
const { showBackButton } = useTelegram()
const { t, tn, locale } = useI18n()

let hideBack: (() => void) | null = null

const data = computed(() => league.data)
const message = computed(() => league.errorMessage)
const membership = computed(() => data.value?.membership ?? null)

const TIER_LABELS: Record<string, StringKey> = {
  bronze: 'league.tier.bronze',
  silver: 'league.tier.silver',
  gold: 'league.tier.gold',
  platinum: 'league.tier.platinum',
  diamond: 'league.tier.diamond',
}

const ZONE_LABELS: Record<LeagueZone, StringKey> = {
  promotion: 'league.zone.promotion',
  safe: 'league.zone.safe',
  demotion: 'league.zone.demotion',
}

const LAST_LABELS: Record<LeagueResult, StringKey> = {
  promoted: 'league.last.promoted',
  stayed: 'league.last.stayed',
  demoted: 'league.last.demoted',
}

const tierName = (key: string): string => t(TIER_LABELS[key] ?? 'league.tier.bronze')

const isTopTier = computed(() => {
  const m = membership.value
  const tiers = data.value?.rules.tiers ?? []
  return m !== null && m.tier >= tiers.length - 1
})

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }).format(new Date(`${iso}T00:00:00`))
}

/** "00:00 (Asia/Tashkent)" is not useful on the phone: show the local time of the close instead. */
const closesLocal = computed(() => {
  const iso = data.value?.week.closes_at
  if (!iso) return ''
  return new Intl.DateTimeFormat(locale.value, { weekday: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
})

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    league.status = 'error'
    league.errorMessage = errorMessage(err, 'settings')
    return
  }
  await league.load()
}

onMounted(() => {
  hideBack = showBackButton(() => void router.push({ name: 'today' }))
  void load()
})

onBeforeUnmount(() => {
  hideBack?.()
})
</script>

<template>
  <section class="flex-1 flex flex-col p-4 max-w-xl mx-auto w-full">
    <h1 class="text-xl font-bold mb-4">🏆 {{ t('league.title') }}</h1>

    <div v-if="!data && !message" class="flex-1 flex items-center justify-center" data-testid="spinner">
      <p class="text-tg-hint">{{ t('common.loading') }}</p>
    </div>

    <div v-else-if="!data" class="flex-1 flex items-center justify-center text-center" data-testid="error">
      <div class="space-y-4">
        <p class="text-lg font-semibold">⚠️ {{ t('common.error_title') }}</p>
        <p class="text-tg-hint">{{ message }}</p>
        <button class="btn btn-primary" data-testid="retry-btn" @click="load()">{{ t('common.retry') }}</button>
      </div>
    </div>

    <div v-else class="space-y-3">
      <p v-if="message" class="text-sm text-red-600" role="alert" data-testid="reload-error">{{ message }}</p>

      <div class="card text-sm space-y-1" data-testid="week">
        <div class="font-semibold">{{ t('league.week', { from: formatDate(data.week.starts_on), to: formatDate(data.week.ends_on) }) }}</div>
        <div class="text-tg-hint">{{ t('league.closes', { zone: closesLocal }) }}</div>
      </div>

      <p v-if="!data.enabled" class="card text-sm text-tg-hint" data-testid="disabled">{{ t('league.disabled') }}</p>

      <template v-else-if="membership">
        <div class="card space-y-2" data-testid="my-card">
          <div class="text-xs text-tg-hint">{{ tierName(membership.tier_key) }}</div>
          <div class="text-2xl font-bold" data-testid="my-place">{{ t('league.my_place', { rank: membership.rank, members: membership.members_count }) }}</div>
          <div class="text-sm" data-testid="my-points">{{ tn('league.points', membership.points) }}</div>
          <div class="text-sm font-semibold" data-testid="zone">{{ t(ZONE_LABELS[membership.zone]) }}</div>
          <div v-if="membership.points_to_promote !== null" class="text-sm text-tg-hint" data-testid="to-promote">
            {{ t('league.to_promote', { points: tn('league.points', membership.points_to_promote) }) }}
          </div>
          <div v-else-if="isTopTier" class="text-sm text-tg-hint" data-testid="top-tier">{{ t('league.top_tier') }}</div>
          <div v-if="membership.points_to_safety !== null" class="text-sm text-red-600" data-testid="to-safety">
            {{ t('league.to_safety', { points: tn('league.points', membership.points_to_safety) }) }}
          </div>
        </div>

        <div class="card space-y-2">
          <div class="font-semibold">{{ t('league.standings') }}</div>
          <ol class="divide-y" data-testid="standings">
            <li
              v-for="row in membership.standings"
              :key="row.rank"
              class="flex items-center justify-between py-2 text-sm"
              :class="row.is_me ? 'font-semibold' : ''"
              :data-testid="row.is_me ? 'row-me' : 'row'"
            >
              <span>
                <span class="inline-block w-7 text-tg-hint">{{ row.rank }}</span>
                {{ row.name || t('league.anonymous') }}<span v-if="row.is_me" class="text-tg-hint"> · {{ t('league.you') }}</span>
              </span>
              <span class="text-tg-hint">{{ tn('league.points', row.points) }}</span>
            </li>
          </ol>
        </div>
      </template>

      <p v-else-if="data.opted_out" class="card text-sm text-tg-hint" data-testid="opted-out">{{ t('league.opted_out') }}</p>
      <p v-else class="card text-sm" data-testid="not-placed">{{ t('league.not_placed') }}</p>

      <div v-if="data.last_week" class="card text-sm space-y-1" data-testid="last-week">
        <div class="text-xs text-tg-hint">{{ t('league.last_week') }}</div>
        <div>
          {{ t(LAST_LABELS[data.last_week.result], { tier: tierName(data.last_week.tier_key), rank: data.last_week.rank, points: tn('league.points', data.last_week.points) }) }}
        </div>
      </div>

      <p class="text-xs text-tg-hint" data-testid="rules">
        {{ t('league.rules', { capacity: data.rules.capacity, promote: data.rules.promote, demote: data.rules.demote }) }}
      </p>

      <RouterLink :to="{ name: 'settings' }" class="btn btn-secondary w-full" data-testid="settings-link">
        ⚙️ {{ t('league.settings.open') }}
      </RouterLink>
    </div>
  </section>
</template>
