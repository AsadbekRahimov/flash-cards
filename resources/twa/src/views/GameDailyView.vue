<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { showErrorToast } from '@/composables/errorToast'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { activeRunFrom, useGameStore } from '@/stores/game'
import { useTodayStore } from '@/stores/today'
import { useI18n } from '@/i18n'
import type { GameActiveRun } from '@/api/types'

/**
 * The caravan of the day (docs/05 §5b.4, docs/18 §12.9): a warm-up of 8-10 tasks on the words that are due, one per local day.
 *
 * It reads the map of the course (`daily`, `active_run`: the one definition of the day that the map and `/today` share) - never `/me`;
 * `?course=` is the course, else the active one of `/today`. States: loading, no course, unavailable, error, then
 * - `running`: a run is on (any kind: a second one cannot start) - "Continue";
 * - `done`: the caravan of the day has been played - the next one is tomorrow, the usual review is in the cards;
 * - `ready`: something is due - "Go";
 * - `empty`: nothing is due.
 *
 * Starting (`POST /game/runs {kind: daily}`): 201 leads to the run; 409 `game_daily_done` is `done` (whoever spent the day, a lost
 * race of two tabs too - no toast); 409 `game_run_active` is `running`; 422 `game_not_enough_material` is `empty`; 403
 * `game_unavailable` is `unavailable`. The caravan has no limits for Free, so there is no paywall here.
 */
const props = defineProps<{
  /** `?course=`; null: the active course. */
  course: string | null
}>()

const router = useRouter()
const auth = useAuthStore()
const game = useGameStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, tn } = useI18n()

const { map, status, error, starting } = storeToRefs(game)

// From the map (`?course=`) back to the map; from "Today" (no course in the address) back to "Today".
useBackButton(props.course !== null ? { name: 'game', query: { course: props.course } } : { name: 'today' })

let left = false

const slug = computed((): string | null => props.course ?? today.data?.active_course?.slug ?? null)
const todayFailed = ref(false)

/** What a refused start told: it wins over the map until the map is read again. */
type Told = { kind: 'done'; resetsAt: string | null } | { kind: 'running'; run: GameActiveRun } | { kind: 'empty' } | { kind: 'unavailable' }
const told = ref<Told | null>(null)

const daily = computed(() => map.value?.daily ?? null)
const activeRun = computed((): GameActiveRun | null => (told.value?.kind === 'running' ? told.value.run : (map.value?.active_run ?? null)))

const view = computed((): 'loading' | 'no_course' | 'unavailable' | 'error' | 'running' | 'done' | 'ready' | 'empty' => {
  if (status.value === 'unavailable' || told.value?.kind === 'unavailable') return 'unavailable'
  if (todayFailed.value || status.value === 'error') return 'error'
  if (status.value === 'ready' && map.value !== null) {
    if (activeRun.value !== null) return 'running'
    if (told.value?.kind === 'done' || daily.value?.done_today) return 'done'
    if (told.value?.kind === 'empty') return 'empty'
    return daily.value?.available ? 'ready' : 'empty'
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
  told.value = null
  await game.loadMap(course)
}

function openRun(id: number): Promise<unknown> {
  return router.push(`/game/runs/${id}`)
}

async function start(): Promise<void> {
  if (starting.value) return
  haptic('light')
  const result = await game.startDaily()
  if (left) return
  if (result.ok) {
    await openRun(result.id)
    return
  }
  const failure = result.error
  switch (failure.code) {
    case 'busy':
      break
    case 'game_daily_done':
      told.value = { kind: 'done', resetsAt: typeof failure.details?.resets_at === 'string' ? failure.details.resets_at : null }
      break
    case 'game_run_active': {
      const run = activeRunFrom(failure, null, 'daily')
      if (run !== null) {
        told.value = { kind: 'running', run }
      } else {
        showErrorToast(failure.message)
        void load()
      }
      break
    }
    case 'game_not_enough_material':
      told.value = { kind: 'empty' }
      break
    case 'game_unavailable':
      told.value = { kind: 'unavailable' }
      break
    default:
      showErrorToast(failure.message)
  }
}

function toMap(): void {
  void router.push(slug.value ? { name: 'game', query: { course: slug.value } } : { name: 'game' })
}

function toCourses(): void {
  void router.push({ name: 'courses' })
}

const { reviewFromPaywall: review } = useReviewFromPaywall(() => undefined, { toastErrors: true })

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
  <div class="page" data-testid="game-daily-view" :data-state="view">
    <PageHeader :title="t('game.daily.title')" :subtitle="subtitle" />

    <LoadingState v-if="view === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="view === 'no_course'"
      icon="book-open"
      :title="t('game.map.no_course_title')"
      :text="t('game.map.no_course_text')"
      data-testid="no-course"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-courses" @click="toCourses">{{ t('game.map.to_courses') }}</button>
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

    <section v-else-if="view === 'running' && activeRun" class="card flex flex-col gap-3" data-testid="running">
      <div class="flex items-center gap-3">
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="play" solid :size="20" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('game.daily.running_title') }}</h2>
          <p class="text-[15px] leading-5 text-tg-subtitle" data-testid="running-progress">
            {{ t('game.map.active_progress', { answered: activeRun.progress.answered, total: activeRun.progress.total }) }}
          </p>
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="continue-btn" @click="openRun(activeRun.id)">
        {{ t('game.map.continue') }}
      </button>
    </section>

    <section v-else-if="view === 'done'" class="card flex flex-col gap-3" data-testid="done" :data-resets-at="(told?.kind === 'done' ? told.resetsAt : daily?.resets_at) ?? ''">
      <div class="flex items-center gap-3">
        <span class="icon-tile size-10 rounded-xl tile-teal"><AppIcon name="check-circle" solid :size="22" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('game.daily.done_title') }}</h2>
          <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('game.daily.done_text') }}</p>
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-lg btn-block" data-testid="review-btn" @click="review">{{ t('game.daily.review') }}</button>
      <button type="button" class="btn btn-plain btn-block" data-testid="to-map" @click="toMap">{{ t('game.daily.to_map') }}</button>
    </section>

    <section v-else-if="view === 'ready'" class="card flex flex-col gap-3" data-testid="ready">
      <div class="flex items-center gap-3">
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="flag" :size="22" /></span>
        <p class="min-w-0 flex-1 text-[15px] leading-5" data-testid="ready-text">{{ tn('game.daily.ready_text', daily?.due_count ?? 0) }}</p>
      </div>
      <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="starting" data-testid="start-btn" @click="start">
        {{ t('game.daily.go') }}
      </button>
    </section>

    <EmptyState v-else icon="check-circle" :title="t('game.daily.empty_title')" :text="t('game.daily.empty_text')" data-testid="empty">
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-map" @click="toMap">{{ t('game.daily.to_map') }}</button>
      </template>
    </EmptyState>
  </div>
</template>
