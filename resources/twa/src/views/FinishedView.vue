<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter, type RouteLocationRaw } from 'vue-router'
import PaywallSheet, { type PaywallReason } from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useAchievementsStore } from '@/stores/achievements'
import { useBillingStore } from '@/stores/billing'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { useTodayStore } from '@/stores/today'
import { COMBO_MIN, useTrainingStore } from '@/stores/training'
import { reportOfferViewed } from '@/composables/offerViewed'
import { resolveBenefits } from '@/composables/proBenefits'
import {
  FINISHED_BEFORE_KEY,
  LAPSED_DISMISSED_KEY,
  PRO_TIP_DAY_KEY,
  PRO_TIP_KEY,
  PRO_TIP_SNOOZE_MS,
  TIP_SLOTS,
  TIP_TEXTS,
  TRIAL_ENDING_DAYS,
  TRIAL_ENDING_DAY_KEY,
  advanceTurn,
  dayFree,
  markDay,
  readTurn,
} from '@/composables/proReminders'
import { hasSeen, isSnoozed, markSeen, snooze } from '@/composables/seenFlag'
import { trialDaysLeft, trialEndsAt } from '@/composables/trial'
import { playSound } from '@/composables/useFeedbackSound'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'
import { modeTitle } from '@/composables/practiceLabels'
import { dayPlanStepPosition, gameStepExpected } from '@/composables/dayPlan'
import type { DayPlan } from '@/api/types'

/**
 * "Session finished" (docs/14 §6.3): an immersive screen (no tab bar) that ends a personal or group session.
 * The numbers of a personal session (cards, share of right answers, new words) and the goal and streak of the day
 * come with the server summary (`training.summary`); a group session has none, so it says only how many cards were
 * reviewed. The look is the calm one of docs/design Finish (redesign 2026-11): no confetti, no count-up, no icon of success.
 * The buttons at the bottom lead out: "Done" (the one primary button, to Today), then the other ways as lines of text: "One more
 * round" (only when the server says another round would have cards: `can_continue`, docs/06 §13.2), "Play: practise the words"
 * (the server's `practice_entry` says whether there is one and where it leads, docs/16 §11.1) and "Close". Under the
 * stats: what grew in the session (`stages`: new words, a stage up, learned), without the zeros - only when words climbed a stage
 * (the number of the new words already says how many were met).
 *
 * The round of hard words (docs/06 §14) has its own finish: the headline is "You won X of N hard words" (by `hard_round` of the summary, or by
 * the progress of the store when the summary did not come), the sound only for a win of all or of 80 % and more, no shame
 * for less ("these words will come back - that is the plan"); no "One more round" and no practice. After an ordinary round the server may
 * add an invitation (`hard_round_offer`): one line of text among the actions, before the practice and the reminders. Under the note of the
 * round: one mistake of an earlier session corrected in it (`corrected`), when the server found a trustworthy pair.
 *
 * The day plan "lesson -> training -> game" (docs/31 §5б, docs/design/2026-11-25-redesign Finish). The finish of a LESSON says "Lesson done!"
 * with the lesson and its new words under it, and a block "What next": step 2 - "Start the training" (the review of the words of this lesson,
 * `lesson_review`, the one main button; only when the lesson gave new words), then a line "Or play: timed pairs" that opens the hub of the
 * lesson with the game started at once (`?play=pairs_timed`), and the way home as text. Whether the review can open is the plan's word: after the
 * finish of such a lesson `/today` is read again (best effort, the screen does not wait), and a plan that has no review for this lesson
 * (`plan.training` null - the review is switched off - or done) takes the card away; without that word (an older server, a failed read) the
 * card stays, and a refusal (409 `lesson_review_unavailable`) quietly takes it away with a neutral line instead of a red error (any other
 * failure is told above the buttons). Either way the screen falls back to the ordinary buttons. "Step N of M" is the place of the step among
 * the steps of the plan (`dayPlanStepPosition`; "recommended now" without a plan at hand). The finish of the REVIEW itself (`summary.lesson_review`) says
 * "Words reinforced" with its numbers and no points (it brings none, nor goal), and offers step 3 - "Play". Every other session (a daily review,
 * the round of hard words, a group lesson, an empty or early end) keeps its own actions.
 */
const props = defineProps<{
  reviewed: number
  nextInDays: number
  /** `done_reason` of the last answer (docs/04 A.3); unknown values show no extra line. */
  reason?: string | null
}>()

const REASONS = ['lesson_complete', 'nothing_due', 'new_quota_exhausted', 'session_cap', 'sprint_complete'] as const

const router = useRouter()
const { close, notificationHaptic } = useTelegram()
const { t, tn } = useI18n()
const training = useTrainingStore()
const monthly = useMonthlyRatingStore()
const achievements = useAchievementsStore()
const billing = useBillingStore()
const today = useTodayStore()

// Goal and streak of the day come with the server summary of a personal session (absent for group sessions).
const summary = computed(() => training.summary)
const goal = computed(() => summary.value?.goal ?? null)
const streak = computed(() => summary.value?.streak ?? null)

/** Nothing was reviewed: no praise, no "next review" (nothing was scheduled) and no paywall that pops up by itself. */
const isEmpty = computed(() => (summary.value?.reviewed ?? props.reviewed) === 0)

const nextLabel = computed(() =>
  props.nextInDays <= 0 ? t('finished.next_today') : tn('finished.next_in_days', props.nextInDays),
)

const reasonLabel = computed(() => {
  const reason = REASONS.find((r) => r === props.reason)
  // The quota card says the same, and says what to do next.
  if (reason === 'new_quota_exhausted' && showQuotaCard.value) return null
  // "Lesson done!" says it already.
  if (reason === 'lesson_complete' && isLessonFinish.value) return null
  return reason ? t(`finished.reason.${reason}`) : null
})

/** An empty session that a reason already explains ("nothing is due") has no numbers worth showing. */
const hasNumbers = computed(() => (summary.value?.reviewed ?? props.reviewed) > 0 || reasonLabel.value === null)

/** Share of right answers, whole percent; null while nothing was answered. */
const correctValue = computed((): number | null => {
  const current = summary.value
  return current !== null && current.reviewed > 0 ? Math.round((current.correct / current.reviewed) * 100) : null
})

/** The round of hard words: by the reason of the last answer, the summary, or the mode of the session this run opened. */
const isHardRound = computed(() => props.reason === 'hard_round_complete' || summary.value?.hard_round != null || training.mode === 'hard_review')
/** Words of the set and how many of them were won; the progress of the store (`done` = won) when the finish summary did not come. */
const hardTotal = computed(() => summary.value?.hard_round?.total ?? training.progress.total)
const hardWon = computed(() => Math.min(summary.value?.hard_round?.won ?? training.progress.done, hardTotal.value))
/** A win of all the words or of 80 % and more gets the sound of the finish; less is told calmly. */
const hardCelebrate = computed(() => isHardRound.value && hardTotal.value > 0 && hardWon.value >= hardTotal.value * 0.8)
const hardNote = computed((): string | null => {
  if (!isHardRound.value || hardTotal.value <= 0) return null
  return t(hardWon.value >= hardTotal.value ? 'finished.hard_round.all' : 'finished.hard_round.later')
})
/**
 * One mistake of an earlier session corrected in the round (`corrected` of the summary, docs/06 §14): "Earlier you got «X» (its
 * translation, muted) wrong - today you answered it right", and under it, muted, what was typed then when the server sends it. Nothing at all without it: the summary of the
 * round is in hand before this screen opens (`finalize` at the last card), so the line never pops in.
 */
const corrected = computed(() => (isHardRound.value ? (summary.value?.corrected ?? null) : null))
/** The translation of the word in the learner's language, muted after the word; nothing when the server has none. */
const correctedTranslation = computed(() => (corrected.value?.translation ?? '').trim())

/**
 * The title follows how the session went: by the share of right answers, and a long run of right answers lifts it to the top
 * one. Without the numbers (a group session) it is the plain "Great!".
 */
const title = computed((): string => {
  if (isHardRound.value && hardTotal.value > 0) {
    return hardWon.value > 0
      ? t('finished.hard_round.title', { won: hardWon.value, total: hardTotal.value })
      : t('finished.hard_round.title_none')
  }
  if (isEmpty.value) return t('finished.title_empty')
  if (isLessonReview.value) return t('finished.lesson_review.title')
  if (isLessonFinish.value) return t('finished.lesson.title')
  const percent = correctValue.value
  if (percent === null || percent >= 80 || training.bestCombo >= 10) return t('finished.title')
  if (percent >= 60) return t('finished.title_good')
  if (percent >= 40) return t('finished.title_keep')
  return t('finished.title_effort')
})

/**
 * "One more round" (docs/06 §13.2): the server said another round would have cards (`can_continue`, only with `sprint_complete`) and
 * the session is the learner's own. The finish screen is also reached by a reload, with nothing in the store: then there is no button.
 */
const canContinue = computed(() => props.reason === 'sprint_complete' && training.canContinue && training.mode !== 'group_lesson' && !isHardRound.value)

/**
 * What grew in the session, the zeros left out: "New words: 6 · Moved up: 2 · Learned: 1"; null when no word climbed a stage (or an
 * older server): with only `graduated` the line would repeat the number of the new words.
 */
const stagesLine = computed((): string | null => {
  const stages = summary.value?.stages
  if (!stages || (stages.promoted <= 0 && stages.mastered <= 0)) return null
  const parts = [
    stages.graduated > 0 ? t('finished.stages.graduated', { count: stages.graduated }) : '',
    stages.promoted > 0 ? t('finished.stages.promoted', { count: stages.promoted }) : '',
    stages.mastered > 0 ? t('finished.stages.mastered', { count: stages.mastered }) : '',
  ].filter((part) => part !== '')
  return parts.length > 0 ? parts.join(' · ') : null
})

/** The finish of this session (if it did not go through) and a new daily review; a failure stays on this screen, the button is free again. */
async function nextRound(): Promise<void> {
  const sessionId = await training.nextRound()
  if (sessionId !== null) await router.replace({ name: 'training', params: { sessionId } })
}

/** The review of a lesson studied today (docs/06 §15): by the reason of the last answer, the summary, or the mode this run opened. */
const isLessonReview = computed(() => !isHardRound.value
  && (props.reason === 'lesson_review_complete' || summary.value?.lesson_review != null || training.mode === 'lesson_review'))
const lessonReview = computed(() => summary.value?.lesson_review ?? null)
/** The review shows the mode by its name, as its header on the training screen does: «Тренировка · Напитки». */
const reviewSubtitle = computed(() => (lessonTitle.value !== ''
  ? t('training.lesson_review_header', { lesson: lessonTitle.value })
  : t('training.lesson_review_header_plain')))

/** The lesson of the session: where the practice entry leads, else what this run opened (a learner without practice has no entry). */
const lessonId = computed((): number | null => summary.value?.practice_entry?.lesson_id ?? training.restartRequest?.lesson_id ?? null)
/** The finish of a lesson (not of a daily review, a group lesson, a round of hard words or the review of a lesson). */
const isLessonFinish = computed(() => summary.value !== null && !isHardRound.value && !isLessonReview.value && lessonId.value !== null
  && (training.mode === 'lesson' || training.mode === null))
/** The name of the lesson ("Drinks"), when the session knows it. */
const lessonTitle = computed(() => (training.lesson?.title ?? '').trim())
const newWords = computed(() => summary.value?.new_words ?? 0)

/** The header with the green mark (docs/design 2026-11-25 Finish): the lesson and its review, when something was done in them. */
const planHeader = computed(() => (isLessonFinish.value || isLessonReview.value) && !isEmpty.value)
const planSubtitle = computed((): string => {
  if (isLessonReview.value) return reviewSubtitle.value
  const parts = [lessonTitle.value, newWords.value > 0 ? tn('finished.lesson.new_words', newWords.value) : '']
  return parts.filter((part) => part !== '').join(' · ')
})

/** "Start the training" was refused: the card goes, the ordinary buttons come back. */
const reviewRefused = ref(false)
/** The neutral line of a 409 `lesson_review_unavailable` (why the review cannot open), in place of the card; '' otherwise. */
const reviewNotice = ref('')
/**
 * `/today.plan` read after this finish: undefined while it is not (the read is on its way, failed, or the server has no plan) - the card
 * then trusts the summary.
 */
const planAfterFinish = ref<DayPlan | undefined>(undefined)
/** The plan says the review of this lesson can open (it is on and not done yet); true while the plan is not known. */
const reviewOpenByPlan = computed(() => {
  const plan = planAfterFinish.value
  if (plan === undefined) return true
  const review = plan.training
  return review !== null && review.lesson_id === lessonId.value && !review.done
})
/** Step 2: the lesson gave new words today, this run knows the lesson, and the plan does not say the review is off. */
const showTraining = computed(() => isLessonFinish.value && !isEmpty.value && newWords.value > 0 && lessonId.value !== null && !reviewRefused.value
  && reviewOpenByPlan.value)
async function startLessonReview(): Promise<void> {
  const id = lessonId.value
  if (id === null) return
  const sessionId = await training.openLessonReview(id, 'finish')
  if (sessionId !== null) {
    await router.replace({ name: 'training', params: { sessionId } })
    return
  }
  reviewRefused.value = true
  if (training.openErrorCode === 'lesson_review_unavailable') {
    // Not an error of the learner's: the review is not there for this lesson today. Said quietly where the card was.
    reviewNotice.value = training.openError
    training.clearOpenError()
  }
}

/** After the finish of a lesson with new words: the plan of the day read again, so the card of step 2 matches the server. */
async function readPlanAfterFinish(): Promise<void> {
  await today.load()
  const plan = today.status === 'ready' ? today.data?.plan : undefined
  planAfterFinish.value = plan ?? undefined
}

/** "Step 2 of 3 · recommended now": the place of the step among those of the plan at hand. */
function stepEyebrow(key: 'training' | 'game'): string {
  const position = dayPlanStepPosition(today.data?.plan, gameStepExpected(today.data?.practice), key)
  return position === null ? t('finished.next.now') : t('finished.next.step', position)
}

/** "Best run: 12": only a run that the chip showed. */
const bestCombo = computed(() => (training.bestCombo >= COMBO_MIN ? training.bestCombo : 0))

const goalPercent = computed(() => {
  const current = goal.value
  if (current === null) return 0
  if (current.met) return 100
  return current.target > 0 ? Math.min(100, Math.round((current.done / current.target) * 100)) : 0
})

/** The segments of the goal (one per word, as in the mock-up) when there are not too many of them for a phone; else one line. */
const GOAL_SEGMENTS_MAX = 30
const goalSegments = computed((): boolean[] | null => {
  const current = goal.value
  if (current === null || current.target <= 0 || current.target > GOAL_SEGMENTS_MAX) return null
  const filled = current.met ? current.target : Math.min(current.done, current.target)
  return Array.from({ length: current.target }, (_, i) => i < filled)
})
const goalLeft = computed(() => (goal.value === null || goal.value.met ? 0 : Math.max(0, goal.value.target - goal.value.done)))
/** The review of a lesson moves no goal: its finish does not show one. */
const showGoal = computed(() => goal.value !== null && !isLessonReview.value)

/** League points of this session (docs/13 §7.6): shown only when the server counted some. */
const pointsEarned = computed(() => summary.value?.league_points ?? 0)

/**
 * "In the month's rating: 12th place · 340 points": a light request after the screen is shown (it never blocks it; a failure
 * leaves no line). Only for a session that reviewed something — a group or an empty one earns nothing.
 */
const monthMe = computed(() => monthly.mine)
const monthLine = computed(() => {
  const mine = monthMe.value
  if (mine === null || !mine.enabled || mine.me === null || mine.me.rank === null) return null
  return t('finished.month_line', { rank: mine.me.rank, points: tn('month.points', mine.me.points) })
})

function parseStart(value: string | undefined): number | null {
  const at = value === undefined ? NaN : Date.parse(value)
  return Number.isFinite(at) ? at : null
}

/**
 * The achievement that came during this session (docs/04 A.15): until now it went to the chat of the bot only. One light request
 * after the screen is shown (it never blocks it, a failure leaves no card); the newest unlocked one that is not older than the
 * start of the session. Both are the server's time: `started_at` of the finish summary (or of the start answer, kept in the store); the
 * device's clock is only the fallback of an older server that sends none.
 */
const newAchievement = computed(() => {
  const since = parseStart(summary.value?.started_at) ?? training.startedAt
  if (since <= 0) return null
  let found: { key: string; title: string; at: number } | null = null
  for (const item of achievements.data?.items ?? []) {
    const at = item.unlocked_at === null ? NaN : Date.parse(item.unlocked_at)
    if (Number.isFinite(at) && at >= since && (found === null || at > found.at)) found = { key: item.key, title: item.title, at }
  }
  return found
})

onMounted(() => {
  monthly.mine = null
  if (summary.value !== null && !isEmpty.value) {
    void monthly.loadMine()
    void achievements.load()
  }
  if (showTraining.value) void readPlanAfterFinish()
})

/**
 * The hub of the lesson that was just studied (or of the course of a daily review), opened "from the finish screen": the
 * hub then starts its sessions with `source = after_training` (docs/16 §2.5, the `source` of `practice_sessions`).
 */
const practiceEntry = computed(() => summary.value?.practice_entry ?? null)
/**
 * "Review N hard words": the invitation of the server after an ordinary round (`hard_round_offer.available`); never at the end of the round
 * itself. It opens the round with `source = finish`; a refusal (played in another tab) stays on this screen as the text above the buttons
 * and the line goes away.
 */
const hardOffer = computed(() => summary.value?.hard_round_offer ?? null)
const offerFailed = ref(false)
const showHardOffer = computed(() => !isHardRound.value && !offerFailed.value && hardOffer.value !== null && hardOffer.value.available && hardOffer.value.words > 0)
async function startHardRound(): Promise<void> {
  const sessionId = await training.open({ mode: 'hard_review', source: 'finish' })
  if (sessionId === null) offerFailed.value = true
  else await router.replace({ name: 'training', params: { sessionId } })
}

/**
 * Step 3: the hub of the lesson that opens the timed pairs on its words at once (`?play=pairs_timed`); only where the server gives an entry to
 * the practice of a lesson (a short lesson is topped up by the server, the hub tells a refusal).
 */
const gameTarget = computed((): RouteLocationRaw | null => {
  const entry = practiceEntry.value
  if (entry === null || entry.lesson_id === null) return null
  return { name: 'practice-hub', params: { slug: entry.course }, query: { lesson: String(entry.lesson_id), play: 'pairs_timed', from: 'finished' } }
})
/** "Or play: timed pairs" under the training of a lesson; "Play" as the main button after the review. */
const showGameLine = computed(() => isLessonFinish.value && !isEmpty.value && gameTarget.value !== null)
const showPlayHero = computed(() => isLessonReview.value && !isEmpty.value && gameTarget.value !== null)
/** The block "What next" has something in it; then the way home is a line of text and "Done" is gone. */
const showWhatNext = computed(() => showTraining.value || showGameLine.value || showPlayHero.value || reviewNotice.value !== '')
const heroMain = computed(() => showTraining.value || showPlayHero.value)

const practiceTarget = computed((): RouteLocationRaw | null => {
  const entry = practiceEntry.value
  if (entry === null) return null
  const lesson = entry.lesson_id === null ? {} : { lesson: String(entry.lesson_id) }
  return { name: 'practice-hub', params: { slug: entry.course }, query: { ...lesson, from: 'finished' } }
})
/** Only after a round that did something: an empty one has nothing to practise. A lesson and its review offer the game instead. */
const showPractice = computed(() => practiceTarget.value !== null && !isEmpty.value && !isLessonFinish.value && !isLessonReview.value)

/**
 * The end of today's new words (docs/12 §5.4): on Free a calm card says so and offers the two ways on — what Pro gives (the
 * sheet opens only when the learner asks for it) and the invitations. Nothing pops up by itself. Pro (paid or the welcome
 * trial) has no such card: its plan is read from `/today` if it is at hand, and from `/me` (asked once, only for this reason).
 */
const quotaExhausted = computed(() => props.reason === 'new_quota_exhausted')
const paywallOpen = ref(false)
/** Why the sheet was opened: the end of the new words, or the learner asked what Pro gives (the tip, the trial card). */
const paywallReason = ref<PaywallReason>('quota')
function openPaywall(reason: PaywallReason): void {
  paywallReason.value = reason
  paywallOpen.value = true
}
const plan = computed(() => billing.entitlement?.plan ?? today.data?.entitlement?.plan ?? null)
const showQuotaCard = computed(() => quotaExhausted.value && plan.value === 'free')

/**
 * Reminders about Pro (docs/14, "Напоминания о Pro"). Only here, in the pause after a round, and only by what the learner has just done: a
 * personal session that reviewed something, with the plan known from `/me` (asked once, best effort; the screen never waits for it).
 * Nothing pops up; every button is the learner's own press. No more than one of these three at a time; the terms are in `proReminders.ts`.
 *
 * - the tip (`pro-tip`): Free only; one benefit at a time, round and round (the turn is kept on the device); not in the first session of the
 *   device nor of a learner whose streak says they studied before (the first impression is not an advertisement); not more than once in a
 *   local day; not with the card of the end of the new words (which already speaks of Pro) nor while the "trial ended" banner of Today
 *   is still there; the cross hides it for 3 days;
 * - the end of the trial (`trial-ending`): a trial with 2 days or less left, once in a local day.
 *
 * The first button is the way that works: while payment is not open (`paywall.mode = coming_soon`) it is the invitation of friends and "What Pro
 * gives" is the second one; with payment open it is the other way round. The cards stand under the buttons: above them they would push
 * "One more round" off the first screen of a phone.
 */
const reminderZone = today.data?.timezone ?? null
const finishedBefore = hasSeen(FINISHED_BEFORE_KEY)
if (!isEmpty.value) markSeen(FINISHED_BEFORE_KEY)
const tipDayFree = dayFree(PRO_TIP_DAY_KEY, reminderZone)
const endingDayFree = dayFree(TRIAL_ENDING_DAY_KEY, reminderZone)
const tipTurn = readTurn()
const tipHidden = ref(isSnoozed(PRO_TIP_KEY))

const remindable = computed(() => !isEmpty.value && summary.value !== null && training.mode !== 'group_lesson' && billing.entitlement !== null)
const paymentOpen = computed(() => billing.paywall?.mode === 'purchase')
/** The way that works goes first (and is the tinted one): the invitation while payment is not open, "What Pro gives" once it is. */
const reminderActions = computed((): ('details' | 'invite')[] => (paymentOpen.value ? ['details', 'invite'] : ['invite', 'details']))

/** The learner studied before: this device finished a session, or the streak has more than the one day of today. */
const studiedBefore = computed(() => finishedBefore || (summary.value?.streak?.best ?? 0) > 1)

/** "Pro ended" of a trial is on Today until it is closed there: the tip does not come on top of it. */
function lapsedBannerStands(): boolean {
  const lapsed = billing.entitlement?.lapsed ?? today.data?.entitlement.lapsed ?? null
  if (lapsed === null || lapsed.provider !== 'trial') return false
  try {
    return window.localStorage.getItem(LAPSED_DISMISSED_KEY) !== lapsed.ended_at
  } catch {
    return true
  }
}

const tipText = computed((): string | null => {
  const keys = resolveBenefits(TIP_SLOTS, billing.practiceAccess.pro_promise, billing.gameAccess.pro_promise)
    .map((key) => TIP_TEXTS[key])
    .filter((key): key is NonNullable<typeof key> => key !== undefined)
  const key = keys[tipTurn % Math.max(1, keys.length)]
  return key === undefined ? null : t(key)
})
const showTip = computed(() => remindable.value && billing.entitlement?.plan === 'free' && studiedBefore.value && tipDayFree && !tipHidden.value
  && !showQuotaCard.value && !lapsedBannerStands() && tipText.value !== null)
function hideTip(): void {
  tipHidden.value = true
  snooze(PRO_TIP_KEY, PRO_TIP_SNOOZE_MS)
}

const trialEnd = computed(() => trialEndsAt(billing.entitlement))
const trialDays = computed(() => (trialEnd.value === null ? 0 : trialDaysLeft(trialEnd.value)))
const showTrialEnding = computed(() => remindable.value && trialEnd.value !== null && trialDays.value <= TRIAL_ENDING_DAYS && endingDayFree)
const trialEndingText = computed(() => {
  const days = trialDays.value
  const lead = days <= 1 ? t('finished.trial_ending.last') : t('finished.trial_ending.days', { count: days })
  return `${lead} ${t(paymentOpen.value ? 'finished.trial_ending.keep' : 'finished.trial_ending.keep_invite')}`
})

/**
 * The payment mode, the end of the trial and what Pro gives this learner are `/me`: asked (best effort, never blocking) only when a reminder could
 * come out of it by what `/today` already says - a Free learner who studied before with today's tip not yet used, or a Pro with an end (the
 * trial can be one: only `/me` tells) with today's trial card not yet used.
 */
const mayRemind = computed(() => {
  if (isEmpty.value || summary.value === null || training.mode === 'group_lesson') return false
  const entitlement = today.data?.entitlement
  if (entitlement === undefined) return false
  if (entitlement.plan === 'free') return studiedBefore.value && tipDayFree && !tipHidden.value
  return entitlement.source === 'subscription' && entitlement.until !== null && endingDayFree
})
watch(mayRemind, (on) => {
  if (on) void billing.load()
}, { immediate: true })

// Counted the moment the card is on screen, not before: a card that did not show is not "once in a day" spent.
watch(showTip, (on, was) => {
  if (!on || was) return
  markDay(PRO_TIP_DAY_KEY, reminderZone)
  advanceTurn()
}, { immediate: true })
watch(showTrialEnding, (on, was) => {
  if (on && !was) markDay(TRIAL_ENDING_DAY_KEY, reminderZone)
}, { immediate: true })
// The tip and the trial card offer Pro: the first step of the Pro funnel (docs/13 §5.1), quiet while nothing is for sale.
watch([showTip, showTrialEnding], ([tip, ending]) => { if (ending) reportOfferViewed('finished_trial_ending'); else if (tip) reportOfferViewed('finished_pro_tip') }, { immediate: true })

/** "Repeat words" in the paywall: closes it and starts a daily review; a failure is shown on this screen. */
const { reviewFromPaywall } = useReviewFromPaywall(() => {
  paywallOpen.value = false
})

/**
 * An empty `nothing_due` session means there was nowhere to take new words from (no course, an empty or finished one): the way out
 * is the catalog. Only for a personal session: a group lesson of the teacher has nothing to choose.
 */
const showCourses = computed(() => isEmpty.value && props.reason === 'nothing_due' && training.mode !== 'group_lesson')

/** The streak has something to say: a run of days, or the warning that it is about to break. */
const showStreak = computed(() => streak.value !== null && (streak.value.current > 0 || streak.value.at_risk))
/** The card of rows under the numbers (docs/design Finish) has at least one row. */
const hasRows = computed(() => corrected.value !== null || showGoal.value || showStreak.value || bestCombo.value > 0
  || newAchievement.value !== null || (pointsEarned.value > 0 && !isLessonFinish.value) || monthLine.value !== null || showNext.value)
/** "Next review": the review of a lesson moves no schedule, so it has none. */
const showNext = computed(() => !isEmpty.value && !isLessonReview.value)

notificationHaptic('success')
if (!isEmpty.value && (!isHardRound.value || hardCelebrate.value)) playSound('done')
if (quotaExhausted.value) void billing.load()

// A failed "Repeat words" is shown here only: Today (where "Home" leads) shows the same `openError` for its own button.
onBeforeUnmount(() => {
  training.clearOpenError()
})
</script>

<template>
  <!--
    docs/design/2026-11-25-redesign Finish: on the grey page — the title (with the green mark after a lesson and its review, else an eyebrow
    over it), the numbers in one card with lines between them, one card of rows (a mistake put right, the goal and the streak, the month, the
    next review), "What next" of the day plan, then the main button and the other actions as text under it.
  -->
  <div class="page" :class="planHeader ? 'pt-[30px]' : 'pt-16 short:pt-6'" data-testid="finished-view">
    <header v-if="planHeader" class="flex items-center gap-3.5" data-testid="plan-header">
      <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-success text-on-primary" aria-hidden="true">
        <AppIcon name="check" :size="30" />
      </span>
      <div class="flex min-w-0 flex-col gap-0.5">
        <h1 class="text-[28px] leading-[34px] font-extrabold tracking-[-0.5px] break-words">{{ title }}</h1>
        <p v-if="planSubtitle" class="text-[15px] leading-5 text-muted break-words" data-testid="plan-subtitle">{{ planSubtitle }}</p>
        <p v-if="reasonLabel" class="text-[15px] leading-5 text-muted" data-testid="reason">{{ reasonLabel }}</p>
      </div>
    </header>
    <header v-else class="flex flex-col gap-2">
      <p class="text-[13px] leading-[18px]" :class="isEmpty ? 'text-muted' : 'text-success-ink'" data-testid="eyebrow">
        {{ isHardRound ? t('training.hard_round.chip') : t('finished.eyebrow') }}
      </p>
      <h1
        class="text-[30px] leading-9 font-extrabold tracking-[-0.5px] short:text-[26px] short:leading-[30px]"
        :data-testid="isHardRound ? 'hard-round-title' : undefined"
      >
        {{ title }}
      </h1>
      <p v-if="hardNote" class="text-[15px] leading-5 text-muted" data-testid="hard-round-note">{{ hardNote }}</p>
      <p v-else-if="reasonLabel" class="text-[15px] leading-5 text-muted" data-testid="reason">{{ reasonLabel }}</p>
    </header>

    <!-- The review of a lesson: the words of its set reinforced, and how many at the first try. No points, no new words: it brings none. -->
    <div
      v-if="summary && hasNumbers && isLessonReview && lessonReview"
      class="mt-[18px] grid grid-cols-2 rounded-card border border-hairline bg-surface"
      data-testid="stats"
    >
      <div class="flex min-w-0 flex-col gap-0.5 px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-won">{{ t('finished.stats.of', { done: lessonReview.won, total: lessonReview.total }) }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.lesson_review.won') }}</p>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5 border-l border-divider px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-first-try">{{ lessonReview.first_try }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.lesson_review.first_try') }}</p>
      </div>
    </div>
    <!-- A lesson (docs/design 2026-11-25 Finish): right answers of all, the new words, and the points of the league when there are some. -->
    <div
      v-else-if="summary && hasNumbers && isLessonFinish"
      class="mt-[18px] grid rounded-card border border-hairline bg-surface"
      :class="pointsEarned > 0 ? 'grid-cols-3' : 'grid-cols-2'"
      data-testid="stats"
    >
      <div class="flex min-w-0 flex-col gap-0.5 px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-correct">{{ t('finished.stats.of', { done: summary.correct, total: summary.reviewed }) }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.correct_answers') }}</p>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5 border-l border-divider px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-new">{{ summary.new_words > 0 ? `+${summary.new_words}` : summary.new_words }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.new_words') }}</p>
      </div>
      <div v-if="pointsEarned > 0" class="flex min-w-0 flex-col gap-0.5 border-l border-divider px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="points-earned">+{{ pointsEarned }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.league_points') }}</p>
      </div>
    </div>
    <!-- Any other personal session: the cards, the share of right answers and the new words; the points are a row below. -->
    <div
      v-else-if="summary && hasNumbers"
      class="mt-[18px] grid grid-cols-3 rounded-card border border-hairline bg-surface"
      data-testid="stats"
    >
      <div class="flex min-w-0 flex-col gap-0.5 px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-reviewed">{{ summary.reviewed }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.cards') }}</p>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5 border-l border-divider px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-correct">{{ correctValue === null ? '—' : `${correctValue}%` }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.correct') }}</p>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5 border-l border-divider px-3 py-3.5">
        <p class="text-2xl leading-7 font-extrabold" data-testid="stat-new">{{ summary.new_words }}</p>
        <p class="text-[13px] leading-[18px] text-muted">{{ t('finished.stats.new') }}</p>
      </div>
    </div>
    <p
      v-else-if="!summary && hasNumbers"
      class="card mt-7 text-[17px] leading-[22px] font-semibold short:mt-5"
      data-testid="reviewed"
    >
      {{ tn('finished.reviewed', reviewed) }}
    </p>
    <p v-if="stagesLine" class="mt-2 px-1 text-[13px] leading-[18px] text-muted" data-testid="stages">{{ stagesLine }}</p>
    <p v-if="isLessonReview && summary" class="mt-2 px-1 text-[13px] leading-[18px] text-muted" data-testid="lesson-review-note">{{ t('finished.lesson_review.note') }}</p>

    <section
      v-if="hasRows"
      class="mt-2.5 flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-hairline bg-surface"
      data-testid="rows"
    >
      <div v-if="corrected" class="flex items-start gap-3 px-[18px] py-3.5" data-testid="hard-round-corrected">
        <AppIcon name="check" :size="18" class="mt-0.5 shrink-0 text-success" />
        <div class="min-w-0 flex-1">
          <!-- The sentence in two keys around the word, so the translation can follow the word as a muted suffix (Uzbek puts more after it). -->
          <p class="text-[15px] leading-5 break-words" data-testid="hard-round-corrected-line">{{ t('finished.corrected.lead', { word: corrected.word }) }}<span v-if="correctedTranslation" class="text-muted" data-testid="hard-round-corrected-translation"> ({{ correctedTranslation }})</span> {{ t('finished.corrected.tail') }}</p>
          <p v-if="corrected.answer" class="mt-0.5 text-[13px] leading-[18px] text-muted break-words" data-testid="hard-round-corrected-answer">
            {{ t('finished.corrected.answer', { answer: corrected.answer }) }}
          </p>
        </div>
      </div>

      <div v-if="goal && showGoal" class="flex flex-col gap-2.5 px-4 py-3.5" data-testid="goal">
        <div class="flex items-baseline justify-between gap-3">
          <p class="text-base leading-5 font-bold" :class="{ 'text-success-ink': goal.met }" data-testid="goal-text">
            {{ goal.met ? t('finished.goal_met') : t('finished.goal_line', { done: goal.done, target: goal.target }) }}
          </p>
          <AppIcon v-if="goal.met" name="check" :size="18" class="shrink-0 self-center text-success" />
          <p v-else-if="goalLeft > 0" class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="goal-left">{{ t('finished.goal_left', { count: goalLeft }) }}</p>
        </div>
        <!-- One segment per word of the goal (as in the mock-up); a long goal is one line. -->
        <div
          v-if="goalSegments"
          class="grid gap-[3px]"
          :style="{ gridTemplateColumns: `repeat(${goalSegments.length}, minmax(0, 1fr))` }"
          aria-hidden="true"
          data-testid="goal-segments"
        >
          <span v-for="(filled, i) in goalSegments" :key="i" class="h-2 rounded-[3px]" :class="filled ? 'bg-success' : 'bg-track'" />
        </div>
        <ProgressLine v-else :value="goalPercent" :tone="goal.met ? 'success' : 'accent'" :label="t('today.goal.title')" />
      </div>

      <div v-if="streak && showStreak" class="px-[18px] py-3.5">
        <p v-if="streak.current > 0" class="text-[15px] leading-5 font-semibold" data-testid="streak">
          {{ tn('today.streak.in_row', streak.current) }}
        </p>
        <p v-if="streak.current > 0 && streak.met_today && streak.held_by === 'game'" class="mt-0.5 text-[13px] leading-[18px] text-muted" data-testid="streak-held-by-game">
          {{ t('today.streak.held_by_game') }}
        </p>
        <p v-if="streak.at_risk" class="text-[13px] leading-[18px] text-warning-ink" :class="{ 'mt-0.5': streak.current > 0 }" data-testid="streak-at-risk">
          {{ t('today.streak.at_risk') }}
        </p>
      </div>

      <p v-if="bestCombo > 0" class="px-[18px] py-3.5 text-[15px] leading-5 font-semibold" data-testid="best-combo">
        {{ t('finished.best_combo', { count: bestCombo }) }}
      </p>

      <RouterLink
        v-if="newAchievement"
        :to="{ name: 'achievements' }"
        class="flex min-h-[52px] items-center gap-3 px-[18px] py-3.5 active:bg-fill"
        data-testid="new-achievement"
      >
        <span class="min-w-0 flex-1 text-[15px] leading-5 font-semibold break-words">{{ t('finished.new_achievement', { title: newAchievement.title }) }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
      </RouterLink>

      <div v-if="pointsEarned > 0 && !isLessonFinish" class="flex items-baseline justify-between gap-3 px-[18px] py-3.5">
        <p class="text-[15px] leading-5 font-semibold">{{ t('league.title') }}</p>
        <p class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="points-earned">{{ tn('finished.points_earned', pointsEarned) }}</p>
      </div>
      <RouterLink
        v-if="monthLine"
        :to="{ name: 'league-month' }"
        class="flex min-h-[52px] items-center gap-3 px-[18px] py-3.5 active:bg-fill"
        data-testid="month-line"
      >
        <span class="min-w-0 flex-1 text-[15px] leading-5 text-accent">{{ monthLine }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
      </RouterLink>

      <div v-if="showNext" class="flex items-baseline justify-between gap-3 px-[18px] py-3.5" data-testid="next">
        <p class="text-[15px] leading-5 font-semibold">{{ t('finished.next_label') }}</p>
        <p class="shrink-0 text-[13px] leading-[18px] text-muted" data-testid="next-label">{{ nextLabel }}</p>
      </div>
    </section>

    <section v-if="showQuotaCard" class="card mt-3.5 space-y-3" data-testid="quota-card">
      <p class="text-[15px] leading-5 font-semibold">{{ t('finished.quota.title') }}</p>
      <div class="flex flex-col gap-2">
        <button type="button" class="btn btn-tinted btn-block" data-testid="paywall-open" @click="openPaywall('quota')">
          {{ t('finished.quota.pro') }}
        </button>
        <RouterLink :to="{ name: 'referrals' }" class="btn btn-secondary btn-block" data-testid="quota-invite">
          {{ t('finished.quota.invite') }}
        </RouterLink>
      </div>
    </section>

    <!-- "What next" of the day plan (docs/31 §5б): step 2 (the training of the lesson) or step 3 (the game) as the big card, the game as a row. -->
    <section v-if="showWhatNext" class="mt-[22px] flex flex-col gap-2.5" data-testid="what-next">
      <h2 class="text-xl leading-6 font-extrabold tracking-[-0.3px]">{{ t('finished.next.title') }}</h2>

      <div v-if="showTraining" class="flex flex-col gap-3 rounded-hero bg-highlight p-4" data-testid="training-card">
        <div class="flex flex-col gap-1">
          <p class="text-[13px] leading-[18px] font-bold text-accent" data-testid="training-step">{{ stepEyebrow('training') }}</p>
          <p class="text-[22px] leading-7 font-extrabold tracking-[-0.4px]">{{ t('finished.next.training_title') }}</p>
          <p class="text-sm leading-5">{{ t('finished.next.training_text') }} {{ tn('finished.lesson.new_words', newWords) }}.</p>
        </div>
        <button
          type="button"
          class="btn btn-primary btn-lg btn-block"
          :disabled="training.opening"
          data-testid="lesson-review-btn"
          @click="startLessonReview"
        >
          {{ t('finished.next.training_start') }}
          <AppIcon name="arrow-right" :size="22" />
        </button>
      </div>

      <p v-if="reviewNotice" class="text-[15px] leading-5 text-muted" role="status" data-testid="training-unavailable">{{ reviewNotice }}</p>

      <div v-if="showPlayHero && gameTarget" class="flex flex-col gap-3 rounded-hero bg-highlight p-4" data-testid="game-card">
        <div class="flex flex-col gap-1">
          <p class="text-[13px] leading-[18px] font-bold text-accent" data-testid="game-step">{{ stepEyebrow('game') }}</p>
          <p class="text-[22px] leading-7 font-extrabold tracking-[-0.4px]">{{ modeTitle('pairs_timed') }}</p>
          <p class="text-sm leading-5">{{ t('finished.next.game_text') }}</p>
        </div>
        <RouterLink :to="gameTarget" class="btn btn-primary btn-lg btn-block" data-testid="game-play-btn">
          {{ t('finished.next.play') }}
          <AppIcon name="arrow-right" :size="22" />
        </RouterLink>
      </div>

      <RouterLink
        v-if="showGameLine && gameTarget"
        :to="gameTarget"
        class="flex min-h-12 items-center gap-3 rounded-card border border-hairline bg-surface px-3.5 py-3 active:bg-fill"
        data-testid="game-line"
      >
        <AppIcon name="puzzle-piece" :size="28" class="shrink-0 text-accent" />
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="text-base leading-5 font-bold">{{ t('finished.next.game_line') }}</span>
          <span class="text-[13px] leading-[18px] text-muted">{{ t('finished.next.game_sub') }}</span>
        </span>
        <AppIcon name="chevron-right" :size="20" class="shrink-0" />
      </RouterLink>
    </section>

    <!-- One main button: the step of "What next", else "Done"; every other way on is a line of text under it (docs/design Finish). -->
    <div class="mt-auto flex flex-col gap-1.5 pt-6">
      <InlineAlert v-if="training.openError" tone="danger" :message="training.openError" data-testid="open-error" />

      <RouterLink v-if="heroMain" :to="{ name: 'today' }" class="btn btn-plain btn-block text-base" data-testid="home-btn">
        {{ t('finished.home') }}
      </RouterLink>
      <RouterLink v-else :to="{ name: 'today' }" class="btn btn-primary btn-lg btn-block" data-testid="home-btn">
        {{ t('finished.done') }}
      </RouterLink>
      <button
        v-if="canContinue"
        type="button"
        class="btn btn-plain btn-block text-[15px]"
        :disabled="training.opening"
        data-testid="next-round-btn"
        @click="nextRound"
      >
        {{ t('finished.next_round') }}
      </button>
      <!-- The round of hard words, offered by the server after an ordinary round: one line, before the practice and the reminders. -->
      <button
        v-if="showHardOffer && hardOffer"
        type="button"
        class="btn btn-plain btn-block text-[15px]"
        :disabled="training.opening"
        data-testid="hard-round-offer-btn"
        @click="startHardRound"
      >
        {{ tn('finished.hard_round.offer', hardOffer.words) }}
      </button>
      <!-- Playing with what was just learned (docs/15 §4). -->
      <RouterLink v-if="showPractice && practiceTarget" :to="practiceTarget" class="btn btn-plain btn-block text-[15px]" data-testid="practice-btn">
        {{ t('finished.practice') }}
      </RouterLink>
      <RouterLink v-if="showCourses" :to="{ name: 'courses' }" class="btn btn-plain btn-block text-[15px]" data-testid="courses-btn">
        {{ t('finished.choose_course') }}
      </RouterLink>
      <button type="button" class="btn btn-plain btn-block text-[15px] text-muted" data-testid="close-btn" @click="close()">
        {{ t('common.exit_app') }}
      </button>

      <!-- Reminders about Pro: under the buttons, so that "Done" stays on the first screen. One at a time. -->
      <section v-if="showTrialEnding" class="card mt-3 space-y-3" data-testid="trial-ending">
        <p class="text-[15px] leading-5 font-semibold">{{ trialEndingText }}</p>
        <div class="flex flex-col gap-2" data-testid="trial-ending-actions">
          <template v-for="action in reminderActions" :key="action">
            <button
              v-if="action === 'details'"
              type="button"
              class="btn btn-block"
              :class="paymentOpen ? 'btn-tinted' : 'btn-secondary'"
              data-testid="trial-ending-pro"
              @click="openPaywall('info')"
            >
              {{ t(paymentOpen ? 'lapsed.trial.renew' : 'protip.more') }}
            </button>
            <RouterLink v-else :to="{ name: 'referrals' }" class="btn btn-block" :class="paymentOpen ? 'btn-secondary' : 'btn-tinted'" data-testid="trial-ending-invite">
              {{ t('lapsed.invite') }}
            </RouterLink>
          </template>
        </div>
      </section>
      <section v-else-if="showTip" class="card mt-3 space-y-3" data-testid="pro-tip">
        <div class="flex items-start gap-3">
          <div class="min-w-0 flex-1">
            <p class="text-[13px] leading-[18px] text-muted">{{ t('protip.title') }}</p>
            <p class="text-[15px] leading-5 font-semibold" data-testid="pro-tip-text">{{ tipText }}</p>
          </div>
          <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
          <button
            type="button"
            class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-muted active:bg-fill"
            :aria-label="t('today.invite.dismiss')"
            data-testid="pro-tip-close"
            @click="hideTip()"
          >
            <AppIcon name="x-mark" :size="18" />
          </button>
        </div>
        <div class="flex flex-col gap-2" data-testid="pro-tip-actions">
          <template v-for="action in reminderActions" :key="action">
            <button
              v-if="action === 'details'"
              type="button"
              class="btn btn-block"
              :class="paymentOpen ? 'btn-tinted' : 'btn-secondary'"
              data-testid="pro-tip-details"
              @click="openPaywall('info')"
            >
              {{ t('protip.more') }}
            </button>
            <RouterLink v-else :to="{ name: 'referrals' }" class="btn btn-block" :class="paymentOpen ? 'btn-secondary' : 'btn-tinted'" data-testid="pro-tip-invite">
              {{ t('protip.invite') }}
            </RouterLink>
          </template>
        </div>
      </section>
    </div>

    <PaywallSheet v-if="paywallOpen" :reason="paywallReason" @close="paywallOpen = false" @review="reviewFromPaywall" />
  </div>
</template>
