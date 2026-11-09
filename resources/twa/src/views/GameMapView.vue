<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClassCaravanCard from '@/components/game/ClassCaravanCard.vue'
import GameItemImage from '@/components/game/GameItemImage.vue'
import PaywallSheet from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { showErrorToast } from '@/composables/errorToast'
import { useGameLimitLine } from '@/composables/gameLimitLine'
import { realName } from '@/composables/format'
import { BASE_CAMEL_ASSET } from '@/assets/game'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { activeRunFrom, useGameStore } from '@/stores/game'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { GameActiveRun, GameMapBoss, GameMapCity, GameMapStop } from '@/api/types'

/**
 * The map of the Karvon game (docs/05 §5b.2, docs/18 §12.9): a vertical road of cities (the modules of the course, each with its name on
 * the Silk Road), and on it the stops (the lessons) with 0-3 stars. A stop of a lesson that was not started in the cards is grey and
 * says "start the lesson"; a stop that is open leads to a sheet and from there to a run. The boss of a city is a chip that leads to its screen
 * (docs/05 §5b.8); the Pro chip opens the paywall at once.
 *
 * States: loading, no course, "not available yet" (`game_unavailable`), error (Retry), empty (nothing started: "Learn"), the map; over
 * the road the banner of the unfinished run. `?course=` is the course, else the active one of `/today` (this screen never reads `/me`:
 * the limits come with the map, the access with the entry that led here). `?stop=` opens the sheet of that stop once the map is there
 * (the "Again" button of the end of a run).
 *
 * Starting: 201 leads to the run; 403 `game_limit_reached` / `game_pro_required` is the paywall; 409 `game_run_active` asks to continue
 * or to replace (`replace_active`); 422 `game_not_enough_material` says to learn more; 409 `stop_locked` reads the map again.
 */
const props = defineProps<{
  /** `?course=`; null: the active course. */
  course: string | null
  /** `?stop=`: the lesson whose sheet opens at once; null: none. */
  stop: number | null
}>()

const router = useRouter()
const auth = useAuthStore()
const game = useGameStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, tn } = useI18n()

const { map, status, error, starting } = storeToRefs(game)

useBackButton({ name: 'today' })

/** Set when the learner leaves: an answer that comes back after it must not carry them anywhere. */
let left = false

/** The course the map is read for: the query, else the active course of `/today`. */
const slug = computed((): string | null => props.course ?? today.data?.active_course?.slug ?? null)
/** `/today` could not tell the active course (failed): the error of it is shown instead of "choose a course". */
const todayFailed = ref(false)

const view = computed((): 'loading' | 'no_course' | 'unavailable' | 'error' | 'empty' | 'ready' => {
  if (status.value === 'unavailable') return 'unavailable'
  if (todayFailed.value || status.value === 'error') return 'error'
  if (status.value === 'ready' && map.value !== null) {
    return map.value.cities.some((city) => city.stops.some((stop) => stop.unlocked)) ? 'ready' : 'empty'
  }
  if (status.value === 'idle' && slug.value === null && today.status !== 'loading' && today.status !== 'idle') return 'no_course'
  return 'loading'
})

const errorText = computed((): string => (todayFailed.value ? today.errorMessage : error.value))

const subtitle = computed((): string => map.value?.course.title ?? '')

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
  stopHandled = false
  await game.loadMap(course)
  if (!left) openFromQuery()
}

// ---- the road ----

function cityTitle(city: GameMapCity): string {
  return city.title || t('game.map.module', { number: city.number })
}

function stopTitle(stop: GameMapStop): string {
  return stop.title ? `${t('game.map.stop', { number: stop.number })} · ${stop.title}` : t('game.map.stop', { number: stop.number })
}

const cityOpen = (city: GameMapCity): boolean => city.stops.some((stop) => stop.unlocked)

const friendsIn = (city: GameMapCity) => (map.value?.friends ?? []).filter((friend) => friend.city_number === city.number)

/** The camels of the first three friends in the city (the same three whose names are written): the one they wear, the base one otherwise. */
const camelsAt = (city: GameMapCity): { id: number; asset: string }[] =>
  friendsIn(city).slice(0, 3).map((friend) => ({ id: friend.friendship_id, asset: friend.camel_asset ?? BASE_CAMEL_ASSET }))

/** Friends who are in the city: up to three names, the rest as "+N" (the server sends at most 20 friends of the course). */
function friendsAt(city: GameMapCity): string {
  const here = friendsIn(city)
  if (here.length === 0) return ''
  const names = here.slice(0, 3).map((friend) => realName(friend.name) || t('friends.list.anonymous')).join(', ')
  return t('game.map.friends', { names: here.length > 3 ? `${names} ${t('game.map.friends_more', { count: here.length - 3 })}` : names })
}

/** The words of the boss chip; `pro` makes it a button (the paywall). */
function bossChip(boss: GameMapBoss): { text: string; tone: string; pro: boolean } {
  switch (boss.state) {
    case 'ready':
      return { text: t('game.boss.ready'), tone: 'chip-success', pro: false }
    case 'defeated':
      return { text: t('game.boss.defeated'), tone: 'chip-neutral', pro: false }
    case 'locked_by_plan':
      return { text: t('game.boss.pro'), tone: 'chip-accent', pro: true }
    default: {
      const stops = Math.max(0, boss.required_stops - boss.cleared_stops)
      // Enough stops cleared but too few words met: the words are what is left.
      const text = stops > 0 ? tn('game.boss.not_ready_stops', stops) : tn('game.boss.not_ready_words', Math.max(1, boss.required - boss.introduced))
      return { text, tone: 'chip-neutral', pro: false }
    }
  }
}

function starsLabel(stop: GameMapStop): string {
  return t('game.map.stars_aria', { stars: stop.stars })
}

// ---- the caravan of the day ----

/**
 * What the card says: the caravan that is on (the banner above is for any other run), one that was played today, one that is waiting
 * (words are due; a stop run that is on does not change that - the caravan screen says "a run is on"), or nothing due.
 */
const dailyState = computed((): 'running' | 'ready' | 'done' | 'empty' => {
  const daily = map.value?.daily
  if (map.value?.active_run?.kind === 'daily') return 'running'
  if (daily?.done_today) return 'done'
  return daily?.available || (daily?.due_count ?? 0) > 0 ? 'ready' : 'empty'
})

const dailyText = computed((): string => {
  switch (dailyState.value) {
    case 'running':
      return t('game.daily.card.running')
    case 'ready':
      return tn('game.daily.card.ready', map.value?.daily?.due_count ?? 0)
    case 'done':
      return t('game.daily.card.done')
    default:
      return t('game.daily.card.empty')
  }
})

function openDaily(): void {
  haptic('light')
  void router.push({ name: 'game-daily', query: map.value ? { course: map.value.course.slug } : {} })
}

// ---- the sheet of a stop ----

const sheetLesson = ref<number | null>(null)
const sheetStop = computed((): { city: GameMapCity; stop: GameMapStop } | null => {
  if (sheetLesson.value === null) return null
  for (const city of map.value?.cities ?? []) {
    const stop = city.stops.find((candidate) => candidate.lesson_id === sheetLesson.value)
    if (stop) return { city, stop }
  }
  return null
})

const limitLine = useGameLimitLine()

function openStop(stop: GameMapStop): void {
  if (!stop.unlocked) return
  haptic('light')
  sheetLesson.value = stop.lesson_id
}

function closeSheet(): void {
  sheetLesson.value = null
  if (props.stop !== null) void router.replace({ name: 'game', query: props.course !== null ? { course: props.course } : {} })
}

/** `?stop=`: the sheet of that stop, once per reading of the map; a stop that is not there or not open is ignored. */
let stopHandled = false
function openFromQuery(): void {
  if (stopHandled || props.stop === null || map.value === null) return
  stopHandled = true
  for (const city of map.value.cities) {
    const stop = city.stops.find((candidate) => candidate.lesson_id === props.stop && candidate.unlocked)
    if (stop) sheetLesson.value = stop.lesson_id
  }
}

// ---- the start ----

const paywall = ref(false)
const conflict = ref<GameActiveRun | null>(null)
const material = ref(false)

function openRun(id: number): Promise<unknown> {
  return router.push(`/game/runs/${id}`)
}

/** The run that is on, from the 409: its `details`, else the banner's. */
async function start(replaceActive: boolean): Promise<void> {
  const lesson = sheetLesson.value
  if (lesson === null || starting.value) return
  haptic('light')
  const result = await game.startStop(lesson, replaceActive)
  if (left) return
  if (result.ok) {
    sheetLesson.value = null
    conflict.value = null
    await openRun(result.id)
    return
  }
  const failure = result.error
  switch (failure.code) {
    case 'game_limit_reached':
    case 'game_pro_required':
      sheetLesson.value = null
      paywall.value = true
      break
    case 'game_run_active':
      conflict.value = activeRunFrom(failure, map.value?.active_run ?? null, 'stop')
      if (conflict.value === null) showErrorToast(failure.message)
      break
    case 'game_not_enough_material':
      material.value = true
      break
    case 'stop_locked':
    case 'game_unavailable':
      sheetLesson.value = null
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
  sheetLesson.value = null
  if (run !== null) void openRun(run.id)
}

function replaceRun(): void {
  conflict.value = null
  void start(true)
}

function closeMaterial(): void {
  material.value = false
}

async function learn(): Promise<void> {
  material.value = false
  sheetLesson.value = null
  await router.push(slug.value ? { name: 'course', params: { slug: slug.value } } : { name: 'courses' })
}

/** The chip of a boss that is not behind the paywall: its screen (the intro, what is missing, the fight). */
function openBoss(city: GameMapCity): void {
  haptic('light')
  void router.push({ name: 'game-boss', params: { stageId: city.stage_id }, query: slug.value ? { course: slug.value } : {} })
}

const bossPaywall = (): void => {
  haptic('light')
  paywall.value = true
}

const { reviewFromPaywall } = useReviewFromPaywall(() => {
  paywall.value = false
}, { toastErrors: true })

watch(() => props.course, () => void load())
// A new `?stop=` on the screen that is already open (the map stays): its sheet opens, nothing is read again.
watch(
  () => props.stop,
  (stop) => {
    if (stop === null) return
    stopHandled = false
    openFromQuery()
  },
)

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  left = true
  game.reset()
})
</script>

<template>
  <div class="page" data-testid="game-map-view">
    <PageHeader :title="t('game.title')" :subtitle="subtitle">
      <template v-if="map !== null && (view === 'ready' || view === 'empty')" #trailing>
        <button type="button" class="btn btn-tinted btn-sm min-h-11" data-testid="caravan-btn" @click="router.push({ name: 'game-caravan', query: slug ? { course: slug } : {} })">
          {{ t('gameCaravan.open') }}
        </button>
      </template>
    </PageHeader>

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="view === 'no_course'"
      icon="book-open"
      :title="t('game.map.no_course_title')"
      :text="t('game.map.no_course_text')"
      data-testid="no-course"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-courses" @click="router.push({ name: 'courses' })">
          {{ t('game.map.to_courses') }}
        </button>
      </template>
    </EmptyState>

    <EmptyState
      v-else-if="view === 'unavailable'"
      icon="lock-closed"
      :title="t('game.map.unavailable_title')"
      :text="t('game.map.unavailable_text')"
      data-testid="unavailable"
    >
      <template #action>
        <button
          type="button"
          class="btn btn-tinted"
          data-testid="to-course"
          @click="router.push(slug ? { name: 'course', params: { slug } } : { name: 'courses' })"
        >
          {{ t('game.map.to_course') }}
        </button>
      </template>
    </EmptyState>

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <template v-else-if="map !== null">
      <!-- The unfinished run: one tap back into it. -->
      <button
        v-if="map.active_run"
        type="button"
        class="card mb-4 flex min-h-11 w-full items-center gap-3 text-left transition-transform active:scale-[0.99]"
        data-testid="active-run"
        @click="openRun(map.active_run.id)"
      >
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="play" solid :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block text-[13px] leading-[18px] font-semibold tracking-wide text-accent-ink uppercase">{{ t('game.map.active_run') }}</span>
          <span class="block text-[15px] leading-5 text-tg-subtitle" data-testid="active-run-progress">
            {{ t('game.map.active_progress', { answered: map.active_run.progress.answered, total: map.active_run.progress.total }) }}
          </span>
        </span>
        <span class="text-[15px] font-semibold text-accent-ink">{{ t('game.map.continue') }}</span>
      </button>

      <!-- The caravan of the class that is on (docs/05 §5b.7): from the answer of the map, nothing is read for it. -->
      <ClassCaravanCard v-if="map.class_caravan" :caravan="map.class_caravan" :timezone="today.data?.timezone" class="mb-4" />

      <!-- The caravan of the day (docs/05 §5b.4): what is due today, in one tap. -->
      <button
        v-if="map.daily"
        type="button"
        class="card mb-4 flex min-h-11 w-full items-center gap-3 text-left transition-transform active:scale-[0.99]"
        :data-state="dailyState"
        data-testid="daily-card"
        @click="openDaily"
      >
        <span class="icon-tile size-10 rounded-xl" :class="dailyState === 'ready' ? 'tile-orange' : 'tile-gray'"><AppIcon name="sparkles" :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block text-[15px] leading-5 font-semibold">{{ t('game.daily.title') }}</span>
          <span class="block text-[13px] leading-[18px] text-tg-subtitle" data-testid="daily-text">{{ dailyText }}</span>
        </span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
      </button>

      <EmptyState v-if="view === 'empty'" icon="flag" :text="t('game.map.empty_text')" data-testid="empty">
        <template #action>
          <button type="button" class="btn btn-primary" data-testid="learn-btn" @click="learn">{{ t('game.map.learn') }}</button>
        </template>
      </EmptyState>

      <ol v-else class="flex flex-col gap-3" :aria-label="t('game.map.road')" data-testid="road">
        <li
          v-for="city in map.cities"
          :key="city.stage_id"
          class="card"
          :class="{ 'opacity-60': !cityOpen(city) }"
          :data-testid="`city-${city.number}`"
        >
          <div class="flex items-start gap-3">
            <span class="icon-tile size-10 rounded-xl" :class="cityOpen(city) ? 'tile-teal' : 'tile-gray'">
              <AppIcon name="flag" :size="20" />
            </span>
            <div class="min-w-0 flex-1">
              <h2 class="text-[17px] leading-[22px] font-semibold" data-testid="city-name">{{ city.city }}</h2>
              <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="city-title">{{ cityTitle(city) }}</p>
              <p v-if="friendsAt(city)" class="mt-0.5 flex items-center gap-1 text-[13px] leading-[18px] text-tg-hint" data-testid="city-friends">
                <AppIcon name="users" :size="14" />
                <GameItemImage v-for="camel in camelsAt(city)" :key="camel.id" :name="camel.asset" :size="22" data-testid="friend-camel" />
                <span class="min-w-0 break-words">{{ friendsAt(city) }}</span>
              </p>
            </div>
            <template v-for="chip in [bossChip(city.boss)]" :key="city.boss.state">
              <button
                v-if="chip.pro"
                type="button"
                class="-my-2 flex min-h-11 shrink-0 items-center"
                data-testid="boss-pro"
                @click="bossPaywall"
              >
                <span class="chip" :class="chip.tone">
                  <AppIcon name="sparkles" solid :size="12" />
                  {{ chip.text }}
                </span>
              </button>
              <!-- The other chips lead to the screen of the boss (docs/05 §5b.8). -->
              <button v-else type="button" class="-my-2 flex min-h-11 shrink-0 items-center" :data-testid="`boss-open-${city.stage_id}`" @click="openBoss(city)">
                <span class="chip" :class="chip.tone" :data-testid="`boss-${city.boss.state}`">{{ chip.text }}</span>
              </button>
            </template>
          </div>

          <ul class="mt-2 flex flex-col">
            <li v-for="stop in city.stops" :key="stop.lesson_id">
              <button
                type="button"
                class="flex min-h-11 w-full items-center gap-3 rounded-lg py-1 text-left"
                :class="stop.unlocked ? 'active:bg-fill' : 'cursor-default text-tg-hint'"
                :aria-disabled="!stop.unlocked"
                :data-testid="`stop-${stop.lesson_id}`"
                @click="openStop(stop)"
              >
                <span
                  class="flex size-9 shrink-0 items-center justify-center rounded-full text-[15px] font-semibold"
                  :class="stop.unlocked ? 'bg-accent-soft text-accent-ink' : 'bg-track text-tg-hint'"
                >
                  <AppIcon v-if="!stop.unlocked" name="lock-closed" :size="16" />
                  <template v-else>{{ stop.number }}</template>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[15px] leading-5 break-words">{{ stopTitle(stop) }}</span>
                  <span v-if="!stop.unlocked" class="block text-[13px] leading-[18px]" data-testid="stop-locked">{{ t('game.map.stop_locked') }}</span>
                </span>
                <span v-if="stop.unlocked" class="flex shrink-0 gap-0.5" role="img" :aria-label="starsLabel(stop)" data-testid="stars">
                  <AppIcon
                    v-for="n in 3"
                    :key="n"
                    name="star"
                    :solid="n <= stop.stars"
                    :size="12"
                    :class="n <= stop.stars ? 'text-warning' : 'text-tg-hint'"
                  />
                </span>
              </button>
            </li>
          </ul>
        </li>
      </ol>
    </template>

    <!-- The sheet of a stop. -->
    <BottomSheet v-if="sheetStop && !conflict && !material" :label="stopTitle(sheetStop.stop)" data-testid="stop-sheet" @close="closeSheet">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ stopTitle(sheetStop.stop) }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="stop-text">{{ t('game.stop.text') }}</p>
      <p v-if="sheetStop.stop.runs > 0" class="flex items-center gap-2 text-[15px] leading-5" data-testid="stop-best">
        <span class="flex gap-0.5" aria-hidden="true">
          <AppIcon v-for="n in 3" :key="n" name="star" :solid="n <= sheetStop.stop.stars" :size="12" :class="n <= sheetStop.stop.stars ? 'text-warning' : 'text-tg-hint'" />
        </span>
        {{ t('game.stop.best', { score: sheetStop.stop.best_score }) }}
      </p>
      <p v-if="limitLine" class="text-[13px] leading-[18px] text-tg-hint" data-testid="stop-limit">{{ limitLine }}</p>
      <button type="button" class="btn btn-primary btn-block" :disabled="starting" data-testid="stop-go" @click="start(false)">
        {{ t('game.stop.go') }}
      </button>
    </BottomSheet>

    <!-- Another run is on: continue it, or start this one (the old one is not counted). -->
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

    <BottomSheet v-if="material" :label="t('game.material.title')" data-testid="material-sheet" @close="closeMaterial">
      <h2 class="pr-10 text-[22px] leading-7 font-bold">{{ t('game.material.title') }}</h2>
      <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('game.material.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" data-testid="material-learn" @click="learn">{{ t('game.map.learn') }}</button>
    </BottomSheet>

    <PaywallSheet v-if="paywall" reason="game" @close="paywall = false" @review="reviewFromPaywall" />
  </div>
</template>
