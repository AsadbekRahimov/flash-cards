<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import AssignmentsBlock from '@/components/AssignmentsBlock.vue'
import GoalRing from '@/components/GoalRing.vue'
import LapsedBanner from '@/components/LapsedBanner.vue'
import PaywallSheet, { type PaywallReason } from '@/components/PaywallSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineAlert from '@/components/ui/InlineAlert.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { formatDate } from '@/composables/format'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useAssignmentsStore } from '@/stores/assignments'
import { useAuthStore } from '@/stores/auth'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { errorMessage } from '@/i18n/errors'
import { useI18n } from '@/i18n'
import type { StringKey } from '@/i18n/ru'
import type { OpenSessionRequest } from '@/api/types'

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
  // review. The marker is dropped from the URL first so a reload or "back" does not reopen it.
  if (route.query.from === 'reminder' && today.data !== null) {
    await router.replace({ name: 'today', query: {} })
    await openSession({ mode: 'daily_review', source: 'reminder' })
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

const openAssignment = (lessonId: number): Promise<void> => openSession({ mode: 'lesson', lesson_id: lessonId, source: 'assignment' })

function learnNext(lessonId: number): Promise<void> {
  return openSession({ mode: 'lesson', lesson_id: lessonId, source: 'self' })
}

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

/** The i18n key of a tier; an unknown key (a renamed tier on the server) falls back to the lowest one. */
function tierKey(key: string): StringKey {
  const known: Record<string, StringKey> = {
    bronze: 'league.tier.bronze',
    silver: 'league.tier.silver',
    gold: 'league.tier.gold',
    platinum: 'league.tier.platinum',
    diamond: 'league.tier.diamond',
  }
  return known[key] ?? 'league.tier.bronze'
}

const name = computed(() => auth.learner?.first_name?.trim() ?? '')
const greeting = computed(() => (name.value !== '' ? t('today.greeting', { name: name.value }) : t('today.title')))
/** «среда, 28 октября»: the device's date and time zone, like the rest of the app. */
const dateLine = computed(() => formatDate(new Date().toISOString(), 'weekdayDayMonth', locale.value))
const isPro = computed(() => data.value?.entitlement.plan === 'pro')

const goalLeft = computed(() => (data.value ? Math.max(0, data.value.goal.target - data.value.goal.done) : 0))

/** What the second line of the session card says about new words; the quota is the server's (`null` = no limit). */
const newWordsText = computed(() => {
  const remaining = data.value?.new_words_remaining ?? null
  if (remaining === null) return t('today.new.unlimited')
  return remaining > 0 ? t('today.new.remaining', { count: remaining }) : t('today.new.exhausted')
})

const assignmentItems = computed(() => assignments.items)
const streak = computed(() => data.value?.streak ?? null)

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
const courseProgressText = computed(() => {
  const progress = data.value?.active_course?.progress
  return progress ? t('catalog.progress', { done: progress.learned, total: progress.total }) : ''
})

/** The league row: the place of the week, or an invitation while the learner is not placed. */
const leagueRow = computed(() => {
  const league = data.value?.league
  if (!league) return { title: t('today.league.join_title'), subtitle: t('today.league.join_subtitle') }

  return {
    title: t('today.league.title', { tier: t(tierKey(league.tier_key)) }),
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
onBeforeUnmount(() => training.clearOpenError())
</script>

<template>
  <div class="page" data-testid="today-view">
    <LoadingState v-if="status === 'idle' || status === 'loading'" />

    <ErrorState v-else-if="!data" :message="loadError" @retry="load" />

    <div v-else class="space-y-4">
      <PageHeader large :title="greeting" :subtitle="dateLine">
        <template #trailing>
          <!-- A chip is 24 px high: the padding (cancelled by the margin) makes the touch target 44 px. -->
          <RouterLink :to="{ name: 'billing' }" class="-m-2.5 block p-2.5" data-testid="plan-badge">
            <span class="chip" :class="isPro ? 'chip-accent' : 'chip-neutral'">
              {{ t(isPro ? 'today.plan.pro' : 'today.plan.free') }}
            </span>
          </RouterLink>
        </template>
      </PageHeader>

      <LapsedBanner v-if="data.entitlement.plan === 'free'" @renew="router.push({ name: 'billing' })" />

      <InlineAlert v-if="status === 'error'" tone="danger" :message="loadError" data-testid="reload-error" />

      <div v-if="!data.onboarding_completed" class="card flex items-start gap-3" data-testid="onboarding-hint">
        <AppIcon name="information-circle" :size="22" class="mt-px text-accent" />
        <p class="text-[15px] leading-5">{{ t('today.onboarding_hint') }}</p>
      </div>

      <section class="card" data-testid="review-card">
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

        <ul class="mt-4 border-t pt-2">
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
          <li>
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
            <div v-else class="flex min-h-11 items-center gap-3">
              <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                <AppIcon name="sparkles" :size="18" />
              </span>
              <span class="text-[15px] leading-5" data-testid="new-remaining">{{ newWordsText }}</span>
            </div>
          </li>
        </ul>

        <button
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

        <p class="mt-3 text-center text-[13px] leading-[18px] text-tg-hint" data-testid="session-hint">
          {{ t('today.session.hint') }}
        </p>

        <button
          v-if="!isPro"
          type="button"
          class="btn btn-plain btn-block mt-1"
          data-testid="paywall-open"
          @click="openPaywall(data.new_words_remaining === 0 ? 'quota' : 'info')"
        >
          {{ t('paywall.open') }}
        </button>
      </section>

      <section v-if="streak" class="card" data-testid="streak-card">
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

        <div class="mt-3 space-y-1 border-t pt-3">
          <p v-if="streak.frozen_yesterday" class="text-[15px] leading-5" data-testid="streak-frozen">
            {{ t('today.streak.frozen_yesterday') }}
          </p>
          <p v-if="isPro" class="text-[13px] leading-[18px] text-tg-hint" data-testid="streak-freezes">
            {{ streak.freezes > 0 ? tn('today.streak.freezes', streak.freezes) : t('today.streak.freezes_none') }}
          </p>
          <div v-else class="flex justify-center">
            <button type="button" class="btn btn-plain btn-sm" data-testid="streak-freeze-teaser" @click="openPaywall('streak_freeze')">
              {{ t('today.streak.freeze_teaser') }}
            </button>
          </div>
        </div>
      </section>

      <AssignmentsBlock v-if="assignmentItems.length > 0" :items="assignmentItems" :busy="opening" @open="openAssignment" />

      <section data-testid="course-card">
        <h2 class="section-label">{{ t('today.course.title') }}</h2>

        <div v-if="data.active_course" class="card space-y-3">
          <div>
            <h3 class="text-[17px] leading-[22px] font-semibold" data-testid="course-title">{{ data.active_course.title }}</h3>
            <div
              class="progress mt-2"
              role="progressbar"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-valuenow="coursePercent"
              :aria-label="courseProgressText"
            >
              <div class="progress-fill" :style="{ width: `${coursePercent}%` }" />
            </div>
            <p class="mt-1.5 text-[13px] leading-[18px] text-tg-hint" data-testid="course-progress">{{ courseProgressText }}</p>
          </div>

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
            <button
              type="button"
              class="btn btn-tinted btn-block"
              :disabled="opening"
              data-testid="next-lesson-btn"
              @click="learnNext(data.active_course.next_lesson.id)"
            >
              {{ t('today.course.learn') }}
            </button>
          </template>
          <p
            v-else
            class="flex items-center gap-2 border-t pt-3 text-[15px] leading-5 text-tg-subtitle"
            data-testid="course-completed"
          >
            <AppIcon name="check-circle" solid :size="20" class="shrink-0 text-success" />
            <span>{{ t('today.course.completed') }}</span>
          </p>
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

      <ListGroup>
        <ListRow
          :to="{ name: 'league' }"
          icon="trophy"
          tile="yellow"
          :title="leagueRow.title"
          :subtitle="leagueRow.subtitle"
          data-testid="league-line"
        />
      </ListGroup>
    </div>

    <PaywallSheet
      v-if="paywallOpen"
      :reason="paywallReason"
      @close="paywallOpen = false"
      @review="onPaywallReview"
    />
  </div>
</template>
