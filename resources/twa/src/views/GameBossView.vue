<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { showErrorToast } from '@/composables/errorToast'
import { shownLimit } from '@/composables/practiceLimits'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useGameStore, type GameStartError } from '@/stores/game'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { GameActiveRun, GameMapCity } from '@/api/types'

/**
 * The boss of a city (docs/05 §5b.7, docs/18 §12.9): the gate of the city is kept by "the Keeper of the Gate" - 12 words, 80 % of them
 * right. It reads the map of the course (`?course=`, else the active one of `/today`; never `/me`) and shows the boss of the city
 * `stageId` by `boss.state`:
 * - `ready` / `defeated`: the intro (the city, what is asked, the Free limit of the day) and "Challenge" (a beaten boss may be fought again);
 * - `not_ready`: how many stops / words are still missing; `locked_by_plan`: "in Pro", the paywall.
 * A city that the map does not have is "no such city".
 *
 * Starting (`POST /game/runs {kind: boss}`): 201 leads to the run (its address carries `course` and `boss`, for the card of the win);
 * 403 `game_pro_required` / `game_limit_reached` is the paywall; 409 `game_run_active` asks to continue or to replace; 409 `boss_not_ready`
 * reads the map again; 422 `game_not_enough_material` says to learn more; 404 `stage_not_found` is "no such city". The result of the
 * fight is the summary of the run (`RunSummary`), not a state of this screen.
 */
const props = defineProps<{
  stageId: number
  /** `?course=`; null: the active course. */
  course: string | null
}>()

const router = useRouter()
const auth = useAuthStore()
const game = useGameStore()
const today = useTodayStore()
const billing = useBillingStore()
const { haptic } = useTelegram()
const { t, tn } = useI18n()

const { map, status, error, starting } = storeToRefs(game)

let left = false

const slug = computed((): string | null => props.course ?? today.data?.active_course?.slug ?? null)
const todayFailed = ref(false)

// Back: to the map of the course (the way the learner came), or "Today" when the course is not known.
useBackButton(props.course !== null ? { name: 'game', query: { course: props.course } } : { name: 'today' })

const city = computed((): GameMapCity | null => map.value?.cities.find((candidate) => candidate.stage_id === props.stageId) ?? null)
/** Set by a refusal of the start that says there is no such city. */
const missing = ref(false)

const view = computed((): 'loading' | 'no_course' | 'unavailable' | 'error' | 'missing' | 'ready' | 'defeated' | 'not_ready' | 'locked' => {
  if (status.value === 'unavailable') return 'unavailable'
  if (todayFailed.value || status.value === 'error') return 'error'
  if (status.value === 'ready' && map.value !== null) {
    const found = city.value
    if (found === null || missing.value) return 'missing'
    switch (found.boss.state) {
      case 'locked_by_plan':
        return 'locked'
      case 'not_ready':
        return 'not_ready'
      case 'defeated':
        return 'defeated'
      default:
        return 'ready'
    }
  }
  if (status.value === 'idle' && slug.value === null && today.status !== 'loading' && today.status !== 'idle') return 'no_course'
  return 'loading'
})

const errorText = computed((): string => (todayFailed.value ? today.errorMessage : error.value))
const cityName = computed((): string => city.value?.city ?? '')

/** What is missing before the boss is ready: the stops, else the words (as on the chip of the map). */
const missingText = computed((): string => {
  const boss = city.value?.boss
  if (!boss) return ''
  const stops = Math.max(0, boss.required_stops - boss.cleared_stops)
  return stops > 0 ? tn('game.boss.not_ready_stops', stops) : tn('game.boss.not_ready_words', Math.max(1, boss.required - boss.introduced))
})

/** "Free today: 1 of 1 · tickets: 2": only where the server told the limits (nothing is made up). */
const limitLine = computed((): string => {
  const limits = billing.gameLimits
  if (limits === null) return ''
  const runs = shownLimit(limits.runs)
  if (runs === null) return ''
  const free = t('game.stop.free_today', { remaining: Math.max(0, runs.remaining), limit: runs.limit })
  return limits.tickets ? `${free} · ${t('game.stop.tickets', { count: limits.tickets.balance })}` : free
})

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch {
    todayFailed.value = true
    return
  }
  todayFailed.value = false
  if (props.course === null && today.data === null) await today.load()
  if (left) return
  const course = slug.value
  if (course === null) {
    if (today.status === 'error') todayFailed.value = true
    return
  }
  missing.value = false
  await game.loadMap(course)
}

// ---- the start ----

const paywall = ref(false)
const conflict = ref<GameActiveRun | null>(null)
const material = ref(false)

function openRun(id: number): Promise<unknown> {
  return router.push({ path: `/game/runs/${id}`, query: { ...(slug.value ? { course: slug.value } : {}), boss: String(props.stageId) } })
}

/** The run that is on, from the 409: its `details`. */
function activeFrom(failure: GameStartError): GameActiveRun | null {
  const details = failure.details
  const id = details?.run_id
  if (typeof id !== 'number') return map.value?.active_run ?? null
  const progress = details?.progress as GameActiveRun['progress'] | undefined
  return { id, kind: typeof details?.kind === 'string' ? details.kind : 'boss', progress: progress ?? { answered: 0, total: 0 } }
}

async function start(replaceActive = false): Promise<void> {
  const course = slug.value
  if (course === null || starting.value) return
  haptic('light')
  const result = await game.startBoss(course, props.stageId, replaceActive)
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
    case 'game_limit_reached':
    case 'game_pro_required':
      paywall.value = true
      break
    case 'game_run_active':
      conflict.value = activeFrom(failure)
      if (conflict.value === null) showErrorToast(failure.message)
      break
    case 'game_not_enough_material':
      material.value = true
      break
    case 'stage_not_found':
      missing.value = true
      break
    case 'boss_not_ready':
      // The map said "ready" a while ago: the numbers are read again and the screen turns to what is true.
      showErrorToast(failure.message)
      void load()
      break
    case 'game_unavailable':
      void load()
      break
    default:
      showErrorToast(failure.message)
  }
}

function continueRun(): void {
  const run = conflict.value
  conflict.value = null
  if (run !== null) void router.push(`/game/runs/${run.id}`)
}

function replaceRun(): void {
  conflict.value = null
  void start(true)
}

function toMap(): void {
  void router.push(slug.value ? { name: 'game', query: { course: slug.value } } : { name: 'game' })
}

async function learn(): Promise<void> {
  material.value = false
  await router.push(slug.value ? { name: 'course', params: { slug: slug.value } } : { name: 'courses' })
}

function openPaywall(): void {
  haptic('light')
  paywall.value = true
}

const { reviewFromPaywall } = useReviewFromPaywall(() => {
  paywall.value = false
}, { toastErrors: true })

watch(() => props.course, () => void load())

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
  game.reset()
})
</script>

<template>
  <div class="page" :data-state="view" data-testid="game-boss-view">
    <PageHeader :title="t('game.bossScreen.title')" :subtitle="cityName" />

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="view === 'no_course'"
      icon="book-open"
      :title="t('game.map.no_course_title')"
      :text="t('game.map.no_course_text')"
      data-testid="no-course"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-courses" @click="router.push({ name: 'courses' })">{{ t('game.map.to_courses') }}</button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="view === 'unavailable'"
      icon="lock-closed"
      :title="t('game.map.unavailable_title')"
      :text="t('game.map.unavailable_text')"
      data-testid="unavailable"
    />

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <EmptyState v-else-if="view === 'missing'" icon="flag" :text="t('game.bossScreen.missing')" data-testid="missing">
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-map" @click="toMap">{{ t('game.daily.to_map') }}</button>
      </template>
    </EmptyState>

    <!-- The boss is not ready: what is missing. -->
    <section v-else-if="view === 'not_ready'" class="card flex flex-col gap-3" data-testid="not-ready">
      <div class="flex items-center gap-3">
        <span class="icon-tile size-10 rounded-xl tile-gray"><AppIcon name="lock-closed" :size="20" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('game.bossScreen.keeper') }}</h2>
          <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="not-ready-text">{{ t('game.bossScreen.not_ready', { missing: missingText }) }}</p>
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="to-map" @click="toMap">{{ t('game.daily.to_map') }}</button>
    </section>

    <!-- The intro: ready, beaten, or Pro. -->
    <section v-else class="card flex flex-col gap-3" data-testid="intro">
      <div class="flex items-center gap-3">
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="flag" :size="22" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] leading-[22px] font-semibold" data-testid="keeper">{{ t('game.bossScreen.keeper') }}</h2>
          <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="city-name">{{ cityName }}</p>
        </div>
        <span v-if="view === 'locked'" class="chip chip-accent shrink-0" data-testid="pro-chip">
          <AppIcon name="sparkles" solid :size="12" />{{ t('game.boss.pro') }}
        </span>
      </div>

      <ul class="flex flex-col gap-1 text-[15px] leading-5" data-testid="rules">
        <li class="flex items-center gap-2"><AppIcon name="academic-cap" :size="18" class="shrink-0 text-tg-hint" />{{ tn('game.bossScreen.words', 12) }}</li>
        <li class="flex items-center gap-2"><AppIcon name="check-circle" :size="18" class="shrink-0 text-tg-hint" />{{ t('game.bossScreen.need', { percent: 80 }) }}</li>
      </ul>

      <p v-if="view === 'defeated'" class="flex items-center gap-2 text-[15px] leading-5 font-medium text-success-ink" data-testid="beaten">
        <AppIcon name="check-circle" solid :size="18" />{{ t('game.bossScreen.beaten') }}
      </p>
      <p v-if="view === 'locked'" class="text-[15px] leading-5 text-tg-subtitle" data-testid="locked-text">{{ t('game.bossScreen.locked') }}</p>
      <p v-if="limitLine && view !== 'locked'" class="text-[13px] leading-[18px] text-tg-hint" data-testid="limit">{{ limitLine }}</p>

      <button v-if="view === 'locked'" type="button" class="btn btn-primary btn-lg btn-block" data-testid="pro-btn" @click="openPaywall">{{ t('game.bossScreen.pro_btn') }}</button>
      <button v-else type="button" class="btn btn-primary btn-lg btn-block" :disabled="starting" data-testid="start-btn" @click="start(false)">
        {{ view === 'defeated' ? t('game.bossScreen.again') : t('game.bossScreen.challenge') }}
      </button>
    </section>

    <!-- Another run is on: continue it, or start this one (the old one is not counted). -->
    <BottomSheet v-if="conflict" :label="t('game.conflict.title')" data-testid="conflict-sheet" @close="conflict = null">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('game.conflict.title') }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('game.conflict.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" data-testid="conflict-continue" @click="continueRun">{{ t('game.conflict.continue') }}</button>
      <button type="button" class="btn btn-tinted btn-block" :disabled="starting" data-testid="conflict-replace" @click="replaceRun">{{ t('game.conflict.replace') }}</button>
    </BottomSheet>

    <BottomSheet v-if="material" :label="t('game.material.title')" data-testid="material-sheet" @close="material = false">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('game.material.title') }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('game.material.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" data-testid="material-learn" @click="learn">{{ t('game.map.learn') }}</button>
    </BottomSheet>

    <PaywallSheet v-if="paywall" reason="game" @close="paywall = false" @review="reviewFromPaywall" />
  </div>
</template>
