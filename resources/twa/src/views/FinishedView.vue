<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter, type RouteLocationRaw } from 'vue-router'
import PaywallSheet, { type PaywallReason } from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import { useAchievementsStore } from '@/stores/achievements'
import { useBillingStore } from '@/stores/billing'
import { useMonthlyRatingStore } from '@/stores/monthlyRating'
import { useTodayStore } from '@/stores/today'
import { COMBO_MIN, useTrainingStore } from '@/stores/training'
import { prefersReducedMotion } from '@/composables/motion'
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
import { useCountUp } from '@/composables/useCountUp'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useI18n } from '@/i18n'

/**
 * "Session finished" (docs/14 §6.3): an immersive screen (no tab bar) that ends a personal or group session.
 * The numbers of a personal session (cards, share of right answers, new words) and the goal and streak of the day
 * come with the server summary (`training.summary`); a group session has none, so it says only how many cards were
 * reviewed. The buttons at the bottom lead out: "One more round" (the primary one, only when the server says another round would have
 * cards: `can_continue`, docs/06 §13.2), "Play: practise the words" (a tinted secondary action: the server's `practice_entry` says
 * whether there is one and where it leads, docs/16 §11.1), "Home" (primary when there is no other round) and "Close". Under the
 * stats: what grew in the session (`stages`: new words, a stage up, learned), without the zeros - only when words climbed a stage
 * (the tile of the new words already says how many were met).
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
  return reason ? t(`finished.reason.${reason}`) : null
})

/** An empty session that a reason already explains ("nothing is due") has no numbers worth showing. */
const hasNumbers = computed(() => (summary.value?.reviewed ?? props.reviewed) > 0 || reasonLabel.value === null)

/** Share of right answers, whole percent; null while nothing was answered. */
const correctValue = computed((): number | null => {
  const current = summary.value
  return current !== null && current.reviewed > 0 ? Math.round((current.correct / current.reviewed) * 100) : null
})

// The numbers of the tiles count up once when the screen opens (not with reduced motion).
const reviewedShown = useCountUp(() => summary.value?.reviewed ?? 0)
const correctShown = useCountUp(() => correctValue.value ?? 0)
const newShown = useCountUp(() => summary.value?.new_words ?? 0)

/**
 * The title follows how the session went: by the share of right answers, and a long run of right answers lifts it to the top
 * one. Without the numbers (a group session) it is the plain "Great!".
 */
const title = computed((): string => {
  if (isEmpty.value) return t('finished.title_empty')
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
const canContinue = computed(() => props.reason === 'sprint_complete' && training.canContinue && training.mode !== 'group_lesson')

/**
 * What grew in the session, the zeros left out: "New words: 6 · Moved up: 2 · Learned: 1"; null when no word climbed a stage (or an
 * older server): with only `graduated` the line would repeat the tile of the new words.
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

/** "Best run: 12": only a run that the chip showed. */
const bestCombo = computed(() => (training.bestCombo >= COMBO_MIN ? training.bestCombo : 0))

/** Confetti, once, for a session that reviewed something (and not with reduced motion): it removes itself after its ~1.2 s. */
const CONFETTI_PIECES = 20
const CONFETTI_MS = 1500
const confetti = ref(!isEmpty.value && !prefersReducedMotion())
const confettiPieces = Array.from({ length: CONFETTI_PIECES }, (_, i) => {
  // Fixed spread (no random: the same burst every time, and a stable test): the pieces fan out and fall.
  const angle = (i / CONFETTI_PIECES) * Math.PI * 2
  const reach = 90 + ((i * 37) % 90)
  return {
    color: i % 4,
    style: {
      '--dx': `${Math.round(Math.cos(angle) * reach)}px`,
      '--dy': `${Math.round(Math.sin(angle) * reach * 0.6 + 140 + ((i * 53) % 120))}px`,
      '--rot': `${180 + ((i * 71) % 360)}deg`,
      '--delay': `${(i % 5) * 40}ms`,
    },
  }
})
let confettiTimer: ReturnType<typeof setTimeout> | null = null

const goalPercent = computed(() => {
  const current = goal.value
  if (current === null) return 0
  if (current.met) return 100
  return current.target > 0 ? Math.min(100, Math.round((current.done / current.target) * 100)) : 0
})

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
  if (confetti.value) confettiTimer = setTimeout(() => (confetti.value = false), CONFETTI_MS)
})

/**
 * The hub of the lesson that was just studied (or of the course of a daily review), opened "from the finish screen": the
 * hub then starts its sessions with `source = after_training` (docs/16 §2.5, the `source` of `practice_sessions`).
 */
const practiceEntry = computed(() => summary.value?.practice_entry ?? null)
const practiceTarget = computed((): RouteLocationRaw | null => {
  const entry = practiceEntry.value
  if (entry === null) return null
  const lesson = entry.lesson_id === null ? {} : { lesson: String(entry.lesson_id) }
  return { name: 'practice-hub', params: { slug: entry.course }, query: { ...lesson, from: 'finished' } }
})
/** Only after a round that did something: an empty one has nothing to practise. */
const showPractice = computed(() => practiceTarget.value !== null && !isEmpty.value)

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

/** "Repeat words" in the paywall: closes it and starts a daily review; a failure is shown on this screen. */
const { reviewFromPaywall } = useReviewFromPaywall(() => {
  paywallOpen.value = false
})

/**
 * An empty `nothing_due` session means there was nowhere to take new words from (no course, an empty or finished one): the way out
 * is the catalog. Only for a personal session: a group lesson of the teacher has nothing to choose.
 */
const showCourses = computed(() => isEmpty.value && props.reason === 'nothing_due' && training.mode !== 'group_lesson')

notificationHaptic('success')
if (!isEmpty.value) playSound('done')
if (quotaExhausted.value) void billing.load()

// A failed "Repeat words" is shown here only: Today (where "Home" leads) shows the same `openError` for its own button.
onBeforeUnmount(() => {
  if (confettiTimer !== null) clearTimeout(confettiTimer)
  training.clearOpenError()
})
</script>

<template>
  <div class="page" data-testid="finished-view">
    <div v-if="confetti" class="confetti" aria-hidden="true" data-testid="confetti">
      <span v-for="(piece, i) in confettiPieces" :key="i" class="confetti-piece" :class="`confetti-${piece.color}`" :style="piece.style" />
    </div>
    <div class="flex flex-1 flex-col justify-center gap-5 py-4">
      <header class="text-center">
        <span
          class="mx-auto flex size-20 items-center justify-center rounded-full bg-success-soft text-success"
          data-testid="finished-icon"
        >
          <AppIcon name="check-circle" solid :size="48" />
        </span>
        <h1 class="mt-4 text-[28px] leading-[34px] font-bold tracking-tight">{{ title }}</h1>
        <p v-if="reasonLabel" class="mt-1 text-[15px] leading-5 text-tg-subtitle" data-testid="reason">{{ reasonLabel }}</p>
      </header>

      <div v-if="summary && hasNumbers" class="grid grid-cols-3 gap-2" data-testid="stats">
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-reviewed">{{ reviewedShown }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.cards') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-correct">{{ correctValue === null ? '—' : `${correctShown}%` }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.correct') }}</p>
        </div>
        <div class="card px-2 py-3 text-center">
          <p class="text-2xl leading-8 font-bold" data-testid="stat-new">{{ newShown }}</p>
          <p class="mt-0.5 text-[13px] leading-[18px] text-tg-hint">{{ t('finished.stats.new') }}</p>
        </div>
      </div>
      <p
        v-else-if="!summary && hasNumbers"
        class="card text-center text-[17px] leading-[22px] font-semibold"
        data-testid="reviewed"
      >
        {{ tn('finished.reviewed', reviewed) }}
      </p>
      <p v-if="stagesLine" class="card text-center text-[15px] leading-5 text-tg-subtitle" data-testid="stages">{{ stagesLine }}</p>

      <p v-if="bestCombo > 0" class="card flex items-center gap-3 text-[17px] leading-[22px] font-semibold" data-testid="best-combo">
        <span class="icon-tile tile-orange"><AppIcon name="fire" solid :size="18" /></span>
        {{ t('finished.best_combo', { count: bestCombo }) }}
      </p>

      <RouterLink
        v-if="newAchievement"
        :to="{ name: 'achievements' }"
        class="card flex items-center gap-3"
        data-testid="new-achievement"
      >
        <span class="icon-tile tile-blue"><AppIcon name="trophy" solid :size="18" /></span>
        <span class="min-w-0 flex-1 text-[17px] leading-[22px] font-semibold break-words">{{ t('finished.new_achievement', { title: newAchievement.title }) }}</span>
        <AppIcon name="chevron-right" :size="16" class="shrink-0 text-tg-hint" />
      </RouterLink>

      <section v-if="showQuotaCard" class="card space-y-3" data-testid="quota-card">
        <p class="text-[17px] leading-[22px] font-semibold">{{ t('finished.quota.title') }}</p>
        <div class="flex flex-col gap-2">
          <button type="button" class="btn btn-tinted btn-block" data-testid="paywall-open" @click="openPaywall('quota')">
            {{ t('finished.quota.pro') }}
          </button>
          <RouterLink :to="{ name: 'referrals' }" class="btn btn-secondary btn-block" data-testid="quota-invite">
            {{ t('finished.quota.invite') }}
          </RouterLink>
        </div>
      </section>

      <section v-if="pointsEarned > 0 || monthLine" class="card space-y-2">
        <p v-if="pointsEarned > 0" class="flex items-center gap-3 text-[17px] leading-[22px] font-semibold" data-testid="points-earned">
          <span class="icon-tile tile-blue"><AppIcon name="trophy" solid :size="18" /></span>
          {{ tn('finished.points_earned', pointsEarned) }}
        </p>
        <RouterLink v-if="monthLine" :to="{ name: 'league-month' }" class="block text-[15px] leading-5 text-accent" data-testid="month-line">
          {{ monthLine }}
        </RouterLink>
      </section>

      <section v-if="goal" class="card" data-testid="goal">
        <div class="flex items-center justify-between gap-3">
          <p
            class="text-[17px] leading-[22px] font-semibold"
            :class="{ 'text-success-ink': goal.met }"
            data-testid="goal-text"
          >
            {{ goal.met ? t('finished.goal_met') : t('finished.goal', { done: goal.done, target: goal.target }) }}
          </p>
          <AppIcon v-if="goal.met" name="check-circle" solid :size="22" class="shrink-0 text-success" />
        </div>
        <div
          class="progress mt-3"
          :class="{ 'progress-success': goal.met }"
          role="progressbar"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="goalPercent"
          :aria-label="t('today.goal.title')"
        >
          <div class="progress-fill" :style="{ width: `${goalPercent}%` }" />
        </div>

        <div v-if="streak && (streak.current > 0 || streak.at_risk)" class="mt-3 flex items-start gap-3 border-t pt-3">
          <span class="icon-tile tile-orange"><AppIcon name="fire" solid :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p v-if="streak.current > 0" class="text-[15px] leading-[30px] font-semibold" data-testid="streak">
              {{ tn('today.streak.in_row', streak.current) }}
            </p>
            <p v-if="streak.current > 0 && streak.met_today && streak.held_by === 'game'" class="text-[13px] leading-[18px] text-tg-subtitle" data-testid="streak-held-by-game">
              {{ t('today.streak.held_by_game') }}
            </p>
            <p v-if="streak.at_risk" class="text-[15px] leading-5 text-warning-ink" data-testid="streak-at-risk">
              {{ t('today.streak.at_risk') }}
            </p>
          </div>
        </div>
      </section>

      <section v-if="!isEmpty" class="card flex items-center gap-3" data-testid="next">
        <span class="icon-tile tile-blue"><AppIcon name="clock" :size="18" /></span>
        <div>
          <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('finished.next_label') }}</p>
          <p class="text-[17px] leading-[22px] font-semibold" data-testid="next-label">{{ nextLabel }}</p>
        </div>
      </section>
    </div>

    <div class="space-y-2 pt-4">
      <InlineAlert v-if="training.openError" tone="danger" :message="training.openError" data-testid="open-error" />

      <template v-if="canContinue">
        <button type="button" class="btn btn-primary btn-lg btn-block" :disabled="training.opening" data-testid="next-round-btn" @click="nextRound">
          <AppIcon name="play" solid :size="20" />
          {{ t('finished.next_round') }}
        </button>
      </template>
      <!-- A secondary action: playing with what was just learned (docs/15 §4). -->
      <RouterLink v-if="showPractice && practiceTarget" :to="practiceTarget" class="btn btn-tinted btn-block" data-testid="practice-btn">
        <AppIcon name="puzzle-piece" :size="20" />
        {{ t('finished.practice') }}
      </RouterLink>
      <RouterLink :to="{ name: 'today' }" class="btn btn-lg btn-block" :class="canContinue ? 'btn-secondary' : 'btn-primary'" data-testid="home-btn">
        {{ t('finished.home') }}
      </RouterLink>
      <RouterLink v-if="showCourses" :to="{ name: 'courses' }" class="btn btn-tinted btn-block" data-testid="courses-btn">
        {{ t('finished.choose_course') }}
      </RouterLink>
      <button type="button" class="btn btn-plain btn-block" data-testid="close-btn" @click="close()">
        {{ t('common.exit_app') }}
      </button>

      <!-- Reminders about Pro: under the buttons, so that "One more round" stays on the first screen. One at a time. -->
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
          <span class="icon-tile tile-blue"><AppIcon name="sparkles" solid :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] leading-[18px] text-tg-hint">{{ t('protip.title') }}</p>
            <p class="text-[15px] leading-5 font-semibold" data-testid="pro-tip-text">{{ tipText }}</p>
          </div>
          <!-- 44 px target; the negative margins keep the glyph in the corner of the card padding. -->
          <button
            type="button"
            class="-mt-3 -mr-3 flex size-11 shrink-0 items-center justify-center rounded-full text-tg-hint active:bg-track"
            :aria-label="t('today.invite.dismiss')"
            data-testid="pro-tip-close"
            @click="hideTip()"
          >
            <AppIcon name="x-mark" :size="20" />
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
