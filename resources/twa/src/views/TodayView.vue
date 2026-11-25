<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { storeToRefs } from 'pinia'
import AssignmentsBlock from '@/components/AssignmentsBlock.vue'
import IntroSheet from '@/components/IntroSheet.vue'
import LapsedBanner from '@/components/LapsedBanner.vue'
import PaywallSheet, { type PaywallReason } from '@/components/PaywallSheet.vue'
import ReadinessBlock from '@/components/ReadinessBlock.vue'
import CupTodayRow, { type CupTodayCard } from '@/components/today/CupTodayRow.vue'
import DayPlan from '@/components/today/DayPlan.vue'
import LessonHero from '@/components/today/LessonHero.vue'
import ScoreTodayCard from '@/components/today/ScoreTodayCard.vue'
import TrialWelcomeSheet from '@/components/today/TrialWelcomeSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { isPhraseCourse } from '@/composables/courseLabels'
import { dayPlanSteps, gameStepExpected, type DayPlanStepKey } from '@/composables/dayPlan'
import { formatDate } from '@/composables/format'
import { hasLearnerProgress } from '@/composables/learnerProgress'
import { GAMES_CARD_MODES, PRACTICE_MIN_LEARNED, modeTitle } from '@/composables/practiceLabels'
import { tierLabelKey } from '@/composables/leagueTiers'
import { STREAK_LINE_EVERY_MS, STREAK_LINE_KEY } from '@/composables/proReminders'
import { hasSeen, isSnoozed, markSeen, snooze } from '@/composables/seenFlag'
import { takeTrialEnded } from '@/composables/trialEnded'
import { trialDaysLeft, trialEndsAt } from '@/composables/trial'
import { takeTrialWelcome } from '@/composables/trialWelcome'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { markTiming } from '@/composables/useTimingMarks'
import { useAssignmentsStore } from '@/stores/assignments'
import { useAuthStore } from '@/stores/auth'
import { useBillingStore } from '@/stores/billing'
import { useCoursesStore } from '@/stores/courses'
import { useCupsStore } from '@/stores/cups'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { OpenSessionRequest, PracticeAccessToday } from '@/api/types'

/**
 * "Home" tab (docs/14 §6.1, mock-ups `docs/design/2026-11-25-redesign/Main.html`, `MainNext.html`): the brand row, the date and the course
 * over the greeting; then, while `/today.plan` has a step to offer (or says the day is done), the big card of that step (`LessonHero`: the
 * lesson, the review of the lesson, the timed pairs), the card "Plan for today", the goal of the day with the streak and the tiles
 * "Review" / "Word games". Without such a plan (no course, the course is finished, an older server) the previous card "Goal of the day"
 * with the session button stays. Then "Today N of 100" of the word points (`score_today`, docs/32 §8.2; only with the v2 rules on).
 * Under it all - the list "For today" (assignments of the center, the round of hard words, the caravans),
 * "My course", the streak, the league line and the invitation. A tab root, so no system "Back" button. Data: `GET /today` (stores
 * `today`, `assignments`).
 */
const { t, tn, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const today = useTodayStore()
const training = useTrainingStore()
const assignments = useAssignmentsStore()
const billing = useBillingStore()
const cups = useCupsStore()
const catalog = useCoursesStore()
const { close } = useTelegram()
const { data, status, errorMessage: loadError } = storeToRefs(today)
const { opening, openError } = storeToRefs(training)

useBackButton(null)

/** This screen's own `/today` is on its way: it follows `assignments_possible` itself, the watch below stays out of it. */
let ownLoad = false

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    today.status = 'error'
    today.errorMessage = errorMessage(err, 'today')
    return
  }
  // The center's assignments are secondary, never blocking or breaking Today. A device that knows its learner may have some (the last
  // `/today` said so) asks them alongside Today; otherwise `/today.assignments_possible` decides first, so a self-study learner asks
  // no `/assignments` at all (docs/04 A.2a).
  const started = assignments.loadEarly()
  // The friends' cup (docs/33 §7.1): "my cups", best effort - a read younger than a minute is not repeated, a switched off cup is not
  // asked again in this session.
  void cups.load()
  ownLoad = true
  try {
    await today.load()
  } finally {
    ownLoad = false
  }
  if (today.data !== null) assignments.followToday(today.data.assignments_possible, started)

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

/** The first tap on the primary action of the screen (docs/04 A.21): how long the learner needed to understand it. */
const tapPrimary = (): void => markTiming('first_tap')

function review(): Promise<void> {
  tapPrimary()
  return openSession({ mode: 'daily_review', source: 'self' })
}

/** The round of hard words: opens it, or continues the one that is open (docs/06 §14); a refusal shows under the session button. */
const startHardRound = (): Promise<void> => openSession({ mode: 'hard_review', source: 'self' })

function openAssignment(lessonId: number): Promise<void> {
  tapPrimary()
  return openSession({ mode: 'lesson', lesson_id: lessonId, source: 'assignment' })
}

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

const paywallOpen = ref(false)
/** Why the sheet was opened: the session card ('quota' / 'info') or the streak-freeze teaser (docs/13 §7.2). */
const paywallReason = ref<PaywallReason>('info')

function openPaywall(reason: PaywallReason): void {
  paywallReason.value = reason
  paywallOpen.value = true
}

/**
 * The welcome trial is over (docs/05 §8c-1, P7): the first `/today` of a Free learner after its end opens the sheet `trial_ended` by
 * itself, once per end (`composables/trialEnded.ts`). Not on top of the first-run intro or of a sheet already open.
 */
watch(data, (current) => {
  if (current === null || current.entitlement.plan !== 'free' || introOpen.value || paywallOpen.value) return
  if (takeTrialEnded(current.entitlement.lapsed)) openPaywall('trial_ended')
}, { immediate: true })

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
const dateLine = computed(() => {
  const date = formatDate(new Date().toISOString(), 'weekdayDayMonth', locale.value, data.value?.timezone)
  return date.charAt(0).toLocaleUpperCase(locale.value) + date.slice(1)
})
/** The line over the greeting (mock-up Main): «Пятница, 20 ноября · English A1»; without a course only the date. */
const eyebrow = computed(() => {
  const course = data.value?.active_course?.title
  return course ? `${dateLine.value} · ${course}` : dateLine.value
})
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

/**
 * The welcome of the trial (docs/36 §4б, W3): the sheet opens by itself once per end of the trial (`trial_welcome_seen:<ends_at>`). Only the
 * learner's own trial: `/today` says Pro of a subscription and `/me` names the provider `trial` - a paid Pro and a center's Pro never see it.
 * Not on top of the first-run intro or of a sheet already open: it waits for them to close.
 */
const trialWelcomeOpen = ref(false)
watch([data, trialEnd, introOpen, paywallOpen], () => {
  const entitlement = data.value?.entitlement
  if (trialWelcomeOpen.value || introOpen.value || paywallOpen.value || entitlement?.plan !== 'pro' || entitlement.source !== 'subscription') return
  if (takeTrialWelcome(trialEnd.value)) trialWelcomeOpen.value = true
}, { immediate: true })

function trialWelcomeMore(): void {
  trialWelcomeOpen.value = false
  void router.push({ name: 'billing' })
}

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
/** The share of the goal for its line: a met goal is a full line even when `done` overshoots the target (or the target is odd). */
const goalPercent = computed(() => {
  const goal = data.value?.goal
  if (!goal) return 0
  if (goal.met) return 100
  return goal.target > 0 ? (goal.done / goal.target) * 100 : 0
})
/**
 * A newcomer who has not picked a course yet (docs/14 §6.1): no active course, no open session, nothing to review and no
 * assignment of a group. Home is then ONE start card with one button instead of an empty goal ring, "nothing to review" and
 * a second "choose a course" further down. Any of those things turns the usual screen back on.
 */
const startCandidate = computed(() => data.value !== null && data.value.active_course === null && data.value.open_session_id === null
  && data.value.due_count === 0)
/**
 * `/today` says the learner surely has no assignment of a center (`assignments_possible: false`): nothing to wait for. Absent (an older
 * server) or true: the list may still come.
 */
const noAssignments = computed(() => data.value?.assignments_possible === false)
/** Only the assignments of a group can still turn the start card off, so only then is the screen held back for them (a failed load counts as loaded). */
const awaitingAssignments = computed(() => startCandidate.value && !noAssignments.value && !assignments.loaded)
const showStart = computed(() => startCandidate.value && (noAssignments.value || (assignments.loaded && assignments.items.length === 0)))
/**
 * The list of a group member failed (state 5 of the audit's first-open table): a short line with a retry, so a failure does not read as
 * an empty class; personal training stays as it is. Never for a self-study learner (`assignments_possible: false`, nothing is asked);
 * for a member and with an older server that does not send the field alike - `failed` is only set by a list request that was made and failed.
 */
const showAssignmentsError = computed(() => data.value !== null && data.value.assignments_possible !== false && assignments.failed)

/**
 * A `/today` asked elsewhere while this screen stays open (a payment, the paywall, a refused hard round) may change
 * `assignments_possible` - a learner who has just joined a group, or left it: the list follows the new word. Synchronous, so it runs while
 * `ownLoad` still tells this screen's own `/today` apart (that one is followed in `load()`); an unchanged value runs nothing, and a list
 * request already on its way is not repeated.
 */
watch(() => data.value?.assignments_possible, (possible) => {
  if (ownLoad || data.value === null) return
  assignments.followToday(possible, assignments.loading)
}, { flush: 'sync' })

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
const sessionHint = computed(() => {
  if (needsCourseToStart.value || needsOtherCourse.value) return t('today.new.no_course')
  const current = data.value
  if (current?.active_course && current.due_count > 0 && current.new_words_remaining !== 0 && !courseHasNoNewWords.value) {
    return t('today.session.hint')
  }
  return ''
})
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
/** The games row of the list "For today": with the plan's card the tile "Word games" under the goal is that entry. */
const showGamesRow = computed(() => showGames.value && heroStep.value === null)
/** The names of the modes of the games row («Пары · Сочетания · Предложение»): the row says what is inside in words. */
const gamesModes = computed(() => GAMES_CARD_MODES.map((mode) => modeTitle(mode)).join(' · '))
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

/**
 * The round of hard words (docs/06 §14), a row of the list "For today": only when there is something to do - a round that is open
 * ("Continue") or one that can be started ("Review"). Played today, or refused for any other reason: no row, the learner is not
 * teased with what is not there. `hard_round` null (the feature is off) or absent (an older server): none either.
 */
const hardRound = computed(() => {
  const round = data.value?.hard_round
  if (!hasProgress.value || round == null) return null
  const resuming = round.open_session_id != null
  return resuming || round.available ? { resuming, words: round.words } : null
})

/**
 * The caravan of the class that is on (docs/05 §5b.7), a row of the list: by `/today.game.class_caravan`, no other request. The teacher
 * starts it for the whole group, so a newcomer of the center sees it too. The deadline is in the learner's zone (`/today.timezone`).
 */
const classCaravan = computed(() => {
  const caravan = data.value?.game?.class_caravan
  if (!caravan) return null
  const until = t('gameClass.until', { when: formatDate(caravan.ends_at, 'weekdayTime', locale.value, data.value?.timezone) })
  const counts = { stars: caravan.stars_total, target: caravan.target_stars, until }
  return { to: `/game/class/${caravan.id}`, text: t('gameClass.card.text', counts), aria: t('gameClass.card.aria', counts) }
})

/**
 * The friends' cup that runs (docs/33 §7.1), a row of the list: "Cup «Group» · Day 3 of 7" and the state of today's round as the server
 * has it (`GET /cups` → `me.today.state`, one light request, `stores/cups`): not started - "play the round of the day" with "Play"; on -
 * "continue the round"; played - "round played", no call, and the row goes to the end of the list (`late`); no round for him today -
 * the day only, no call. An older server without `me.today` - the day and "Open". A switched off cup shows nothing.
 */
const cupCard = computed((): (CupTodayCard & { late: boolean }) | null => {
  const cup = cups.running
  if (cup === null || cup.day === null) return null
  const counts = { day: cup.day, days: cup.days }
  const base = { to: { name: 'cup', params: { id: cup.id } }, title: t('today.cup.title', { group: cup.group_title }) }
  const state = cup.me?.today?.state
  switch (state) {
    case 'not_started':
      return { ...base, state, text: t('today.cup.play', counts), action: t('today.cup.go'), dot: 'bg-warning', late: false }
    case 'active':
      return { ...base, state, text: t('today.cup.continue', counts), action: t('today.cup.go_continue'), dot: 'bg-warning', late: false }
    case 'done':
      return { ...base, state, text: t('today.cup.played', counts), action: null, dot: 'bg-success', late: true }
    case 'not_available':
      return { ...base, state, text: t('today.cup.day', counts), action: null, dot: 'bg-track', late: true }
    default:
      return { ...base, state: 'unknown', text: t('today.cup.day', counts), action: t('today.cup.open'), dot: 'bg-accent', late: false }
  }
})

/** The list "For today" has a row to show (otherwise there is no card at all). */
const showTodayList = computed(() => assignmentItems.value.length > 0 || hardRound.value !== null || classCaravan.value !== null || cupCard.value !== null
  || (hasProgress.value && (gameEntry.value !== null || showGameMap.value)) || showGamesRow.value)

/** The status dot of the streak: amber while it is at risk, accent while it lives, the track when there is none. */
const streakDot = computed(() => {
  const current = streak.value
  if (!current || current.current === 0) return 'bg-track'
  return current.at_risk ? 'bg-warning' : 'bg-accent'
})

const lessonLabel = computed(() => {
  const lesson = data.value?.active_course?.next_lesson
  if (!lesson) return ''
  const number = t('course.lesson', { number: lesson.lesson })
  return lesson.title ? `${number} · ${lesson.title}` : number
})

const coursePercent = computed(() => {
  const progress = data.value?.active_course?.progress
  return progress && progress.total > 0 ? (progress.learned / progress.total) * 100 : 0
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
      points: tn('league.score', league.points),
    }),
  }
})

/**
 * The plan of the day (docs/04 A.2a `plan`, docs/31 §5б) picks the big card: step 1 - the next lesson, 2 - the review of the lesson of the day,
 * 3 - the timed pairs on its words, `done` - the chain is complete. A step whose data is missing, `step: null` with nothing done (no course,
 * the course is finished) and an older server without `plan` keep the previous card with the session button (`heroStep` null).
 */
const plan = computed(() => data.value?.plan ?? null)
const heroStep = computed((): 1 | 2 | 3 | 'done' | null => {
  const current = plan.value
  if (current === null || showStart.value) return null
  if (current.step === 1) return data.value?.active_course?.next_lesson ? 1 : null
  if (current.step === 2) return current.training !== null ? 2 : null
  // Step 3 without a way into the game (an older server that does not name the course of the lesson, and the lesson is not surely of the
  // active course): the previous card, not a step card without a button.
  if (current.step === 3) return current.game !== null && gameLink.value !== null ? 3 : null
  return current.done ? 'done' : null
})

const planSteps = computed(() => (plan.value === null ? [] : dayPlanSteps(plan.value, gameStepExpected(data.value?.practice))))
/** The lesson row says how many new words it holds: those still unseen before it, the words met in it once it is done. */
const planNotes = computed((): Partial<Record<DayPlanStepKey, string>> => {
  const current = plan.value
  if (current === null) return {}
  const words = current.lesson.done ? (current.training?.words ?? null) : current.lesson.words_left
  return words !== null && words > 0 ? { lesson: tn(planPhrases.value ? 'today.hero.new_phrases' : 'today.hero.new_words', words) } : {}
})

/** «Урок «Напитки» пройден» over the steps 2 and 3; a lesson out of the active course has no title (docs/04 A.2a). */
const lessonDoneLine = computed(() => {
  const title = plan.value?.training?.title ?? null
  return title !== null && title !== '' ? t('today.hero.lesson_done_named', { title }) : t('today.hero.lesson_done')
})

/**
 * The active course is a course of phrases (docs/35 §3.2): the lesson card and the plan say «фразы» instead of «слова». `/today` does not
 * name the section of its course, and no request is made for it: the catalog this run has already read (the list, or the open course)
 * says it; without one the card says «слова», as before.
 */
const activePhrases = computed((): boolean => {
  const slug = data.value?.active_course?.slug
  if (!slug) return false
  const row = catalog.courses.find((course) => course.slug === slug) ?? (catalog.detail?.slug === slug ? catalog.detail : null)
  return isPhraseCourse(row)
})
/**
 * The lesson of the day is of that course of phrases: step 1 is always the next lesson of the active course; after it, the lesson is surely
 * of the active course only when the server names it (`plan.training.title` is given only for such a lesson, docs/04 A.2a).
 */
const planPhrases = computed((): boolean => {
  if (!activePhrases.value) return false
  const current = plan.value
  if (current === null || !current.lesson.done) return true
  return typeof current.training?.title === 'string'
})

/** The Free quota left for today (`null`: no limit): the lesson card quotes it, and with nothing left it leads to Pro instead of the lesson. */
const quotaLeft = computed(() => data.value?.new_words_remaining ?? null)

const hero = computed((): { eyebrow: string; title: string; text: string; note: string } | null => {
  const step = heroStep.value
  const current = plan.value
  if (step === null || current === null) return null
  if (step === 1) {
    const lesson = data.value?.active_course?.next_lesson
    if (!lesson) return null
    const level = data.value?.active_course?.readiness?.level_label ?? null
    const number = t('course.lesson', { number: lesson.lesson })
    const wordsLeft = current.lesson.words_left ?? lesson.words_left ?? null
    const phrases = activePhrases.value
    const eyebrow = phrases
      ? (level ? t('today.hero.lesson_eyebrow_phrases', { level, number: lesson.lesson }) : t('today.hero.lesson_eyebrow_phrases_plain', { number: lesson.lesson }))
      : (level ? t('today.hero.lesson_eyebrow', { level, number: lesson.lesson }) : t('today.hero.lesson_eyebrow_plain', { number: lesson.lesson }))
    return {
      eyebrow,
      title: lesson.title ?? number,
      text: wordsLeft !== null && wordsLeft > 0 ? tn(phrases ? 'today.hero.new_phrases' : 'today.hero.new_words', wordsLeft) : '',
      note: quotaLeft.value === null ? '' : quotaLeft.value > 0 ? t('today.new.remaining', { count: quotaLeft.value }) : t('today.new.exhausted'),
    }
  }
  if (step === 2 && current.training) {
    const text = tn(planPhrases.value ? 'today.hero.training_text_phrases' : 'today.hero.training_text', current.training.words)
    return { eyebrow: lessonDoneLine.value, title: t('today.hero.training_title'), text, note: '' }
  }
  if (step === 3) {
    return { eyebrow: lessonDoneLine.value, title: t('today.hero.game_title'), text: t(planPhrases.value ? 'today.hero.game_text_phrases' : 'today.hero.game_text'), note: '' }
  }
  // "New lesson - tomorrow" only when it is so: the Free quota of new words is used up; else a lesson may still be taken today.
  return { eyebrow: '', title: t('today.hero.done_title'), text: t(quotaLeft.value === 0 ? 'today.hero.done_text' : 'today.hero.done_text_open'), note: '' }
})

/** The plan is done and the quota still lets a lesson bring new words: "Next lesson" under it, as a second action. */
const doneNextLesson = computed(() => heroStep.value === 'done' && quotaLeft.value !== 0 ? (data.value?.active_course?.next_lesson ?? null) : null)
/** The plan is done and a review is waiting (or open): its button on the card. */
const doneReview = computed(() => heroStep.value === 'done' && data.value !== null && (data.value.open_session_id !== null || data.value.due_count > 0))
/** "Pro" of the done card: a Free learner whose quota is used up. */
const donePro = computed(() => heroStep.value === 'done' && !isPro.value && quotaLeft.value === 0)

/** Step 1 with the Free quota used up: the lesson would open with no new word, so the card offers Pro instead (never to Pro). */
const lessonBlocked = computed(() => heroStep.value === 1 && quotaLeft.value === 0)

/** The lesson of step 1 (or "Next lesson" of the done card): a session of the plan (`source: plan`, docs/04 A.3). */
function startLesson(): Promise<void> {
  tapPrimary()
  const lessonId = data.value?.active_course?.next_lesson?.id
  return lessonId ? openSession({ mode: 'lesson', lesson_id: lessonId, source: 'plan' }) : Promise.resolve()
}

/**
 * The review of the lesson of the day (step 2): `openLessonReview` (source `plan`), then the training screen, as for a lesson. The review gives
 * no league points and does not move the goal - the card only says "fix N words". A refusal (409 `lesson_review_unavailable`) shows its reason
 * under the card (`openError`, i18n/errors.ts) and makes the store read `/today` again, so the plan on the screen is the server's new word.
 */
async function startLessonReview(): Promise<void> {
  const lessonReview = plan.value?.training
  if (!lessonReview) return
  tapPrimary()
  const sessionId = await training.openLessonReview(lessonReview.lesson_id)
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
}

/**
 * Step 3: the practice hub of the lesson, which starts the timed pairs by itself (`play`), in the course of the lesson (`plan.game.course`:
 * the lesson of the day may be of another course than the active one - an assignment, a course switched since). An older server does not
 * name it: then the active course, but only when the lesson surely is of it (`plan.training.title` is given only for such a lesson).
 */
const gameLink = computed((): RouteLocationRaw | null => {
  const game = plan.value?.game
  if (!game) return null
  const trainingTitle = plan.value?.training?.title
  const slug = game.course ?? (typeof trainingTitle === 'string' ? (data.value?.active_course?.slug ?? null) : null)
  if (!slug) return null
  return { name: 'practice-hub', params: { slug }, query: { lesson: String(game.lesson_id), play: game.mode, from: 'plan' } }
})

/** The goal of the day in the new card: «8 / 20 слов», the bar of segments (at most 20) and the streak or what is left on the right. */
const GOAL_SEGMENTS_MAX = 20
const goalSegments = computed(() => {
  const goal = data.value?.goal
  if (!goal || goal.target <= 0) return []
  const count = Math.min(goal.target, GOAL_SEGMENTS_MAX)
  const filled = goal.met ? count : Math.min(count, Math.floor((goal.done / goal.target) * count))
  return Array.from({ length: count }, (_, index) => index < filled)
})
const goalAside = computed(() => {
  const current = data.value
  if (!current) return ''
  if (current.goal.met) return t('today.goal.met')
  if (current.streak.current > 0) return tn('today.streak.short', current.streak.current)
  return tn('today.goal.more', goalLeft.value)
})

/** The tile "Review": the open daily session first, then what is due; with nothing it is a quiet tile without an action. */
const reviewTile = computed(() => {
  const current = data.value
  if (!current) return { text: '', active: false }
  if (current.open_session_id !== null) return { text: t('today.tiles.review_continue'), active: true }
  if (current.due_count > 0) return { text: tn('today.tiles.review_ready', current.due_count), active: true }
  return { text: t('today.tiles.review_none'), active: false }
})
/** The tile "Word games": while the game of the plan is still ahead it says which step it is («Шаг 3 на сегодня»). */
const gamesTileNote = computed(() => {
  const index = planSteps.value.findIndex((step) => step.key === 'game')
  const game = planSteps.value[index]
  return game && game.state !== 'done' ? t('today.tiles.games_step', { step: index + 1 }) : t('today.tiles.games_note')
})

/**
 * The primary action of the screen can be used (docs/04 A.21, `button_ready`): the screen is drawn and one of its primary buttons is
 * there and enabled - "Choose a course" of the start card or of the session card, "Start / Continue session", or a lesson of the
 * center's assignments. "All done" with no assignment has none, so that open has no such mark.
 */
const primaryReady = computed(() => {
  if (data.value === null || status.value === 'idle' || status.value === 'loading' || awaitingAssignments.value || opening.value) return false
  // The card of a step always has its button (step 1 with the Free quota used up leads to Pro, step 3 is there only with its link); the done
  // card - only when it has one of its buttons.
  if (heroStep.value === 1 || heroStep.value === 2 || heroStep.value === 3) return true
  if (heroStep.value === 'done') return doneReview.value || doneNextLesson.value !== null || donePro.value
  return showStart.value || needsCourseToStart.value || needsOtherCourse.value || !allDone.value || assignmentItems.value.length > 0
})
// After the render (`post`): the button is on the screen when the mark is taken.
watch(primaryReady, (ready) => {
  if (ready) markTiming('button_ready')
}, { flush: 'post', immediate: true })

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

    <div v-else>
      <!-- The brand row of the header carries the language pill (it opens the language sheet). -->
      <PageHeader brand large :eyebrow="eyebrow" :title="greeting">
        <template #trailing>
          <!-- A chip is 24 px high: the padding (cancelled by the margin) makes the touch target 44 px. -->
          <RouterLink :to="{ name: 'billing' }" class="-m-2.5 block p-2.5" data-testid="plan-badge">
            <span class="chip" :class="isPro ? 'chip-accent' : 'chip-neutral'">
              {{ t(isPro ? 'today.plan.pro' : 'today.plan.free') }}
            </span>
          </RouterLink>
        </template>
      </PageHeader>

      <div class="flex flex-col gap-3.5">
        <LapsedBanner
          v-if="data.entitlement.plan === 'free'"
          :lapsed="data.entitlement.lapsed"
          @renew="router.push({ name: 'billing' })"
          @invite="router.push({ name: 'referrals', query: { from: 'today' } })"
        />

        <InlineAlert v-if="status === 'error'" tone="danger" :message="loadError" data-testid="reload-error" />

        <section v-if="showStart" class="card flex flex-col items-center gap-3 py-7 text-center" data-testid="start-card">
          <span class="inline-flex size-12 items-center justify-center rounded-card bg-accent-soft text-accent"><AppIcon name="book-open" :size="26" /></span>
          <h2 class="text-[22px] leading-7 font-semibold">{{ t('today.start.title') }}</h2>
          <p class="text-base leading-[22px] text-muted">{{ t('today.start.text') }}</p>
          <RouterLink :to="{ name: 'courses' }" class="btn btn-primary btn-lg btn-block mt-1" data-testid="start-choose-course" @click="tapPrimary">
            {{ t('today.course.choose') }}
          </RouterLink>
        </section>

        <!-- Under the start card the reminder to finish the setup in the bot is a quiet line, not a second card. -->
        <div v-if="showStart && !data.onboarding_completed" class="flex items-start gap-2 px-1" data-testid="onboarding-hint">
          <AppIcon name="information-circle" :size="18" class="shrink-0 text-accent" />
          <div class="min-w-0 flex-1">
            <p class="text-[13px] leading-[18px] text-muted">{{ t('today.onboarding_hint') }}</p>
            <!-- Under the text, not beside it: on a 320 px screen the two would squeeze each other into a column of words. -->
            <button type="button" class="btn btn-plain btn-sm -ml-3 mt-1" data-testid="onboarding-btn" @click="close()">
              {{ t('today.onboarding_btn') }}
            </button>
          </div>
        </div>

        <div v-else-if="!data.onboarding_completed" class="card flex items-start gap-3" data-testid="onboarding-hint">
          <AppIcon name="information-circle" :size="20" class="mt-px shrink-0 text-accent" />
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
          class="flex min-h-11 items-center gap-3 px-1 text-[15px] leading-5"
          data-testid="trial-line"
        >
          <span class="size-2 shrink-0 rounded-full bg-accent" aria-hidden="true" />
          <span class="min-w-0 flex-1">{{ trialText }}</span>
          <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
        </RouterLink>

        <!-- The step of the plan of the day (mock-ups Main / MainNext): its big card, the plan, the goal and the two tiles. -->
        <template v-if="hero !== null">
          <LessonHero :step="String(heroStep)" :eyebrow="hero.eyebrow" :title="hero.title" :text="hero.text" :note="hero.note">
            <template v-if="heroStep === 1">
              <button
                v-if="!lessonBlocked"
                type="button"
                class="btn btn-primary btn-lg btn-block gap-2.5"
                :disabled="opening"
                data-testid="hero-btn"
                @click="startLesson"
              >
                {{ t('today.hero.lesson_start') }}
                <AppIcon name="arrow-right" :size="22" />
              </button>
              <!-- The Free quota of new words is used up: the lesson would bring none today, so the way is to Pro. -->
              <button v-else-if="!isPro" type="button" class="btn btn-tinted btn-lg btn-block" data-testid="hero-pro" @click="openPaywall('quota')">
                {{ t('today.all_done.pro') }}
              </button>
            </template>
            <button
              v-else-if="heroStep === 2"
              type="button"
              class="btn btn-primary btn-lg btn-block gap-2.5"
              :disabled="opening"
              data-testid="hero-btn"
              @click="startLessonReview"
            >
              {{ plan?.training?.open_session_id != null ? t('today.hero.continue') : t('today.hero.training_start') }}
              <AppIcon name="arrow-right" :size="22" />
            </button>
            <RouterLink
              v-else-if="heroStep === 3 && gameLink"
              :to="gameLink"
              class="btn btn-primary btn-lg btn-block gap-2.5"
              data-testid="hero-btn"
              @click="tapPrimary"
            >
              {{ t('today.hero.play') }}
              <AppIcon name="arrow-right" :size="22" />
            </RouterLink>
            <template v-else-if="heroStep === 'done'">
              <button
                v-if="doneReview"
                type="button"
                class="btn btn-tinted btn-lg btn-block"
                :disabled="opening"
                data-testid="hero-review"
                @click="review"
              >
                {{ data.open_session_id !== null ? t('today.review.continue') : tn('today.hero.review', data.due_count) }}
              </button>
              <button
                v-if="doneNextLesson"
                type="button"
                class="btn btn-lg btn-block"
                :class="doneReview ? 'btn-plain' : 'btn-tinted'"
                :disabled="opening"
                data-testid="hero-next-lesson"
                @click="startLesson"
              >
                {{ t('today.hero.next_lesson') }}
              </button>
              <p v-if="donePro" class="flex justify-center">
                <button type="button" class="btn btn-plain btn-sm" data-testid="all-done-pro" @click="openPaywall('quota')">
                  {{ t('today.all_done.pro') }}
                </button>
              </p>
            </template>
          </LessonHero>

          <InlineAlert v-if="openError" ref="openErrorBox" tone="danger" :message="openError" data-testid="open-error" />

          <DayPlan v-if="planSteps.length > 0" :steps="planSteps" :notes="planNotes" :done="plan?.done === true" />

          <!-- The goal of the day and the streak (mock-up Main): «8 / 20 слов», a bar of segments, the streak or what is left. -->
          <section class="card flex flex-col gap-3 px-4 py-3.5" data-testid="day-goal">
            <RouterLink :to="{ name: 'settings' }" class="flex min-h-11 items-center gap-3" data-testid="goal-setting">
              <AppIcon name="flag" :size="28" class="shrink-0 text-accent" />
              <span class="h-[34px] w-px shrink-0 bg-hairline" aria-hidden="true" />
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-[17px] leading-[22px] font-bold">{{ t('today.goal.title') }}<span class="sr-only"> · {{ t('today.goal.edit') }}</span></span>
                <span class="text-sm leading-5 text-muted" data-testid="day-goal-count">{{ tn('today.goal.count', data.goal.target, { done: data.goal.done }) }}</span>
              </span>
              <span
                class="shrink-0 text-right text-[13px] leading-[18px]"
                :class="data.goal.met ? 'font-semibold text-success-ink' : 'text-muted'"
                data-testid="day-goal-aside"
              >{{ goalAside }}</span>
            </RouterLink>
            <div v-if="goalSegments.length > 0" class="grid gap-[3px]" :style="{ gridTemplateColumns: `repeat(${goalSegments.length}, minmax(0, 1fr))` }" aria-hidden="true" data-testid="day-goal-bar">
              <span v-for="(filled, index) in goalSegments" :key="index" class="h-2 rounded-[3px]" :class="filled ? 'bg-success' : 'bg-track'" />
            </div>
          </section>

          <!-- "Review" and "Word games" (mock-up Main): two tinted tiles; the games tile only where the practice is open. -->
          <div class="grid gap-2.5" :class="showGames ? 'grid-cols-2' : 'grid-cols-1'" data-testid="day-tiles">
            <!-- With nothing due the tile is a plain block: a disabled button would grey its text below the contrast of the palette. -->
            <component
              :is="reviewTile.active ? 'button' : 'div'"
              :type="reviewTile.active ? 'button' : undefined"
              class="flex min-h-12 min-w-0 items-center gap-2.5 rounded-card bg-accent-soft px-3 py-3.5 text-left disabled:opacity-50"
              :disabled="reviewTile.active ? opening : undefined"
              :data-active="reviewTile.active"
              data-testid="review-tile"
              @click="reviewTile.active ? review() : undefined"
            >
              <AppIcon name="arrow-path" :size="28" class="shrink-0 text-accent" />
              <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-[15px] leading-[19px] font-bold">{{ t('today.tiles.review') }}</span>
                <span class="text-[13px] leading-[17px] text-muted" data-testid="review-tile-text">{{ reviewTile.text }}</span>
              </span>
            </component>
            <button
              v-if="showGames"
              type="button"
              class="flex min-h-12 min-w-0 items-center gap-2.5 rounded-card bg-accent-soft px-3 py-3.5 text-left"
              data-testid="games-tile"
              @click="openPractice"
            >
              <AppIcon name="puzzle-piece" :size="28" class="shrink-0 text-accent" />
              <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-[15px] leading-[19px] font-bold">{{ t('today.games.title') }}</span>
                <span class="text-[13px] leading-[17px] text-muted" data-testid="games-tile-text">{{ gamesTileNote }}</span>
              </span>
            </button>
          </div>
        </template>

        <!-- "Goal of the day" (mock-up Main): N of M, a thin line, what is waiting and the one primary button of the screen. -->
        <section v-else-if="!showStart" class="card flex flex-col gap-3.5 bg-linear-to-br from-accent-soft to-surface" data-testid="review-card">
          <div class="flex flex-col gap-2" data-testid="goal-card">
            <div class="flex items-baseline justify-between gap-3">
              <h2 class="min-w-0">
                <RouterLink :to="{ name: 'settings' }" class="inline-flex min-h-11 items-center gap-2 text-base font-semibold text-accent-ink" data-testid="goal-setting">
                  <span>{{ t('today.goal.title') }}</span>
                  <AppIcon name="pencil-square" :size="16" />
                  <span class="sr-only">{{ t('today.goal.edit') }}</span>
                </RouterLink>
              </h2>
              <span v-if="streak && streak.current > 0" class="chip chip-warning" data-testid="streak-current">
                <AppIcon name="fire" :size="15" /> {{ tn('today.streak.short', streak.current) }}
              </span>
            </div>
            <p class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
              <span class="font-mono text-[40px] leading-10 font-medium tracking-[-0.5px]" data-testid="goal-done">{{ data.goal.done }}</span>
              <span class="text-[15px] leading-5 text-muted">
                <span data-testid="goal-text">{{ tn('today.goal.of', data.goal.target) }}</span>
                <span> · </span>
                <span v-if="data.goal.met" class="font-semibold text-success-ink" data-testid="goal-met">{{ t('today.goal.met') }}</span>
                <span v-else data-testid="goal-left">{{ t('today.goal.left_short', { count: goalLeft }) }}</span>
              </span>
            </p>
            <ProgressLine
              :value="goalPercent"
              :tone="data.goal.met ? 'success' : 'accent'"
              :label="t('today.goal.progress', { done: data.goal.done, target: data.goal.target })"
              data-testid="goal-line"
            />
          </div>

          <div class="flex flex-col">
            <p class="text-sm leading-5" :class="data.due_count > 0 ? 'text-body' : 'text-muted'" data-testid="due-count">
              {{ data.due_count > 0 ? tn('today.review.due', data.due_count) : t('today.review.none') }}
            </p>
            <template v-if="!needsCourseToStart">
              <RouterLink
                v-if="!data.active_course"
                :to="{ name: 'courses' }"
                class="flex min-h-11 items-center gap-1 text-sm leading-5 text-accent"
                data-testid="new-no-course"
              >
                <span class="min-w-0 flex-1">{{ t('today.new.no_course') }}</span>
                <AppIcon name="chevron-right" :size="16" class="shrink-0" />
              </RouterLink>
              <RouterLink
                v-else-if="courseHasNoNewWords"
                :to="{ name: 'courses' }"
                class="flex min-h-11 items-center gap-1 text-sm leading-5 text-accent"
                data-testid="new-course-empty"
              >
                <span class="min-w-0 flex-1">{{ noNewWordsText }}</span>
                <AppIcon name="chevron-right" :size="16" class="shrink-0" />
              </RouterLink>
              <p v-else class="mt-0.5 text-sm leading-5 text-muted" data-testid="new-remaining">{{ newWordsText }}</p>
            </template>
          </div>

          <RouterLink
            v-if="needsCourseToStart"
            :to="{ name: 'courses' }"
            class="btn btn-primary btn-lg btn-block"
            data-testid="start-choose-course"
            @click="tapPrimary"
          >
            {{ t('today.course.choose') }}
          </RouterLink>
          <RouterLink
            v-else-if="needsOtherCourse"
            :to="{ name: 'courses' }"
            class="btn btn-primary btn-lg btn-block"
            data-testid="start-next-course"
            @click="tapPrimary"
          >
            {{ t('today.course.choose_other') }}
          </RouterLink>
          <div v-else-if="allDone" class="space-y-1" data-testid="all-done">
            <p class="flex items-center justify-center gap-2 text-center text-base leading-[22px] font-semibold">
              <AppIcon name="check-circle" solid :size="20" class="shrink-0 text-success" />
              <span>{{ t('today.all_done') }}</span>
            </p>
            <!-- The quota of the free plan is what ended the day: one quiet way to Pro. Not for a newcomer, not for Pro. -->
            <p v-if="!isPro && hasProgress" class="flex justify-center">
              <button type="button" class="btn btn-plain btn-sm" data-testid="all-done-pro" @click="openPaywall('quota')">
                {{ t('today.all_done.pro') }}
              </button>
            </p>
          </div>
          <button
            v-else
            type="button"
            class="btn btn-primary btn-lg btn-block"
            :disabled="opening"
            data-testid="review-btn"
            @click="review"
          >
            {{ data.open_session_id !== null ? t('today.review.continue') : t('today.review.start') }}
          </button>

          <InlineAlert v-if="openError" ref="openErrorBox" tone="danger" :message="openError" data-testid="open-error" />

          <p v-if="sessionHint" class="-mt-1 text-center text-[13px] leading-[18px] text-muted" data-testid="session-hint">
            {{ sessionHint }}
          </p>
        </section>

        <!-- "Today N of 100" of the word points v2 (docs/32 §8.2): only when the server sends `score_today` (v2 rules on). -->
        <ScoreTodayCard v-if="data.score_today && !showStart" :score="data.score_today" :timezone="data.timezone" />

        <!--
          "For today" (mock-up Main): what else waits today, one row each with a status dot - the assignments of the center first (they
          have a deadline), then the round of hard words, the caravans and the word games. The order of the old cards is kept otherwise.
        -->
        <section v-if="showTodayList" class="list" data-testid="today-list">
          <h2 class="px-[18px] pt-3.5 pb-2.5 text-base leading-[22px] font-semibold">{{ t('today.list.title') }}</h2>
          <div class="divide-y divide-divider border-t border-divider">
            <AssignmentsBlock v-if="assignmentItems.length > 0" :items="assignmentItems" :busy="opening" @open="openAssignment" />

            <!-- The round of hard words (docs/06 §14): the press opens it, or continues the one that is open. -->
            <div v-if="hardRound" data-testid="hard-round-card">
              <button
                type="button"
                class="flex min-h-11 w-full items-center gap-3 px-[18px] py-3 text-left active:bg-fill disabled:opacity-50"
                :disabled="opening"
                @click="startHardRound"
              >
                <span class="size-2 shrink-0 rounded-full bg-danger" aria-hidden="true" />
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span class="text-[15px] leading-5 font-semibold">{{ t('today.hard_round.title') }}</span>
                  <span v-if="hardRound.words > 0" class="text-[13px] leading-[18px] text-muted" data-testid="hard-round-words">
                    {{ tn('today.hard_round.subtitle', hardRound.words) }}
                  </span>
                </span>
                <span class="shrink-0 text-[13px] leading-[18px] text-accent" data-testid="hard-round-btn">
                  {{ hardRound.resuming ? t('today.hard_round.continue') : t('today.hard_round.start') }}
                </span>
              </button>
            </div>

            <!-- The friends' cup that runs (docs/33 §7.1): opens the cup, where the round of the day is played. Played - at the end. -->
            <CupTodayRow v-if="cupCard && !cupCard.late" :card="cupCard" />

            <!-- The caravan of the class that is on (docs/05 §5b.7). -->
            <RouterLink
              v-if="classCaravan"
              :to="classCaravan.to"
              class="flex min-h-11 items-center gap-3 px-[18px] py-3 active:bg-fill"
              :aria-label="classCaravan.aria"
              data-testid="class-caravan-card"
            >
              <span class="size-2 shrink-0 rounded-full bg-accent" aria-hidden="true" />
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-[15px] leading-5 font-semibold">{{ t('gameClass.title') }}</span>
                <span class="text-[13px] leading-[18px] break-words text-muted" data-testid="class-caravan-text">{{ classCaravan.text }}</span>
              </span>
            </RouterLink>

            <!-- The secondary entry of Karvon (docs/05 §5b.4): only when `/today.game` says there is something to do - a closed game says nothing. -->
            <RouterLink
              v-if="hasProgress && gameEntry"
              :to="gameEntry.to"
              class="flex min-h-11 items-center gap-3 px-[18px] py-3 active:bg-fill"
              :data-kind="gameEntry.kind"
              data-testid="game-entry"
            >
              <span class="size-2 shrink-0 rounded-full bg-accent" aria-hidden="true" />
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-[15px] leading-5 font-semibold" data-testid="game-entry-text">{{ gameEntry.text }}</span>
                <span class="text-[13px] leading-[18px] text-muted">{{ t('today.game.title') }}</span>
              </span>
              <span v-if="gameEntry.progress" class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="game-entry-progress">{{ gameEntry.progress }}</span>
            </RouterLink>

            <!-- The standing entry of the Karvon map (docs/05 §5b.4): while the game is open and there is no caravan of the day to show. -->
            <RouterLink
              v-if="hasProgress && showGameMap"
              :to="{ name: 'game' }"
              class="flex min-h-11 items-center gap-3 px-[18px] py-3 active:bg-fill"
              data-testid="game-map-entry"
            >
              <span class="size-2 shrink-0 rounded-full bg-accent" aria-hidden="true" />
              <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-[15px] leading-5 font-semibold">{{ t('today.game.title') }}</span>
                <span class="text-[13px] leading-[18px] text-muted">{{ t('today.game.map') }}</span>
              </span>
            </RouterLink>

            <!-- Practice of the main course, one entry (docs/16 §11.1): the modes by their names and "Play". -->
            <div v-if="showGamesRow" data-testid="games-card">
              <button
                type="button"
                class="flex min-h-11 w-full items-center gap-3 px-[18px] py-3 text-left active:bg-fill"
                @click="openPractice"
              >
                <span class="icon-tile tile-blue"><AppIcon name="puzzle-piece" :size="19" /></span>
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span class="text-[15px] leading-5 font-semibold">{{ t('today.games.title') }}</span>
                  <span class="text-[13px] leading-[18px] text-muted" data-testid="games-modes">{{ gamesModes }}</span>
                </span>
                <span class="shrink-0 text-[13px] leading-[18px] text-accent" data-testid="games-play-btn">{{ t('today.games.play') }}</span>
              </button>
            </div>

            <!-- The cup with nothing to do today (the round played, or no round for him): the last row, without a call. -->
            <CupTodayRow v-if="cupCard && cupCard.late" :card="cupCard" />
          </div>
        </section>

        <div v-if="showAssignmentsError" class="flex items-center gap-2 px-1" role="status" data-testid="assignments-error">
          <AppIcon name="exclamation-circle" :size="18" class="shrink-0 text-warning" />
          <p class="min-w-0 flex-1 text-[13px] leading-[18px] text-muted">{{ t('assignments.load_failed') }}</p>
          <button
            type="button"
            class="btn btn-plain btn-sm shrink-0"
            :disabled="assignments.loading"
            data-testid="assignments-retry"
            @click="assignments.load()"
          >
            {{ t('common.retry') }}
          </button>
        </div>

        <section v-if="!showStart" class="card flex flex-col gap-3" data-testid="course-card">
          <h2 class="text-[13px] leading-[18px] font-normal text-muted">{{ t('today.course.title') }}</h2>

          <template v-if="data.active_course">
            <div>
              <h3 class="text-base leading-[22px] font-semibold" data-testid="course-title">{{ data.active_course.title }}</h3>
              <template v-if="!courseIsEmpty">
                <ProgressLine :value="coursePercent" :label="courseProgressText" class="mt-2.5" />
                <p class="mt-1.5 text-[13px] leading-[18px] text-muted" data-testid="course-progress">{{ courseProgressText }}</p>
              </template>
            </div>

            <template v-if="data.active_course.next_lesson">
              <div class="border-t border-divider pt-3">
                <p class="text-[13px] leading-[18px] text-muted">{{ t('today.course.next') }}</p>
                <p class="text-[15px] leading-5 font-semibold" data-testid="next-lesson">{{ lessonLabel }}</p>
              </div>
              <!-- With the quota used up and nothing due, the lesson would open the same empty session as "Start session". -->
              <!-- The card of step 1 above already opens this lesson. -->
              <template v-if="!allDone && heroStep !== 1">
                <button
                  type="button"
                  class="btn btn-tinted btn-block"
                  :disabled="opening"
                  data-testid="next-lesson-btn"
                  @click="learnNext(data.active_course.next_lesson.id)"
                >
                  {{ t('today.course.learn') }}
                </button>
                <p class="-mt-1 text-center text-[13px] leading-[18px] text-muted" data-testid="next-lesson-hint">{{ t('today.course.learn_hint') }}</p>
              </template>
            </template>
            <template v-else>
              <p
                class="flex items-center gap-2 border-t border-divider pt-3 text-[15px] leading-5 text-muted"
                data-testid="course-completed"
              >
                <AppIcon v-if="!courseIsEmpty" name="check-circle" solid :size="20" class="shrink-0 text-success" />
                <span>{{ t(courseIsEmpty ? 'today.course.empty' : 'today.course.completed') }}</span>
              </p>
              <RouterLink :to="{ name: 'courses' }" class="btn btn-tinted btn-block" data-testid="course-choose-other">
                {{ t('today.course.choose_other') }}
              </RouterLink>
            </template>

            <ReadinessBlock
              v-if="readiness"
              :readiness="readiness"
              :slug="data.active_course.slug"
            />
          </template>

          <EmptyState v-else data-testid="no-course" icon="academic-cap" :text="t('today.course.none')">
            <template #action>
              <RouterLink :to="{ name: 'courses' }" class="btn btn-tinted" data-testid="choose-course">
                {{ t('today.course.choose') }}
              </RouterLink>
            </template>
          </EmptyState>
        </section>

        <!-- The streak (the place of the block "Week" of the mock-up: there is no data by days, so it says the streak and its record). -->
        <section v-if="streak && !showStart" class="card flex flex-col gap-3 py-3.5" data-testid="streak-card">
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-base leading-[22px] font-semibold">{{ t('today.streak.title') }}</h2>
            <span v-if="streak.best > streak.current" class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="streak-best">
              {{ tn('today.streak.record', streak.best) }}
            </span>
          </div>
          <div class="flex items-start gap-3">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="streakDot" aria-hidden="true" data-testid="streak-dot" />
            <div class="min-w-0 flex-1 text-[15px] leading-5">
              <p v-if="streak.at_risk" class="text-warning-ink" data-testid="streak-at-risk">
                {{ t('today.streak.at_risk') }}
              </p>
              <p v-else-if="streak.current > 0 && streak.met_today && streak.held_by === 'game'" data-testid="streak-held-by-game">
                {{ t('today.streak.held_by_game') }}
              </p>
              <p v-else-if="streak.current > 0 && streak.met_today" data-testid="streak-safe">
                {{ t('today.streak.safe') }}
              </p>
              <p v-else-if="streak.current === 0" class="text-muted" data-testid="streak-none">
                {{ t('today.streak.none') }}
              </p>
              <p v-else>{{ tn('today.streak.in_row', streak.current) }}</p>
            </div>
          </div>

          <div v-if="streak.frozen_yesterday || isPro || streak.current > 0" class="space-y-1 border-t border-divider pt-3">
            <p v-if="streak.frozen_yesterday" class="text-[15px] leading-5" data-testid="streak-frozen">
              {{ t('today.streak.frozen_yesterday') }}
            </p>
            <p v-if="isPro" class="text-[13px] leading-[18px] text-muted" data-testid="streak-freezes">
              {{ streak.freezes > 0 ? tn('today.streak.freezes', streak.freezes) : t('today.streak.freezes_none') }}
            </p>
            <div v-else-if="streak.current > 0" class="flex justify-center">
              <button type="button" class="btn btn-plain btn-sm" data-testid="streak-freeze-teaser" @click="openPaywall('streak_freeze')">
                {{ t('today.streak.freeze_teaser') }}
              </button>
            </div>
          </div>

          <div v-if="showFreezeLine" class="flex justify-center border-t border-divider pt-3">
            <button type="button" class="btn btn-plain btn-sm" data-testid="streak-freeze-line" @click="openPaywall('streak_freeze')">
              {{ t('today.streak.freeze_line') }}
            </button>
          </div>
        </section>

        <ListGroup v-if="hasProgress && leagueRow">
          <ListRow
            :to="{ name: 'league' }"
            :title="leagueRow.title"
            :subtitle="leagueRow.subtitle"
            data-testid="league-line"
          />
        </ListGroup>

        <section v-if="showInvite" class="card flex items-start gap-3" data-testid="invite-card">
          <div class="min-w-0 flex-1">
            <p class="text-[15px] leading-5 font-semibold" data-testid="invite-text">{{ t('today.invite.text') }}</p>
            <button
              type="button"
              class="-mb-3 inline-flex min-h-11 items-center text-sm leading-5 text-accent"
              data-testid="invite-more"
              @click="router.push({ name: 'referrals', query: { from: 'today' } })"
            >
              {{ t('today.invite.more') }}
            </button>
          </div>
          <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
          <button
            type="button"
            class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-muted active:bg-fill"
            :aria-label="t('today.invite.dismiss')"
            data-testid="invite-dismiss"
            @click="hideInvite()"
          >
            <AppIcon name="x-mark" :size="20" />
          </button>
        </section>
      </div>
    </div>

    <IntroSheet v-if="introOpen" @close="introOpen = false" />
    <TrialWelcomeSheet
      v-if="trialWelcomeOpen && trialEnd !== null"
      :ends-at="trialEnd"
      :timezone="data?.timezone"
      @close="trialWelcomeOpen = false"
      @more="trialWelcomeMore"
    />

    <PaywallSheet
      v-if="paywallOpen"
      :reason="paywallReason"
      @close="paywallOpen = false"
      @review="onPaywallReview"
    />
  </div>
</template>
