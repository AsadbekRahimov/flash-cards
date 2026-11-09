<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { showErrorToast } from '@/composables/errorToast'
import { formatDate, realName } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useGameClassStore } from '@/stores/gameClass'
import { activeRunFrom, useGameStore } from '@/stores/game'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { GameActiveRun } from '@/api/types'

/**
 * The caravan of a class (docs/05 §5b.7, docs/18 §12.9, §12.11): one `GET /game/class/{id}` per opening - the star track
 * `stars_total / target_stars`, the deadline in the learner's time zone, my contribution, the classmates by contribution and the boss of
 * the class. States: loading, `active`, `finished` (the summary of the week), `canceled` (the API does not send it today: a canceled
 * caravan is 404), `not_found` (404 `class_caravan_not_found`), `unavailable` (403 `game_unavailable`), `error` (Retry).
 *
 * The boss: `locked` until the caravan takes its target, `ready` ("Fight" - `POST /game/runs {kind: class_boss}`), `done` (my one fight is
 * over: `won` / `lost`). Refusals of the start: 409 `game_run_active` asks to continue or to replace (`replace_active`); 409
 * `class_boss_done` / `class_boss_locked` and 404 `class_caravan_not_found` say so and read the screen again; 403 `game_unavailable`
 * closes the screen; the rest is a toast.
 */
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
const classes = useGameClassStore()
const game = useGameStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, locale } = useI18n()

const { caravan, status, error } = storeToRefs(classes)
const { starting } = storeToRefs(game)

useBackButton({ name: 'today' })

let left = false

/** A start that was refused with 403 `game_unavailable`: the screen is closed until it is read again. */
const closed = ref(false)
const authFailed = ref(false)
const authError = ref('')
const conflict = ref<GameActiveRun | null>(null)

type ViewState = 'loading' | 'active' | 'finished' | 'canceled' | 'not_found' | 'unavailable' | 'error'

const view = computed((): ViewState => {
  if (closed.value || status.value === 'unavailable') return 'unavailable'
  if (authFailed.value || status.value === 'error') return 'error'
  if (status.value === 'not_found') return 'not_found'
  if (status.value === 'ready' && caravan.value !== null) {
    return caravan.value.status === 'finished' ? 'finished' : caravan.value.status === 'canceled' ? 'canceled' : 'active'
  }
  return 'loading'
})

const errorText = computed((): string => (authFailed.value ? authError.value : error.value))

/** The learner's zone when `/today` is known (it is not read here), else the device's. */
const zone = computed((): string | undefined => today.data?.timezone || undefined)

const until = computed((): string => {
  const at = caravan.value?.ends_at
  return at ? t('gameClass.until', { when: formatDate(at, 'weekdayTime', locale.value, zone.value) }) : ''
})

const percent = computed((): number => {
  const c = caravan.value
  return c === null || c.target_stars <= 0 ? 0 : Math.min(100, Math.round((c.stars_total / c.target_stars) * 100))
})

const reached = computed((): boolean => caravan.value !== null && caravan.value.stars_total >= caravan.value.target_stars)
const starsLeft = computed((): number => (caravan.value === null ? 0 : Math.max(0, caravan.value.target_stars - caravan.value.stars_total)))

const subtitle = computed((): string => (caravan.value ? t('gameClass.subtitle', { group: caravan.value.group_title, course: caravan.value.course.title }) : ''))

const finishedAt = computed((): string => {
  const at = caravan.value?.summary?.finished_at
  return at ? formatDate(at, 'dayMonthShortTime', locale.value, zone.value) : ''
})

/** The boss of the class as the learner meets it. */
const bossState = computed((): 'locked' | 'ready' | 'won' | 'lost' => {
  const boss = caravan.value?.boss
  if (boss?.my_attempt === 'won') return 'won'
  if (boss?.my_attempt === 'lost') return 'lost'
  return boss?.unlocked ? 'ready' : 'locked'
})

async function load(): Promise<void> {
  closed.value = false
  try {
    await auth.ensure()
  } catch {
    authFailed.value = true
    authError.value = t('error.generic')
    return
  }
  authFailed.value = false
  if (left) return
  await classes.load(props.id)
}

function openRun(id: number): Promise<unknown> {
  return router.push(`/game/runs/${id}`)
}

/** The run that is on, from the 409's `details`. */
async function fight(replaceActive: boolean): Promise<void> {
  if (starting.value) return
  haptic('light')
  const result = await classes.startBoss(props.id, replaceActive)
  if (left) return
  if (result.ok) {
    conflict.value = null
    await openRun(result.id)
    return
  }
  const failure = result.error
  switch (failure.code) {
    case 'busy':
      break
    case 'game_run_active':
      conflict.value = activeRunFrom(failure, null, 'stop')
      if (conflict.value === null) showErrorToast(failure.message)
      break
    case 'game_unavailable':
      conflict.value = null
      closed.value = true
      break
    case 'class_boss_done':
    case 'class_boss_locked':
    case 'class_caravan_not_found':
      conflict.value = null
      showErrorToast(failure.message)
      void load()
      break
    default:
      showErrorToast(failure.message)
  }
}

function continueRun(): void {
  const run = conflict.value
  conflict.value = null
  if (run !== null) void openRun(run.id)
}

function replaceRun(): void {
  conflict.value = null
  void fight(true)
}

function toMap(): void {
  void router.push({ name: 'game' })
}

watch(() => props.id, () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
  classes.reset()
  game.reset()
})
</script>

<template>
  <div class="page" data-testid="game-class-view" :data-state="view">
    <PageHeader :title="t('gameClass.title')" :subtitle="subtitle" />

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="view === 'unavailable'"
      icon="lock-closed"
      :title="t('game.map.unavailable_title')"
      :text="t('game.map.unavailable_text')"
      data-testid="unavailable"
    />

    <EmptyState
      v-else-if="view === 'not_found'"
      icon="flag"
      :title="t('gameClass.not_found.title')"
      :text="t('gameClass.not_found.text')"
      data-testid="not-found"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-map" @click="toMap">{{ t('gameClass.to_map') }}</button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="view === 'canceled'"
      icon="flag"
      :title="t('gameClass.canceled.title')"
      :text="t('gameClass.canceled.text')"
      data-testid="canceled"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-map" @click="toMap">{{ t('gameClass.to_map') }}</button>
      </template>
    </EmptyState>

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <template v-else-if="caravan !== null">
      <!-- The star track of the week. -->
      <section class="card flex flex-col gap-2" data-testid="progress-card">
        <template v-if="view === 'finished'">
          <h2 class="text-[17px] leading-[22px] font-semibold" data-testid="finished-title">{{ t('gameClass.finished.title') }}</h2>
          <p v-if="finishedAt" class="text-[13px] leading-[18px] text-tg-subtitle" data-testid="finished-at">
            {{ t('gameClass.finished.at', { date: finishedAt }) }}
          </p>
        </template>
        <div class="flex items-baseline justify-between gap-3">
          <p class="flex items-center gap-1.5 text-[22px] leading-7 font-bold" data-testid="stars-text">
            <AppIcon name="star" solid :size="20" class="shrink-0 text-warning" />
            <span>{{ caravan.stars_total }} / {{ caravan.target_stars }}</span>
          </p>
          <p v-if="view === 'active'" class="text-right text-[13px] leading-[18px] text-tg-subtitle" data-testid="until">{{ until }}</p>
        </div>
        <div
          class="progress"
          role="progressbar"
          :aria-label="t('gameClass.progress_aria', { stars: caravan.stars_total, target: caravan.target_stars })"
          aria-valuemin="0"
          :aria-valuemax="caravan.target_stars"
          :aria-valuenow="caravan.stars_total"
        >
          <div class="progress-fill" :class="{ 'progress-success': reached }" :style="{ width: `${percent}%` }" data-testid="progress-fill" />
        </div>
        <p v-if="view === 'active'" class="text-[15px] leading-5" :class="reached ? 'font-semibold text-success-ink' : 'text-tg-subtitle'" data-testid="goal-text">
          {{ reached ? t('gameClass.target_reached') : t('gameClass.stars_left', { count: starsLeft }) }}
        </p>
        <ul v-else-if="caravan.summary" class="flex flex-col gap-1" data-testid="summary">
          <li class="flex items-center gap-2 text-[15px] leading-5" :data-ok="caravan.summary.target_reached" data-testid="summary-target">
            <AppIcon :name="caravan.summary.target_reached ? 'check-circle' : 'x-circle'" :size="18" :class="caravan.summary.target_reached ? 'text-success' : 'text-tg-hint'" />
            {{ caravan.summary.target_reached ? t('gameClass.finished.target_reached') : t('gameClass.finished.target_missed') }}
          </li>
          <li class="flex items-center gap-2 text-[15px] leading-5" :data-ok="caravan.summary.boss_defeated" data-testid="summary-boss">
            <AppIcon :name="caravan.summary.boss_defeated ? 'check-circle' : 'x-circle'" :size="18" :class="caravan.summary.boss_defeated ? 'text-success' : 'text-tg-hint'" />
            {{ caravan.summary.boss_defeated ? t('gameClass.finished.boss_defeated') : t('gameClass.finished.boss_not_defeated') }}
          </li>
        </ul>
      </section>

      <!-- My contribution. -->
      <section class="card mt-4 flex flex-col gap-1" data-testid="my-card">
        <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('gameClass.my.title') }}</h2>
        <p v-if="caravan.my" class="text-[15px] leading-5 break-words" data-testid="my-stats">
          {{ t('gameClass.my.stats', { stars: caravan.my.stars, runs: caravan.my.runs, score: caravan.my.best_score }) }}
        </p>
        <p v-else class="text-[15px] leading-5 text-tg-subtitle" data-testid="my-none">{{ t('gameClass.my.none') }}</p>
      </section>

      <!-- The boss of the class. -->
      <section class="card mt-4 flex flex-col gap-3" :data-state="bossState" data-testid="boss-card">
        <div class="flex items-center gap-3">
          <span class="icon-tile size-10 rounded-xl" :class="bossState === 'locked' ? 'tile-gray' : 'tile-red'">
            <AppIcon :name="bossState === 'locked' ? 'lock-closed' : 'flag'" :size="20" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('gameClass.boss.title') }}</h2>
            <p v-if="caravan.boss.defeated" class="text-[13px] leading-[18px] font-semibold text-success-ink" data-testid="boss-defeated">
              {{ t('gameClass.boss.defeated') }}
            </p>
          </div>
        </div>
        <template v-if="view === 'active'">
          <p v-if="bossState === 'locked'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="boss-locked">{{ t('gameClass.boss.locked') }}</p>
          <template v-else-if="bossState === 'ready'">
            <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="boss-ready">{{ t('gameClass.boss.ready') }}</p>
            <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="starting" data-testid="boss-go" @click="fight(false)">
              {{ t('gameClass.boss.go') }}
            </button>
          </template>
          <p v-else class="text-[15px] leading-5" data-testid="boss-attempt">
            {{ bossState === 'won' ? t('gameClass.boss.won') : t('gameClass.boss.lost') }}
          </p>
        </template>
        <p v-else-if="bossState === 'won' || bossState === 'lost'" class="text-[15px] leading-5" data-testid="boss-attempt">
          {{ bossState === 'won' ? t('gameClass.boss.won') : t('gameClass.boss.lost') }}
        </p>
        <p v-else-if="!caravan.boss.defeated" class="text-[15px] leading-5 text-tg-subtitle" data-testid="boss-missed">{{ t('gameClass.boss.missed') }}</p>
      </section>

      <!-- The classmates by contribution (the order is the server's). -->
      <section class="mt-4" data-testid="members">
        <h2 class="section-label mt-0">{{ t('gameClass.members.title') }}</h2>
        <p v-if="caravan.members.length === 0" class="card text-[15px] leading-5 text-tg-subtitle" data-testid="members-empty">
          {{ t('gameClass.members.empty') }}
        </p>
        <ol v-else class="card flex flex-col divide-y divide-tg-separator p-0">
          <li
            v-for="(member, index) in caravan.members"
            :key="index"
            class="flex min-h-11 items-center gap-3 px-4 py-2"
            :class="member.is_me ? 'bg-accent-soft' : ''"
            :data-me="member.is_me"
            data-testid="member"
          >
            <span class="w-6 shrink-0 text-center text-[15px] font-semibold text-tg-hint">{{ index + 1 }}</span>
            <span class="min-w-0 flex-1">
              <span class="block text-[15px] leading-5 font-medium break-words">
                {{ realName(member.name) || t('league.anonymous') }}
                <span v-if="member.is_me" class="chip chip-accent ml-1" data-testid="member-me">{{ t('gameClass.members.me') }}</span>
              </span>
              <span class="block text-[13px] leading-[18px] text-tg-subtitle">
                {{ t('gameClass.members.stats', { stars: member.stars, runs: member.runs }) }}
                <template v-if="member.boss_won"> · {{ t('gameClass.members.boss_won') }}</template>
              </span>
            </span>
          </li>
        </ol>
      </section>
    </template>

    <!-- Another run is on: continue it, or start the boss (the old run is not counted). -->
    <BottomSheet v-if="conflict" :label="t('game.conflict.title')" data-testid="conflict-sheet" @close="conflict = null">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('game.conflict.title') }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('game.conflict.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" data-testid="conflict-continue" @click="continueRun">
        {{ t('game.conflict.continue') }}
      </button>
      <button type="button" class="btn btn-tinted btn-block" :disabled="starting" data-testid="conflict-replace" @click="replaceRun">
        {{ t('game.conflict.replace') }}
      </button>
    </BottomSheet>
  </div>
</template>
