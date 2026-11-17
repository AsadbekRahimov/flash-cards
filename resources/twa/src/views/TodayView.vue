<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'
import ClassCaravanCard from '@/components/game/ClassCaravanCard.vue'
import HardRoundCard from '@/components/HardRoundCard.vue'
import AssignmentsBlock from '@/components/AssignmentsBlock.vue'
import GoalRing from '@/components/GoalRing.vue'
import IntroSheet from '@/components/IntroSheet.vue'
import LanguageSheet from '@/components/LanguageSheet.vue'
import LapsedBanner from '@/components/LapsedBanner.vue'
import PaywallSheet, { type PaywallReason } from '@/components/PaywallSheet.vue'
import ReadinessBlock from '@/components/ReadinessBlock.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { hasLearnerProgress } from '@/composables/learnerProgress'
import { GAMES_CARD_MODES, MODE_LOOK, PRACTICE_MIN_LEARNED, modeTitle } from '@/composables/practiceLabels'
import { tierLabelKey } from '@/composables/leagueTiers'
import { STREAK_LINE_EVERY_MS, STREAK_LINE_KEY } from '@/composables/proReminders'
import { hasSeen, isSnoozed, markSeen, snooze } from '@/composables/seenFlag'
import { trialDaysLeft, trialEndsAt } from '@/composables/trial'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAssignmentsStore } from '@/stores/assignments'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { errorMessage } from '@/i18n/errors'
import { LANGUAGE_TITLE, useI18n } from '@/i18n'
import type { OpenSessionRequest, PracticeAccessToday } from '@/api/types'

/**
 * "Home" tab (docs/14 §6.1): a greeting with the date and the plan, the session card (goal ring, what is waiting,
 * the one primary button), the streak, assignments of the center, "My course" and the league line. A tab root,
 * so no system "Back" button. Data: `GET /today` (stores `today`, `assignments`).
 */
const { t, tn, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const today = useTodayStore()
const training = useTrainingStore()
const assignments = useAssignmentsStore()
const billing = useBillingStore()
const { close } = useTelegram()
const { data, status, errorMessage: loadError } = storeToRefs(today)
const { opening, openError } = storeToRefs(training)

useBackButton(null)

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    today.status = 'error'
    today.errorMessage = errorMessage(err, 'today')
    return
  }
  // The center's assignments are secondary: they load alongside Today, never blocking or breaking it.
  void assignments.load()
  await today.load()

  // Opened from a reminder (`/twa/?from=reminder`, docs/12 §7.5): go straight into the daily
  // review. `from=reminder_quiz` is the button left on the reminder after the answer in the chat; it
  // opens the same review, and the source tells the two apart in the funnel. The marker is dropped
  // from the URL first so a reload or "back" does not reopen it.
  const from = route.query.from
  if ((from === 'reminder' || from === 'reminder_quiz') && today.data !== null) {
    await router.replace({ name: 'today', query: {} })
    await openSession({ mode: 'daily_review', source: from })
    return
  }

  // Opened from the group announcement (`/twa/?from=assignment&lesson=<id>`, docs/13 §2.3): start the
  // lesson through the usual session path (the server re-checks access to the lesson).
  const lessonId = Number(route.query.lesson)
  if (route.query.from === 'assignment' && Number.isInteger(lessonId) && lessonId > 0 && today.data !== null) {
    await router.replace({ name: 'today', query: {} })
    await openSession({ mode: 'lesson', lesson_id: lessonId, source: 'assignment' })
  }
}

/** Opens a personal session and goes to the training screen; a failure stays on this screen. */
async function openSession(body: OpenSessionRequest): Promise<void> {
  const sessionId = await training.open(body)
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

const review = (): Promise<void> => openSession({ mode: 'daily_review', source: 'self' })

/** The round of hard words: opens it, or continues the one that is open (docs/06 §14); a refusal shows under the session button. */
const startHardRound = (): Promise<void> => openSession({ mode: 'hard_review', source: 'self' })

const openAssignment = (lessonId: number): Promise<void> => openSession({ mode: 'lesson', lesson_id: lessonId, source: 'assignment' })

function learnNext(lessonId: number): Promise<void> {
  return openSession({ mode: 'lesson', lesson_id: lessonId, source: 'self' })
}

/** Whether the learner is past the first day (docs/14 §3): a newcomer's Home keeps only the greeting, the goal, the counters and the button. */
const hasProgress = computed(() => data.value !== null && hasLearnerProgress(data.value))

const INTRO_KEY = 'lexiflow:intro-seen'
const introOpen = ref(false)
// The first visit of a newcomer: the sheet "How it works" opens once (marked at the moment it opens), never for a learner with progress.
watch(data, (current) => {
  if (current === null || introOpen.value || hasLearnerProgress(current) || hasSeen(INTRO_KEY)) return
  markSeen(INTRO_KEY)
  introOpen.value = true
})

/** "Til · Язык": the language chip of the header opens the sheet. */
const languageOpen = ref(false)

const paywallOpen = ref(false)
/** Why the sheet was opened: the session card ('quota' / 'info') or the streak-freeze teaser (docs/13 §7.2). */
const paywallReason = ref<PaywallReason>('info')

function openPaywall(reason: PaywallReason): void {
  paywallReason.value = reason
  paywallOpen.value = true
}

/** "Repeat words" in the paywall: close the sheet (a failure shows on this screen) and start the review. */
const { reviewFromPaywall: onPaywallReview } = useReviewFromPaywall(() => {
  paywallOpen.value = false
})

const name = computed(() => auth.learner?.first_name?.trim() ?? '')
const greeting = computed(() => (name.value !== '' ? t('today.greeting', { name: name.value }) : t('today.title')))
/**
 * «среда, 28 октября»: the day the goal and the streak below are counted in, i.e. in the learner's time zone that
 * `/today` names (the zone of the settings). The device's zone is only the fallback, for a zone this browser does not know.
 */
const dateLine = computed(() =>
  formatDate(new Date().toISOString(), 'weekdayDayMonth', locale.value, data.value?.timezone),
)
const isPro = computed(() => data.value?.entitlement.plan === 'pro')
const NO_PRACTICE: PracticeAccessToday = { available: false, pro_required: false, modes: [], battle: false, pro_promise: 'none' }

/**
 * The welcome trial (docs/04 A.2): `/today` carries no `renewal`, so for a Pro learner `/me` is asked (the billing store keeps it
 * fresh for a minute) and `renewal.provider === 'trial'` tells it. Only a Pro that has an end can be the trial (a perpetual
 * grant or a center's Pro cannot), so those read no `/me`. A paid or a center's Pro has no such line.
 */
watch(isPro, (pro) => {
  const current = data.value?.entitlement
  if (pro && current?.source === 'subscription' && current.until !== null) void billing.load()
}, { immediate: true })
/**
 * The standing entry of the Karvon map (owner's decision of 2026-11-17: the game is open to everybody): `/today.game.available`
 * (the game is open to this learner; no field - an older server - no row, `/me` is not asked for it) and an active course. The
 * caravan of the day / a run that is on already has its own row (`gameEntry`): then this one is not shown.
 */
const showGameMap = computed(() => data.value?.active_course != null && data.value.game?.available === true && !gameEntry.value)
const trialEnd = computed(() => (isPro.value ? trialEndsAt(billing.entitlement) : null))
const trialText = computed(() => {
  if (trialEnd.value === null) return ''
  const days = trialDaysLeft(trialEnd.value)
  return days <= 1 ? t('today.trial.last') : t('today.trial.line', { count: days })
})

/** The invitation card "Pro for free" (docs/13 §7.1): closed with the cross for 14 days, remembered on the device. */
const INVITE_KEY = 'lexiflow:invite-card-hidden'
const INVITE_SNOOZE_MS = 14 * 24 * 60 * 60 * 1000
const inviteHidden = ref(isSnoozed(INVITE_KEY))
function hideInvite(): void {
  inviteHidden.value = true
  snooze(INVITE_KEY, INVITE_SNOOZE_MS)
}
/** Not for a paying Pro, not on the first card, not next to the "Pro ended" banner (it has its own way to the invitations). */
const showInvite = computed(() => (!isPro.value || trialEnd.value !== null) && !showStart.value && !inviteHidden.value
  && (data.value?.entitlement.lapsed ?? null) === null)

const goalLeft = computed(() => (data.value ? Math.max(0, data.value.goal.target - data.value.goal.done) : 0))
/**
 * A newcomer who has not picked a course yet (docs/14 §6.1): no active course, no open session, nothing to review and no
 * assignment of a group. Home is then ONE start card with one button instead of an empty goal ring, "nothing to review" and
 * a second "choose a course" further down. Any of those things turns the usual screen back on.
 */
const startCandidate = computed(() => data.value !== null && data.value.active_course === null && data.value.open_session_id === null
  && data.value.due_count === 0)
/** Only the assignments of a group can still turn the start card off, so only then is the screen held back for them (a failed load counts as loaded). */
const awaitingAssignments = computed(() => startCandidate.value && !assignments.loaded)
const showStart = computed(() => startCandidate.value && assignments.loaded && assignments.items.length === 0)

const needsCourseToStart = computed(() => data.value !== null &&
  data.value.active_course === null && data.value.due_count === 0 && data.value.open_session_id === null)

/**
 * The server's sign that the active course has no unlearned words left (`next_lesson` is null: the course is empty or finished).
 * `new_words_remaining` is only the tariff's daily quota and says nothing about the course.
 */
const courseHasNoNewWords = computed(() => data.value !== null && data.value.active_course !== null && data.value.active_course.next_lesson === null)
const courseIsEmpty = computed(() => courseHasNoNewWords.value && data.value?.active_course?.progress.total === 0)
/** No new words in the course and nothing to review: "Start session" would only open an empty session, so the card leads to the catalog. */
const needsOtherCourse = computed(() => courseHasNoNewWords.value && data.value !== null && data.value.due_count === 0 && data.value.open_session_id === null)
const noNewWordsText = computed(() => t(courseIsEmpty.value ? 'today.course.empty' : 'today.new.course_done'))

/**
 * Nothing to do today: no review is due, no session is open and the free quota of new words is used up (the server says
 * `0`, not `null`). The primary "Start session" would only open an empty session, so the card says so calmly instead.
 */
const allDone = computed(() => {
  const current = data.value
  return current !== null && current.active_course !== null && current.due_count === 0
    && current.open_session_id === null && current.new_words_remaining === 0 && !courseHasNoNewWords.value
})

/** The practice access comes with `/today` itself (docs/04 A.2a): no `/me` is read for the games card. Absent (an older server): no practice. */
const practice = computed(() => data.value?.practice ?? NO_PRACTICE)
/**
 * The "Word games" card (docs/16 §11.1): under the session card, when the learner has a course and a few learned words (the
 * first mode of the hub opens with them; `PRACTICE_MIN_LEARNED`) - never on the start card. One entry to the practice of the
 * main course: "all done" no longer has a button of its own.
 */
const gamesCandidate = computed(() => !showStart.value
  && (data.value?.active_course?.progress.learned ?? 0) >= PRACTICE_MIN_LEARNED)
const showGames = computed(() => gamesCandidate.value && practice.value.available)
/** Practice of the main course (docs/16 §11.1): the same rule as on the course screen - behind Pro it opens the paywall. */
function openPractice(): void {
  const slug = data.value?.active_course?.slug
  if (!slug) return
  if (practice.value.pro_required) openPaywall('practice')
  else void router.push({ name: 'practice-hub', params: { slug } })
}

/** What the second line of the session card says about new words; the quota is the server's (`null` = no limit). */
const newWordsText = computed(() => {
  const remaining = data.value?.new_words_remaining ?? null
  if (remaining === null) return t('today.new.unlimited')
  return remaining > 0 ? t('today.new.remaining', { count: remaining }) : t('today.new.exhausted')
})

const assignmentItems = computed(() => assignments.items)
const streak = computed(() => data.value?.streak ?? null)
/**
 * A Free learner whose streak broke (`current` is 0 while the `best` says there was one - the server sends no "reset" flag, this is the state
 * the card already shows as "streak-none"): one line under it, "In Pro a freeze would have kept the streak", that leads to the sheet of
 * the freezes. Once in 7 days (`STREAK_LINE_EVERY_MS`): it is counted when the line first shows, and the screen keeps it while it is open.
 */
const freezeLineAllowed = !isSnoozed(STREAK_LINE_KEY)
const showFreezeLine = computed(() => freezeLineAllowed && data.value !== null && !isPro.value && !showStart.value
  && streak.value !== null && streak.value.current === 0 && streak.value.best > 0)
watch(showFreezeLine, (on) => {
  if (on) snooze(STREAK_LINE_KEY, STREAK_LINE_EVERY_MS)
}, { immediate: true })

/**
 * The entry of Karvon, by `/today.game` (never `/me`): a run that is on first, else the caravan of the day. A closed game sends
 * `{daily_available: false, active_run: null}` - no entry; no `game` block (an older server) - none either.
 */
const gameEntry = computed((): { kind: 'run' | 'daily'; to: RouteLocationRaw; text: string; progress: string } | null => {
  const game = data.value?.game
  if (!game) return null
  if (game.active_run) {
    const { id, progress } = game.active_run
    return {
      kind: 'run',
      to: `/game/runs/${id}`,
      text: t('today.game.continue'),
      progress: t('game.map.active_progress', { answered: progress.answered, total: progress.total }),
    }
  }
  return game.daily_available ? { kind: 'daily', to: { name: 'game-daily' }, text: t('today.game.daily'), progress: '' } : null
})

const lessonLabel = computed(() => {
  const lesson = data.value?.active_course?.next_lesson
  if (!lesson) return ''
  const number = t('course.lesson', { number: lesson.lesson })
  return lesson.title ? `${number} · ${lesson.title}` : number
})

const coursePercent = computed(() => {
  const progress = data.value?.active_course?.progress
  return progress && progress.total > 0 ? Math.min(100, Math.round((progress.learned / progress.total) * 100)) : 0
})
/**
 * The readiness block (docs/04 A.20) lives in the card of the course and reads `/today` only. A newcomer who has not met a word
 * yet does not see it: with no progress it would only show 0 % and a test with nothing to ask about.
 */
const readiness = computed(() => (hasProgress.value ? (data.value?.active_course?.readiness ?? null) : null))

const courseProgressText = computed(() => {
  const progress = data.value?.active_course?.progress
  return progress ? t('catalog.progress', { done: progress.learned, total: progress.total }) : ''
})

/** The league row: the place of the week; none without the league data. */
const leagueRow = computed(() => {
  const league = data.value?.league
  // The server sends null while the leagues are off, the learner opted out or is not placed yet: no row then.
  if (!league) return null

  return {
    title: t('today.league.title', { tier: t(tierLabelKey(league.tier_key)) }),
    subtitle: t('today.league.subtitle', {
      rank: league.rank,
      members: league.members_count,
      points: tn('league.points', league.points),
    }),
  }
})

// The message sits under the session button, but a failed lesson or assignment is opened from further down.
const openErrorBox = ref<InstanceType<typeof InlineAlert> | null>(null)
watch(openError, async (message) => {
  if (message === '') return
  await nextTick()
  openErrorBox.value?.$el.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' })
})

onMounted(() => {
  void load()
})

// An open error belongs to this screen only; it must not greet the learner on the next one.
onBeforeUnmount(() => {
  training.clearOpenError()
})
</script>

<template>
  <div class="page" data-testid="today-view">
    <LoadingState v-if="status === 'idle' || status === 'loading' || awaitingAssignments" />

    <ErrorState v-else-if="!data" :message="loadError" @retry="load" />

    <div v-else class="space-y-4">
      <PageHeader large :title="greeting" :subtitle="dateLine">
        <template #trailing>
          <!-- A chip is 24 px high: the padding (cancelled by the margin) makes the touch target 44 px. -->
          <div class="flex items-center gap-4">
            <button type="button" class="-m-2.5 block p-2.5" :aria-label="LANGUAGE_TITLE" data-testid="lang-btn" @click="languageOpen = true">
              <span class="chip chip-neutral">
                <AppIcon name="globe-alt" :size="14" />
                {{ locale.toUpperCase() }}
              </span>
            </button>
            <RouterLink :to="{ name: 'billing' }" class="-m-2.5 block p-2.5" data-testid="plan-badge">
              <span class="chip" :class="isPro ? 'chip-accent' : 'chip-neutral'">
                {{ t(isPro ? 'today.plan.pro' : 'today.plan.free') }}
              </span>
            </RouterLink>
          </div>
        </template>
      </PageHeader>

      <LapsedBanner
        v-if="data.entitlement.plan === 'free'"
        :lapsed="data.entitlement.lapsed"
        @renew="router.push({ name: 'billing' })"
        @invite="router.push({ name: 'referrals', query: { from: 'today' } })"
      />

      <InlineAlert v-if="status === 'error'" tone="danger" :message="loadError" data-testid="reload-error" />

      <section v-if="showStart" class="card flex flex-col items-center gap-3 py-6 text-center" data-testid="start-card">
        <span class="icon-tile size-16 rounded-2xl tile-blue"><AppIcon name="academic-cap" :size="32" /></span>
        <h2 class="text-[22px] leading-7 font-bold">{{ t('today.start.title') }}</h2>
        <p class="text-[15px] leading-5 text-tg-subtitle">{{ t('today.start.text') }}</p>
        <RouterLink :to="{ name: 'courses' }" class="btn btn-primary btn-lg btn-block mt-1" data-testid="start-choose-course">
          <AppIcon name="academic-cap" :size="20" />
          {{ t('today.course.choose') }}
        </RouterLink>
      </section>

      <!-- Under the start card the reminder to finish the setup in the bot is a quiet line, not a second card. -->
      <div v-if="showStart && !data.onboarding_completed" class="flex items-center gap-2 px-1" data-testid="onboarding-hint">
        <AppIcon name="information-circle" :size="18" class="shrink-0 text-accent" />
        <p class="min-w-0 flex-1 text-[13px] leading-[18px] text-tg-subtitle">{{ t('today.onboarding_hint') }}</p>
        <button type="button" class="btn btn-plain btn-sm shrink-0" data-testid="onboarding-btn" @click="close()">
          {{ t('today.onboarding_btn') }}
        </button>
      </div>

      <div v-else-if="!data.onboarding_completed" class="card flex items-start gap-3" data-testid="onboarding-hint">
        <AppIcon name="information-circle" :size="22" class="mt-px text-accent" />
        <div class="min-w-0 flex-1 space-y-3">
          <p class="text-[15px] leading-5">{{ t('today.onboarding_hint') }}</p>
          <button type="button" class="btn btn-tinted btn-block" data-testid="onboarding-btn" @click="close()">
            {{ t('today.onboarding_btn') }}
          </button>
        </div>
      </div>

      <!-- A line, not a card: it says how long the welcome Pro lasts and leads to the subscription. Under the start card on the first visit. -->
      <RouterLink
        v-if="trialEnd !== null"
        :to="{ name: 'billing' }"
        class="flex min-h-11 items-center gap-2 px-1 text-[15px] leading-5"
        data-testid="trial-line"
      >
        <AppIcon name="sparkles" :size="18" class="shrink-0 text-accent" />
        <span class="min-w-0 flex-1">{{ trialText }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
      </RouterLink>

      <section v-if="!showStart" class="card" data-testid="review-card">
        <div class="flex items-center gap-4" data-testid="goal-card">
          <GoalRing :done="data.goal.done" :target="data.goal.target" :met="data.goal.met" />
          <div class="min-w-0 flex-1">
            <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('today.goal.title') }}</h2>
            <p class="mt-0.5 text-[15px] leading-5 text-tg-subtitle" data-testid="goal-text">
              {{ t('today.goal.progress', { done: data.goal.done, target: data.goal.target }) }}
            </p>
            <p
              v-if="data.goal.met"
              class="mt-1 flex items-center gap-1.5 text-[15px] leading-5 font-semibold text-success-ink"
              data-testid="goal-met"
            >
              <AppIcon name="check-circle" solid :size="18" />
              <span>{{ t('today.goal.met') }}</span>
            </p>
            <p v-else class="mt-1 text-[15px] leading-5" data-testid="goal-left">{{ tn('today.goal.left', goalLeft) }}</p>
          </div>
        </div>

        <RouterLink
          :to="{ name: 'settings' }"
          class="mt-3 flex min-h-11 items-center justify-between gap-2 text-[15px] leading-5 text-accent"
          data-testid="goal-setting"
        >
          <span>{{ tn('today.goal.daily', data.goal.target) }}</span>
          <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
        </RouterLink>

        <ul class="border-t pt-2">
          <li class="flex min-h-11 items-center gap-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
              <AppIcon name="arrow-path" :size="18" />
            </span>
            <span
              class="text-[15px] leading-5"
              :class="{ 'text-tg-hint': data.due_count === 0 }"
              data-testid="due-count"
            >
              {{ data.due_count > 0 ? tn('today.review.due', data.due_count) : t('today.review.none') }}
            </span>
          </li>
          <li v-if="!needsCourseToStart">
            <RouterLink
              v-if="!data.active_course"
              :to="{ name: 'courses' }"
              class="flex min-h-11 items-center gap-3 text-accent"
              data-testid="new-no-course"
            >
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                <AppIcon name="sparkles" :size="18" />
              </span>
              <span class="flex-1 text-[15px] leading-5 font-medium">{{ t('today.new.no_course') }}</span>
              <AppIcon name="chevron-right" :size="16" class="text-tg-hint" />
            </RouterLink>
            <RouterLink
              v-else-if="courseHasNoNewWords"
              :to="{ name: 'courses' }"
              class="flex min-h-11 items-center gap-3 text-accent"
              data-testid="new-course-empty"
            >
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                <AppIcon name="sparkles" :size="18" />
              </span>
              <span class="flex-1 text-[15px] leading-5 font-medium">{{ noNewWordsText }}</span>
              <AppIcon name="chevron-right" :size="16" class="text-tg-hint" />
            </RouterLink>
            <div v-else class="flex min-h-11 items-center gap-3">
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                <AppIcon name="sparkles" :size="18" />
              </span>
              <span class="text-[15px] leading-5" data-testid="new-remaining">{{ newWordsText }}</span>
            </div>
          </li>
        </ul>

        <RouterLink
          v-if="needsCourseToStart"
          :to="{ name: 'courses' }"
          class="btn btn-primary btn-lg btn-block mt-3"
          data-testid="start-choose-course"
        >
          <AppIcon name="academic-cap" :size="20" />
          {{ t('today.course.choose') }}
        </RouterLink>
        <RouterLink
          v-else-if="needsOtherCourse"
          :to="{ name: 'courses' }"
          class="btn btn-primary btn-lg btn-block mt-3"
          data-testid="start-next-course"
        >
          <AppIcon name="academic-cap" :size="20" />
          {{ t('today.course.choose_other') }}
        </RouterLink>
        <div v-else-if="allDone" class="mt-3 space-y-2" data-testid="all-done">
          <p class="flex items-center justify-center gap-2 text-center text-[17px] leading-[22px] font-semibold">
            <AppIcon name="check-circle" solid :size="20" class="shrink-0 text-success" />
            <span>{{ t('today.all_done') }}</span>
          </p>
          <!-- The quota of the free plan is what ended the day: one quiet way to Pro. Not for a newcomer, not for Pro. -->
          <p v-if="!isPro && hasProgress" class="text-center text-[13px] leading-[18px]">
            <button type="button" class="text-tg-link underline" data-testid="all-done-pro" @click="openPaywall('quota')">
              {{ t('today.all_done.pro') }}
            </button>
          </p>
        </div>
        <button
          v-else
          type="button"
          class="btn btn-primary btn-lg btn-block mt-3"
          :disabled="opening"
          data-testid="review-btn"
          @click="review"
        >
          <AppIcon name="play" solid :size="20" />
          {{ data.open_session_id !== null ? t('today.review.continue') : t('today.review.start') }}
        </button>

        <InlineAlert v-if="openError" ref="openErrorBox" tone="danger" :message="openError" class="mt-3" data-testid="open-error" />

        <p v-if="!allDone" class="mt-3 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="session-hint">
          {{ t(needsCourseToStart || needsOtherCourse ? 'today.new.no_course' : 'today.session.hint') }}
        </p>

      </section>

      <!-- The round of hard words (docs/06 §14): compact, under the session card and above the games; nothing while it is not on offer (or was played today). -->
      <HardRoundCard v-if="hasProgress" :round="data.hard_round" :busy="opening" @start="startHardRound" />

      <!-- Practice of the main course, one entry (docs/16 §11.1): three modes by their icons and one button. -->
      <section v-if="showGames" class="card flex items-center gap-3" data-testid="games-card">
        <div class="min-w-0 flex-1">
          <h2 class="text-[17px] leading-[22px] font-semibold">{{ t('today.games.title') }}</h2>
          <div class="mt-2 flex gap-2" aria-hidden="true" data-testid="games-modes">
            <span
              v-for="mode in GAMES_CARD_MODES"
              :key="mode"
              class="icon-tile size-9 rounded-xl"
              :class="`tile-${MODE_LOOK[mode].tile}`"
              :title="modeTitle(mode)"
            >
              <AppIcon :name="MODE_LOOK[mode].icon" :size="18" />
            </span>
          </div>
        </div>
        <button type="button" class="btn btn-tinted shrink-0" data-testid="games-play-btn" @click="openPractice">
          {{ t('today.games.play') }}
        </button>
      </section>

      <!-- The caravan of the class that is on (docs/05 §5b.7): by `/today.game.class_caravan`, no other request. The teacher starts it for the whole group, so a newcomer of the center is its addressee too. -->
      <ClassCaravanCard v-if="data.game?.class_caravan" :caravan="data.game.class_caravan" :timezone="data.timezone" />

      <!-- The secondary entry of Karvon (docs/05 §5b.4): only when `/today.game` says there is something to do - a closed game says nothing. -->
      <RouterLink
        v-if="hasProgress && gameEntry"
        :to="gameEntry.to"
        class="card flex min-h-11 items-center gap-3 transition-transform active:scale-[0.99]"
        :data-kind="gameEntry.kind"
        data-testid="game-entry"
      >
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="sparkles" :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block text-[13px] leading-[18px] font-semibold tracking-wide text-accent-ink uppercase">{{ t('today.game.title') }}</span>
          <span class="block text-[15px] leading-5 font-medium" data-testid="game-entry-text">{{ gameEntry.text }}</span>
          <span v-if="gameEntry.progress" class="block text-[13px] leading-[18px] text-tg-subtitle" data-testid="game-entry-progress">{{ gameEntry.progress }}</span>
        </span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
      </RouterLink>

      <!-- The standing entry of the Karvon map (docs/05 §5b.4): while the game is open and there is no caravan of the day to show. -->
      <RouterLink
        v-if="hasProgress && showGameMap"
        :to="{ name: 'game' }"
        class="card flex min-h-11 items-center gap-3 transition-transform active:scale-[0.99]"
        data-testid="game-map-entry"
      >
        <span class="icon-tile size-10 rounded-xl tile-orange"><AppIcon name="sparkles" :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block text-[13px] leading-[18px] font-semibold tracking-wide text-accent-ink uppercase">{{ t('today.game.title') }}</span>
          <span class="block text-[15px] leading-5 font-medium">{{ t('today.game.map') }}</span>
        </span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
      </RouterLink>

      <section v-if="streak && !showStart" class="card" data-testid="streak-card">
        <div class="flex items-start gap-3">
          <span class="icon-tile size-10 rounded-xl" :class="streak.current > 0 ? 'tile-orange' : 'tile-gray'">
            <AppIcon name="fire" solid :size="22" />
          </span>
          <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
              <p class="text-[17px] leading-[22px] font-semibold" data-testid="streak-current">
                {{ streak.current > 0 ? tn('today.streak.in_row', streak.current) : t('today.streak.title') }}
              </p>
              <span v-if="streak.best > streak.current" class="chip chip-neutral shrink-0" data-testid="streak-best">
                {{ t('today.streak.best', { best: streak.best }) }}
              </span>
            </div>
            <p v-if="streak.at_risk" class="mt-0.5 text-[15px] leading-5 text-warning-ink" data-testid="streak-at-risk">
              {{ t('today.streak.at_risk') }}
            </p>
            <p
              v-else-if="streak.current > 0 && streak.met_today && streak.held_by === 'game'"
              class="mt-0.5 text-[15px] leading-5 text-tg-subtitle"
              data-testid="streak-held-by-game"
            >
              {{ t('today.streak.held_by_game') }}
            </p>
            <p
              v-else-if="streak.current > 0 && streak.met_today"
              class="mt-0.5 text-[15px] leading-5 text-tg-subtitle"
              data-testid="streak-safe"
            >
              {{ t('today.streak.safe') }}
            </p>
            <p
              v-else-if="streak.current === 0"
              class="mt-0.5 text-[15px] leading-5 text-tg-subtitle"
              data-testid="streak-none"
            >
              {{ t('today.streak.none') }}
            </p>
          </div>
        </div>

        <div v-if="streak.frozen_yesterday || isPro || streak.current > 0" class="mt-3 space-y-1 border-t pt-3">
          <p v-if="streak.frozen_yesterday" class="text-[15px] leading-5" data-testid="streak-frozen">
            {{ t('today.streak.frozen_yesterday') }}
          </p>
          <p v-if="isPro" class="text-[13px] leading-[18px] text-tg-hint" data-testid="streak-freezes">
            {{ streak.freezes > 0 ? tn('today.streak.freezes', streak.freezes) : t('today.streak.freezes_none') }}
          </p>
          <div v-else-if="streak.current > 0" class="flex justify-center">
            <button type="button" class="btn btn-plain btn-sm" data-testid="streak-freeze-teaser" @click="openPaywall('streak_freeze')">
              {{ t('today.streak.freeze_teaser') }}
            </button>
          </div>
        </div>

        <div v-if="showFreezeLine" class="mt-3 flex justify-center border-t pt-3">
          <button type="button" class="btn btn-plain btn-sm" data-testid="streak-freeze-line" @click="openPaywall('streak_freeze')">
            {{ t('today.streak.freeze_line') }}
          </button>
        </div>
      </section>

      <AssignmentsBlock v-if="assignmentItems.length > 0" :items="assignmentItems" :busy="opening" @open="openAssignment" />

      <section v-if="!showStart" data-testid="course-card">
        <h2 class="section-label">{{ t('today.course.title') }}</h2>

        <div v-if="data.active_course" class="card space-y-3">
          <div>
            <h3 class="text-[17px] leading-[22px] font-semibold" data-testid="course-title">{{ data.active_course.title }}</h3>
            <div
              v-if="!courseIsEmpty"
              class="progress mt-2"
              role="progressbar"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="coursePercent"
              :aria-label="courseProgressText"
            >
              <div class="progress-fill" :style="{ width: `${coursePercent}%` }" />
            </div>
            <p v-if="!courseIsEmpty" class="mt-1.5 text-[13px] leading-[18px] text-tg-hint" data-testid="course-progress">{{ courseProgressText }}</p>
          </div>

          <ReadinessBlock
            v-if="readiness"
            :readiness="readiness"
            :slug="data.active_course.slug"
          />

          <template v-if="data.active_course.next_lesson">
            <div class="flex items-center gap-3 border-t pt-3">
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                <AppIcon name="book-open" :size="18" />
              </span>
              <div class="min-w-0">
                <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('today.course.next') }}</p>
                <p class="text-[15px] leading-5 font-semibold" data-testid="next-lesson">{{ lessonLabel }}</p>
              </div>
            </div>
            <!-- With the quota used up and nothing due, the lesson would open the same empty session as "Start session". -->
            <template v-if="!allDone">
              <button
                type="button"
                class="btn btn-tinted btn-block"
                :disabled="opening"
                data-testid="next-lesson-btn"
                @click="learnNext(data.active_course.next_lesson.id)"
              >
                {{ t('today.course.learn') }}
              </button>
              <p class="text-center text-[13px] leading-[18px] text-tg-hint" data-testid="next-lesson-hint">{{ t('today.course.learn_hint') }}</p>
            </template>
          </template>
          <template v-else>
            <p
              class="flex items-center gap-2 border-t pt-3 text-[15px] leading-5 text-tg-subtitle"
              data-testid="course-completed"
            >
              <AppIcon v-if="!courseIsEmpty" name="check-circle" solid :size="20" class="shrink-0 text-success" />
              <span>{{ t(courseIsEmpty ? 'today.course.empty' : 'today.course.completed') }}</span>
            </p>
            <RouterLink :to="{ name: 'courses' }" class="btn btn-tinted btn-block" data-testid="course-choose-other">
              {{ t('today.course.choose_other') }}
            </RouterLink>
          </template>
        </div>

        <div v-else class="card p-0">
          <EmptyState data-testid="no-course" icon="academic-cap" :text="t('today.course.none')">
            <template #action>
              <RouterLink :to="{ name: 'courses' }" class="btn btn-tinted" data-testid="choose-course">
                {{ t('today.course.choose') }}
              </RouterLink>
            </template>
          </EmptyState>
        </div>
      </section>

      <ListGroup v-if="hasProgress && leagueRow">
        <ListRow
          :to="{ name: 'league' }"
          icon="trophy"
          tile="yellow"
          :title="leagueRow.title"
          :subtitle="leagueRow.subtitle"
          data-testid="league-line"
        />
      </ListGroup>

      <section v-if="showInvite" class="card flex items-start gap-3" data-testid="invite-card">
        <span class="icon-tile tile-pink size-10 rounded-xl"><AppIcon name="gift" :size="20" /></span>
        <div class="min-w-0 flex-1">
          <p class="text-[15px] leading-5 font-semibold" data-testid="invite-text">{{ t('today.invite.text') }}</p>
          <button
            type="button"
            class="mt-1 inline-flex min-h-11 items-center text-[15px] leading-5 text-tg-link underline"
            data-testid="invite-more"
            @click="router.push({ name: 'referrals', query: { from: 'today' } })"
          >
            {{ t('today.invite.more') }}
          </button>
        </div>
        <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
        <button
          type="button"
          class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-tg-hint active:bg-track"
          :aria-label="t('today.invite.dismiss')"
          data-testid="invite-dismiss"
          @click="hideInvite()"
        >
          <AppIcon name="x-mark" :size="20" />
        </button>
      </section>
    </div>

    <IntroSheet v-if="introOpen" @close="introOpen = false" />
    <LanguageSheet v-if="languageOpen" @close="languageOpen = false" />

    <PaywallSheet
      v-if="paywallOpen"
      :reason="paywallReason"
      @close="paywallOpen = false"
      @review="onPaywallReview"
    />
  </div>
</template>
