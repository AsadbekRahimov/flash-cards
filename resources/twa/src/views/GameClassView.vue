<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AppIcon from '@/components/ui/AppIcon.vue'
import BottomSheet from '@/components/ui/BottomSheet.vue'
import ClosedState from '@/components/ClosedState.vue'
import MascotArt from '@/components/ui/MascotArt.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import NumberText from '@/components/game/NumberText.vue'
import { NUMBER_MARK } from '@/components/game/numberMark'
import { showErrorToast } from '@/composables/errorToast'
import { formatDate } from '@/composables/format'
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
const route = useRoute()
const auth = useAuthStore()
const classes = useGameClassStore()
const game = useGameStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, locale } = useI18n()

const { caravan, status, error } = storeToRefs(classes)
const { starting } = storeToRefs(game)

// With `?course=` (an entry from the course) "Back" leads to that course, else to Home.
const backCourse = typeof route.query.course === 'string' && route.query.course !== '' ? route.query.course : null
useBackButton(backCourse !== null ? { name: 'course', params: { slug: backCourse } } : { name: 'today' })

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

/** The first letter of a name for the avatar of the table (a whole character). */
const initialOf = (name: string): string => (Array.from(name.trim())[0] ?? '').toUpperCase()

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

    <ClosedState
      v-else-if="view === 'unavailable'"
      mascot="thinking"
      :title="t('game.map.unavailable_title')"
      :text="t('game.map.unavailable_text')"
      data-testid="unavailable"
    />

    <ClosedState
      v-else-if="view === 'not_found'"
      mascot="thinking"
      :title="t('gameClass.not_found.title')"
      :text="t('gameClass.not_found.text')"
      data-testid="not-found"
    >
      <button type="button" class="btn btn-secondary btn-block" data-testid="to-map" @click="toMap">{{ t('gameClass.to_map') }}</button>
    </ClosedState>

    <ClosedState
      v-else-if="view === 'canceled'"
      mascot="thinking"
      :title="t('gameClass.canceled.title')"
      :text="t('gameClass.canceled.text')"
      data-testid="canceled"
    >
      <button type="button" class="btn btn-secondary btn-block" data-testid="to-map" @click="toMap">{{ t('gameClass.to_map') }}</button>
    </ClosedState>

    <ErrorState v-else-if="view === 'error'" :message="errorText" @retry="load" />

    <template v-else-if="caravan !== null">
      <!-- The star track of the week. -->
      <section class="flex flex-col gap-2 rounded-hero bg-highlight p-5" data-testid="progress-card">
        <template v-if="view === 'finished'">
          <!-- The week is over and the class took its target: the mascot holds the cup (kit: a result); a missed week is told quietly. -->
          <MascotArt v-if="caravan.summary?.target_reached" pose="trophy" :height="112" class="self-center short:hidden" />
          <h2 class="t-title-m" data-testid="finished-title">{{ t('gameClass.finished.title') }}</h2>
          <p v-if="finishedAt" class="t-label-s text-muted" data-testid="finished-at">
            {{ t('gameClass.finished.at', { date: finishedAt }) }}
          </p>
        </template>
        <div class="flex items-baseline justify-between gap-3">
          <p class="t-numeric-l flex items-center gap-1.5" data-testid="stars-text">
            <AppIcon name="star" solid :size="18" class="shrink-0 text-body" />
            <span>{{ caravan.stars_total }} / {{ caravan.target_stars }}</span>
          </p>
          <p v-if="view === 'active'" class="t-label-s text-right text-muted" data-testid="until">{{ until }}</p>
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
        <p v-if="view === 'active'" class="t-body-m" :class="reached ? 'text-success-ink' : 'text-muted'" data-testid="goal-text">
          <template v-if="reached">{{ t('gameClass.target_reached') }}</template>
          <NumberText v-else :text="t('gameClass.stars_left', { count: NUMBER_MARK })" :value="starsLeft" />
        </p>
        <ul v-else-if="caravan.summary" class="flex flex-col gap-1" data-testid="summary">
          <li class="t-body-m flex items-center gap-2" :data-ok="caravan.summary.target_reached" data-testid="summary-target">
            <AppIcon :name="caravan.summary.target_reached ? 'check-circle' : 'x-circle'" :size="18" :class="caravan.summary.target_reached ? 'text-success-ink' : 'text-muted'" />
            {{ caravan.summary.target_reached ? t('gameClass.finished.target_reached') : t('gameClass.finished.target_missed') }}
          </li>
          <li class="t-body-m flex items-center gap-2" :data-ok="caravan.summary.boss_defeated" data-testid="summary-boss">
            <AppIcon :name="caravan.summary.boss_defeated ? 'check-circle' : 'x-circle'" :size="18" :class="caravan.summary.boss_defeated ? 'text-success-ink' : 'text-muted'" />
            {{ caravan.summary.boss_defeated ? t('gameClass.finished.boss_defeated') : t('gameClass.finished.boss_not_defeated') }}
          </li>
        </ul>
      </section>

      <!-- My contribution. -->
      <section class="card mt-4 flex flex-col gap-1" data-testid="my-card">
        <h2 class="t-title-m">{{ t('gameClass.my.title') }}</h2>
        <NumberText
          v-if="caravan.my"
          class="t-body-m block break-words text-muted"
          :text="t('gameClass.my.stats', { stars: NUMBER_MARK, runs: NUMBER_MARK, score: NUMBER_MARK })"
          :value="[caravan.my.stars, caravan.my.runs, caravan.my.best_score]"
          data-testid="my-stats"
        />
        <p v-else class="t-body-m text-muted" data-testid="my-none">{{ t('gameClass.my.none') }}</p>
      </section>

      <!-- The boss of the class. -->
      <section class="card mt-4 flex flex-col gap-3" :data-state="bossState" data-testid="boss-card">
        <div class="min-w-0">
          <h2 class="t-title-m">{{ t('gameClass.boss.title') }}</h2>
          <p v-if="caravan.boss.defeated" class="t-label-s flex items-center gap-1 text-success-ink" data-testid="boss-defeated">
            <AppIcon name="check" :size="16" class="shrink-0" />
            {{ t('gameClass.boss.defeated') }}
          </p>
        </div>
        <template v-if="view === 'active'">
          <p v-if="bossState === 'locked'" class="t-body-m text-muted" data-testid="boss-locked">{{ t('gameClass.boss.locked') }}</p>
          <template v-else-if="bossState === 'ready'">
            <p class="t-body-m text-muted" data-testid="boss-ready">{{ t('gameClass.boss.ready') }}</p>
            <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="starting" data-testid="boss-go" @click="fight(false)">
              {{ t('gameClass.boss.go') }}
            </button>
          </template>
          <p v-else class="t-body-m" data-testid="boss-attempt">
            {{ bossState === 'won' ? t('gameClass.boss.won') : t('gameClass.boss.lost') }}
          </p>
        </template>
        <p v-else-if="bossState === 'won' || bossState === 'lost'" class="t-body-m" data-testid="boss-attempt">
          {{ bossState === 'won' ? t('gameClass.boss.won') : t('gameClass.boss.lost') }}
        </p>
        <p v-else-if="!caravan.boss.defeated" class="t-body-m text-muted" data-testid="boss-missed">{{ t('gameClass.boss.missed') }}</p>
      </section>

      <!-- The classmates by contribution (the order is the server's). -->
      <section class="mt-4" data-testid="members">
        <h2 class="section-label !mt-0">{{ t('gameClass.members.title') }}</h2>
        <p v-if="caravan.members.length === 0" class="card t-body-m text-muted" data-testid="members-empty">
          {{ t('gameClass.members.empty') }}
        </p>
        <!-- The table of the class as the league of the kit (LeagueRow): the place, the avatar with the first letter, the name; my row in the soft accent with my avatar in muhr and "· you". -->
        <ol v-else class="card flex flex-col gap-0.5 px-2 py-2">
          <li
            v-for="(member, index) in caravan.members"
            :key="index"
            class="league-row py-1.5"
            :data-me="member.is_me"
            data-testid="member"
          >
            <span class="w-6 shrink-0 text-center font-display text-[15px] leading-5 font-semibold" :class="member.is_me ? 'text-accent' : 'text-muted'">{{ index + 1 }}</span>
            <span
              class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm leading-none font-semibold"
              :class="member.is_me ? 'bg-muhr text-on-muhr' : 'bg-sunken text-muted'"
              aria-hidden="true"
              data-testid="member-avatar"
            >{{ initialOf(member.name) }}</span>
            <span class="min-w-0 flex-1">
              <span class="t-body-m-strong block break-words">
                {{ member.name }}
                <template v-if="member.is_me"> · <span data-testid="member-me">{{ t('gameClass.members.me') }}</span></template>
              </span>
              <span class="t-label-s block text-muted">
                <NumberText :text="t('gameClass.members.stats', { stars: NUMBER_MARK, runs: NUMBER_MARK })" :value="[member.stars, member.runs]" />
                <template v-if="member.boss_won"> · {{ t('gameClass.members.boss_won') }}</template>
              </span>
            </span>
          </li>
        </ol>
      </section>
    </template>

    <!-- Another run is on: continue it, or start the boss (the old run is not counted). -->
    <BottomSheet v-if="conflict" :label="t('game.conflict.title')" data-testid="conflict-sheet" @close="conflict = null">
      <h2 class="t-display-m pr-10">{{ t('game.conflict.title') }}</h2>
      <p class="t-body-m text-muted">{{ t('game.conflict.text') }}</p>
      <button type="button" class="btn btn-primary btn-block" data-testid="conflict-continue" @click="continueRun">
        {{ t('game.conflict.continue') }}
      </button>
      <button type="button" class="btn btn-ghost btn-block" :disabled="starting" data-testid="conflict-replace" @click="replaceRun">
        {{ t('game.conflict.replace') }}
      </button>
    </BottomSheet>
  </div>
</template>
