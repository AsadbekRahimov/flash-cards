<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import PaywallSheet from '@/components/PaywallSheet.vue'
import ActiveSessionSheet from '@/components/practice/ActiveSessionSheet.vue'
import FriendPickerSheet from '@/components/practice/FriendPickerSheet.vue'
import ModeCard from '@/components/practice/ModeCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ListGroup from '@/components/ui/ListGroup.vue'
import ListRow from '@/components/ui/ListRow.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ProgressLine from '@/components/ui/ProgressLine.vue'
import { progressPercent } from '@/composables/courseLabels'
import { showErrorToast } from '@/composables/errorToast'
import { dayPlanStepPosition, gameStepExpected } from '@/composables/dayPlan'
import { estimateMinutes, isKnownPracticeMode, lessonHeader, modeTitle } from '@/composables/practiceLabels'
import { useBackButton } from '@/composables/useBackButton'
import { useReviewFromPaywall } from '@/composables/useReviewFromPaywall'
import { useTelegram } from '@/composables/useTelegram'
import { useAuthStore } from '@/stores/auth'
import { useBattleStore } from '@/stores/battle'
import { useBillingStore } from '@/stores/billing'
import { useFriendsStore } from '@/stores/friends'
import { usePracticeStore } from '@/stores/practice'
import { useTodayStore } from '@/stores/today'
import { useTrainingStore } from '@/stores/training'
import { useI18n } from '@/i18n'
import type { BattleIncoming, FriendItem, PracticeActiveSession, PracticeMode, PracticeModeInfo, PracticeSource } from '@/api/types'

/**
 * "Word games": the practice hub of a course or of one lesson (docs/16 §11.2, docs/14, docs/design/2026-11-25-redesign Practice): the link
 * back to Today, the heading "Word games" with the place (the lesson or the course) above it and the number of learned words under it (from
 * `/today` when its course is this one: the hub does not count them), the "Continue" card of the unfinished session, the card of step 3 of
 * the day plan on the hub of a lesson ("Timed pairs on the words of the lesson", the one main button unless "Continue" has it), and one
 * card "All games": a row per mode, then "Caravan of the day" (when the game is open to the learner, `/me`) and the battle with a friend.
 * The mock-up's "online" of a friend has no data behind it and is not shown.
 *
 * Step 3 of the day plan (docs/31 §5б): `?lesson=<id>&play=pairs_timed` opens the timed pairs on the words of the lesson at once (`source =
 * deeplink`, the button of the finish screen and of Today) and takes `play` out of the address first, so that "Back" from the game does not
 * start it again. The hub of a short lesson shows the mode as short of words (`no_words`), but the server tops a short lesson up with learned
 * words of the course when the game is opened: the step card and the start by the address do not trust that state and ask the server; a
 * refusal (422 no material, 403 the limit of Free or Pro only, 409 an unfinished session) leaves the learner on the hub, told as any other.
 *
 * States: loading, error (Retry), "practice is not available yet" (`practice_unavailable`), the hub. A card is playable
 * (the whole card is the button), short of material ("Learn the lesson"), switched off, or behind Pro (the paywall).
 * The one unfinished session of a learner: a mode pressed while there is one asks what to do (`ActiveSessionSheet`).
 * A mode that lacks only recordings (the dictation without audio files) is not shown. "Back" (the system button and the link above the
 * heading) leads to "Today" when the hub was opened from the day plan - `?play=`, `?from=finished` / `plan` / `lesson_nudge` (the finish
 * screen, "Today", the bot's reminder) - and to the course otherwise (the hub opened from the course screen). A hub opened from the finish
 * screen of a training session (`?from=finished`, the button "Practice of the lesson / of the course") starts its sessions with
 * `source = after_training` instead of `hub`.
 *
 * The card of the timed pairs of a lesson says "Step N of M" (N, M - `dayPlanStepPosition` over `/today.plan`; "recommended now" without
 * a plan at hand) only when it is the step of the plan: the hub came from the plan (as above) or its lesson is `plan.game.lesson_id`. On the
 * hub of an older lesson opened from the course it is the plain card of the game.
 *
 * Free has a few sessions of practice a day (docs/18 §3): under "Choose a mode" a quiet line says how many are left ("Free today: 1 of 2
 * left"; Pro sees nothing). The cards stay as they are when none is left - a press opens the paywall of the limit at once, without a request
 * (`practice.open` checks the counter) - except the "Continue" card and the card of the mode that is unfinished: going back into a
 * session that was already counted costs nothing. "Play with a friend" starts a battle and meets the counter of battles the same way.
 */
const props = withDefaults(
  defineProps<{
    slug: string
    /** The lesson of the hub (`?lesson=`); null: the whole course. */
    lessonId: number | null
    /**
     * `?from=finished`: the hub was opened from the finish screen of a training session (its sessions are "after training"); `plan`: from the
     * plan of "Today"; `lesson_nudge`: from the bot's reminder after a lesson.
     */
    from?: 'finished' | 'plan' | 'lesson_nudge' | null
    /** `?play=pairs_timed`: open the game on the words of the lesson at once (only with `lessonId`). */
    play?: 'pairs_timed' | null
  }>(),
  { from: null, play: null },
)

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const practice = usePracticeStore()
const battle = useBattleStore()
const billing = useBillingStore()
const friends = useFriendsStore()
const training = useTrainingStore()
const today = useTodayStore()
const { haptic } = useTelegram()
const { t, tn } = useI18n()

const { hub, hubStatus, hubError, opening, openError, conflict, paywall, limitHit } = storeToRefs(practice)
const { sessionLimit } = storeToRefs(billing)

/**
 * Opened from the day plan (the finish screen, "Today", the bot): `play` is taken out of the address by the autoplay, so it is read once,
 * when the screen is set up; `from` stays in the address.
 */
const openedWithPlay = props.play !== null
const fromPlan = computed(() => openedWithPlay || props.from !== null)
/** Where "Back" leads: "Today" for a hub of the plan, the course otherwise. */
const backTarget = computed(() => (fromPlan.value ? { name: 'today' } : { name: 'course', params: { slug: props.slug } }))

useBackButton(() => {
  const target = backTarget.value
  if (router.options.history.state.back === router.resolve(target).fullPath) router.back()
  else void router.push(target)
})

/** Set when the learner leaves: an answer that comes back after it must not carry them anywhere. */
let left = false

/** The mode the learner pressed, for "Start a new one" after the unfinished session has been offered. */
const pressed = ref<PracticeMode | null>(null)
/** The paywall opened from a card that is behind Pro (the server's 403 sets `practice.paywall` for the same sheet). */
const proSheet = ref(false)
const pickerOpen = ref(false)

const showPaywall = computed(() => proSheet.value || paywall.value || limitHit.value !== null)

function closePaywall(): void {
  proSheet.value = false
  practice.dismissPaywall()
}

const { reviewFromPaywall } = useReviewFromPaywall(closePaywall, { toastErrors: true })

/**
 * The place above the heading: the lesson ("Module 2 · Lesson 4 · Food"; a lesson with neither a name nor a number - the server does not know
 * them - is named by its course) or the course; nothing until the hub is there.
 */
const place = computed((): string => {
  const current = hub.value
  if (current === null) return ''
  if (current.lesson === null) return current.course.title
  return lessonHeader(current.lesson) || current.course.title
})

/**
 * "Play on 120 learned words": the number is the one of `/today` (`active_course.progress.learned`), only when its course is the course of
 * the hub - the hub itself does not count the words; without it the line has no number.
 */
const learnedLine = computed((): string => {
  const course = today.data?.active_course ?? null
  if (course !== null && course.slug === props.slug && course.progress.learned > 0) return tn('practice.hub.learned', course.progress.learned)
  return t('practice.hub.learned_unknown')
})

/** The unfinished session of the mode, when it is this very course / lesson: the card leads straight back into it. */
function resumeFor(info: PracticeModeInfo): PracticeActiveSession | null {
  const current = hub.value
  const active = current?.active_session ?? null
  if (current === null || active === null || active.mode !== info.mode) return null
  return active.course === current.course.slug && active.lesson_id === (current.lesson?.id ?? null) ? active : null
}

/**
 * The cards that are not shown at all:
 * - a mode this build does not know (a newer server, an old bundle still open in the WebView during a release): it could not be drawn;
 * - a mode that lacks only its recordings (`not_enough` with the reason `audio_pending` / `no_audio_words`: the dictation, while the files
 *   of the course are not made) - there is nothing the learner can do about it, and a card that never opens is noise;
 * - a mode of authored tasks with no task for the topic at all (`no_items` with `available = 0`: the newer modes before their packs, the
 *   courses whose tasks are not written yet) - the same noise.
 * The other reasons ("learn the words", "few tasks yet: 2 of 3") stay: they say what the next step is, or that tasks are coming.
 */
const AUDIO_SHORTAGES: readonly (PracticeModeInfo['reason'])[] = ['audio_pending', 'no_audio_words']
function hidden(info: PracticeModeInfo): boolean {
  if (!isKnownPracticeMode(info.mode)) return true
  if (info.state !== 'not_enough') return false
  return AUDIO_SHORTAGES.includes(info.reason) || (info.reason === 'no_items' && info.available === 0)
}
const modes = computed((): PracticeModeInfo[] => (hub.value?.modes ?? []).filter((info) => !hidden(info)))

/** The unfinished session, when this build can draw its mode (one of a newer server is left alone: the server keeps it). */
const activeSession = computed((): PracticeActiveSession | null => {
  const active = hub.value?.active_session ?? null
  return active !== null && isKnownPracticeMode(active.mode) ? active : null
})

const learnKind = computed(() => (hub.value?.lesson ? 'lesson' : 'course'))

/** Where the unfinished session is ("Module 2 · Lesson 4 · Food"), when that can be told: the card and the sheet name it. */
const activePlace = computed((): string | null => {
  const active = hub.value?.active_session ?? null
  return active === null ? null : practice.placeOf(active)
})
const conflictPlace = computed((): string | null => (conflict.value === null ? null : practice.placeOf(conflict.value)))

/** "Continue: Find the pairs, 1 of 3 done" for a screen reader, and where the session is. */
const continueLabel = computed((): string => {
  const active = activeSession.value
  if (active === null) return ''
  const words = t('practice.hub.card.aria_resume', { title: modeTitle(active.mode), done: active.progress.done, total: active.progress.total })
  return activePlace.value === null ? words : `${words}. ${activePlace.value}`
})

async function load(): Promise<void> {
  try {
    await auth.ensure()
  } catch (err) {
    practice.failHub(err)
    return
  }
  await practice.loadHub(props.slug, props.lessonId)
  if (!left) await autoplay()
  // The invitations to me are in the list (the hub has only their number); it is asked for only when there are some.
  const state = hub.value?.battle
  if (!left && state?.enabled && state.incoming_count > 0) void battle.loadList()
}

function openSession(id: number): Promise<unknown> {
  return router.push({ name: 'practice-session', params: { id } })
}

/** Where the sessions of a card come from: the hub, or the hub opened from the finish screen. */
const cardSource = computed((): PracticeSource => (props.from === 'finished' ? 'after_training' : 'hub'))
/** The source of the session the learner pressed last, for "Start a new one" after the unfinished session has been offered. */
let pressedSource: PracticeSource = 'hub'

async function start(mode: PracticeMode, replaceActive: boolean, source: PracticeSource = cardSource.value): Promise<void> {
  // One session is being opened at a time; a second card pressed meanwhile must not become "the mode that was pressed".
  if (opening.value !== null) return
  pressed.value = mode
  pressedSource = source
  // The unfinished session is the one the learner has just walked out of on purpose ("No sound for now" on a dictation): the mode
  // they chose takes its place at once, with no "Continue or start a new one" about it.
  const replace = replaceActive || practice.isAbandoned(hub.value?.active_session?.id ?? null)
  const id = await practice.open({ mode, course: props.slug, lesson_id: props.lessonId, source, replace_active: replace })
  if (id !== null) practice.clearAbandoned()
  if (id !== null && !left) await openSession(id)
}

function select(mode: PracticeMode): void {
  const info = hub.value?.modes.find((card) => card.mode === mode)
  if (!info) return
  haptic('light')
  if (info.state === 'pro_required') {
    proSheet.value = true
    return
  }
  const resume = resumeFor(info)
  if (resume) void openSession(resume.id)
  else void start(mode, false)
}

/** "Continue" of the unfinished-session sheet. */
function continueActive(): void {
  const active = conflict.value
  practice.clearConflict()
  if (active !== null) void openSession(active.id)
}

/** "Start a new one": the old session is closed by the server (`replace_active`). */
function startNew(): void {
  const mode = pressed.value
  practice.clearConflict()
  if (mode !== null) void start(mode, true, pressedSource)
}

// ---- step 3 of the day plan: the timed pairs on the words of the lesson ----

/** The card of the timed pairs as the hub states it; null on the hub of a course, or when the server has none. */
const lessonGame = computed((): PracticeModeInfo | null => {
  if (props.lessonId === null || hub.value?.lesson == null) return null
  return hub.value.modes.find((info) => info.mode === 'pairs_timed') ?? null
})
/**
 * The card of step 3 is there on the hub of a lesson whose timed pairs are not switched off. A short lesson (`not_enough`, `no_words`) keeps
 * it: the server tops the lesson up with learned words of the course when the game is opened.
 */
const showLessonGame = computed(() => lessonGame.value !== null && lessonGame.value.state !== 'disabled')
/** The card is the step of the plan: the hub came from the plan, or its lesson is the lesson of the game of today's plan. */
const isPlanStep = computed(() => fromPlan.value || (props.lessonId !== null && today.data?.plan?.game?.lesson_id === props.lessonId))
/** "Step 2 of 2 · recommended now": the place of the game among the steps the plan has; "recommended now" without a plan at hand. */
const lessonGameEyebrow = computed((): string => {
  if (!isPlanStep.value) return ''
  const position = dayPlanStepPosition(today.data?.plan, gameStepExpected(today.data?.practice), 'game')
  return position === null ? t('practice.hub.step.now') : t('practice.hub.step.eyebrow', position)
})
const lessonGameText = computed((): string => {
  const minutes = estimateMinutes(lessonGame.value?.estimate_seconds ?? 60)
  const title = hub.value?.lesson?.title ?? null
  return title ? t('practice.hub.step.text', { lesson: title, minutes }) : t('practice.hub.step.text_plain', { minutes })
})

/**
 * "Play" of the card of step 3, and the start by the address: Pro only - the paywall at once; the unfinished game of this lesson - back
 * into it; else a new game (`deeplink`), whatever the hub says about the words of the lesson (the server tops a short lesson up).
 */
function playLessonGame(): void {
  const info = lessonGame.value
  haptic('light')
  if (info?.state === 'pro_required') {
    proSheet.value = true
    return
  }
  const resume = info === null ? null : resumeFor(info)
  if (resume) void openSession(resume.id)
  else void start('pairs_timed', false, 'deeplink')
}

/**
 * `?play=pairs_timed`: the address loses `play` first (`replace`: "Back" from the game then lands on the hub, not on a new game), then the
 * game opens. Without a lesson, or on a hub that is closed to the learner, nothing is started.
 */
let autoplayed = false
async function autoplay(): Promise<void> {
  // Once per visit: the hub is read again (another plan, another lesson) without the learner asking for the game again.
  if (props.play === null || autoplayed) return
  autoplayed = true
  const { play: _play, ...query } = route.query
  await router.replace({ query })
  // A hub that failed to load (the error with "Retry") starts nothing either: the learner sees why, the game would hide it.
  if (left || props.lessonId === null || hubStatus.value === 'unavailable' || hubStatus.value === 'error') return
  playLessonGame()
}

// ---- the caravan of the day ----

/** "Caravan of the day": the game is open to the learner (`/me`, asked best effort). */
const caravanOn = computed(() => billing.gameAccess.available)

async function learn(): Promise<void> {
  const lesson = hub.value?.lesson
  if (!lesson) {
    await router.push({ name: 'course', params: { slug: props.slug } })
    return
  }
  const sessionId = await training.open({ mode: 'lesson', lesson_id: lesson.id, source: 'picker' })
  if (left) return
  if (sessionId !== null) await router.push({ name: 'training', params: { sessionId } })
  else if (training.openError !== '') {
    showErrorToast(training.openError)
    training.clearOpenError()
  }
}

// ---- the battle with a friend ----

const battleOn = computed(() => hub.value?.battle.enabled === true)
const activeBattleId = computed(() => hub.value?.battle.active_battle_id ?? null)

function playWithFriend(): void {
  haptic('light')
  if (activeBattleId.value !== null) {
    void router.push({ name: 'battle', params: { id: activeBattleId.value } })
    return
  }
  // A new battle is counted, and Free has a few a day: with none left, the paywall instead of a setup that ends in it.
  const spent = billing.limitReached('battles')
  if (spent !== null) {
    practice.showLimit(spent)
    return
  }
  pickerOpen.value = true
  void friends.load()
}

const pickerStatus = computed((): 'loading' | 'ready' | 'error' => {
  if (friends.friends !== null) return 'ready'
  return friends.status === 'error' ? 'error' : 'loading'
})

function pickFriend(friend: FriendItem): void {
  pickerOpen.value = false
  void router.push({
    name: 'battle-new',
    query: { friendship: String(friend.id), course: props.slug, ...(props.lessonId !== null ? { lesson: String(props.lessonId) } : {}) },
  })
}

const invitations = computed(() => (battleOn.value ? battle.incoming : []))

function invitationMeta(invitation: BattleIncoming): string {
  const topic = invitation.lesson ? lessonHeader(invitation.lesson) : invitation.course.title
  return `${modeTitle(invitation.mode)} · ${topic}`
}

// A refused open is told in a toast: the cards are all over a long screen.
watch(openError, (message) => {
  if (message === '') return
  showErrorToast(message)
  practice.clearOpenError()
})

watch([() => props.slug, () => props.lessonId], () => void load())

// The plan changes under the hub (Pro paid for in the sheet, a promo code): the cards behind Pro are not behind it
// any more, so the hub is asked again. The first `/me` (the plan becomes known) is not a change.
watch(
  () => billing.entitlement?.plan,
  (plan, before) => {
    if (before !== undefined && plan !== before) void load()
  },
)

onMounted(() => {
  void load()
  // What the learner may play beyond practice (the caravan): best effort, the hub never waits for it.
  void billing.load()
})

onBeforeUnmount(() => {
  left = true
  // What the hub asked for may still come back: a refusal of it (the unfinished session, the paywall, a toast) is not for the next hub.
  // And "dropped on purpose" is for this one visit only.
  practice.leaveOpen()
  practice.clearAbandoned()
})
</script>

<template>
  <div class="page" data-testid="practice-hub-view">
    <!-- The way back is on the screen too (the system button of Telegram says nothing about where it leads). -->
    <RouterLink
      :to="backTarget"
      class="-mb-3.5 flex min-h-11 items-center gap-1 self-start text-[15px] font-semibold text-accent"
      data-testid="hub-back"
    >
      <AppIcon name="chevron-left" :size="18" />{{ fromPlan ? t('nav.today') : t('nav.courses') }}
    </RouterLink>

    <PageHeader :eyebrow="place" :title="t('practice.hub.games_title')" :subtitle="learnedLine" large />

    <LoadingState v-if="hubStatus === 'idle' || hubStatus === 'loading'" variant="cards" />

    <EmptyState
      v-else-if="hubStatus === 'unavailable'"
      icon="lock-closed"
      :title="t('practice.hub.unavailable_title')"
      :text="t('practice.hub.unavailable_text')"
      data-testid="unavailable"
    >
      <template #action>
        <button type="button" class="btn btn-tinted" data-testid="to-course" @click="router.push({ name: 'course', params: { slug } })">
          {{ t('practice.hub.to_course') }}
        </button>
      </template>
    </EmptyState>

    <ErrorState v-else-if="hubStatus === 'error' || hub === null" :message="hubError" @retry="load" />

    <template v-else>
      <ListGroup v-if="invitations.length > 0" :header="t('practice.hub.battle.invites')" class="-mt-5 mb-3.5" data-testid="battle-invites">
        <ListRow
          v-for="invitation in invitations"
          :key="invitation.id"
          :to="{ name: 'battle', params: { id: invitation.id } }"
          :title="t('practice.hub.battle.invited_by', { name: invitation.from.first_name || t('friends.list.anonymous') })"
          :subtitle="invitationMeta(invitation)"
          :data-testid="`invitation-${invitation.id}`"
        />
      </ListGroup>

      <!-- Step 3 of the day plan (hub of a lesson): the timed pairs on its words. Its button is the main one unless "Continue" has it. -->
      <section v-if="showLessonGame" class="mb-3.5 flex flex-col gap-3 rounded-hero bg-highlight p-4" data-testid="lesson-game-card">
        <div class="flex flex-col gap-1">
          <p v-if="lessonGameEyebrow" class="text-[13px] leading-[18px] font-bold text-accent" data-testid="lesson-game-step">{{ lessonGameEyebrow }}</p>
          <h2 class="text-2xl leading-[30px] font-extrabold tracking-[-0.4px]">{{ modeTitle('pairs_timed') }}</h2>
          <p class="text-sm leading-5" data-testid="lesson-game-text">{{ lessonGameText }}</p>
        </div>
        <button
          type="button"
          class="btn btn-lg btn-block"
          :class="activeSession ? 'btn-tinted' : 'btn-primary'"
          :disabled="opening !== null"
          :aria-busy="opening === 'pairs_timed' ? 'true' : undefined"
          data-testid="lesson-game-play"
          @click="playLessonGame"
        >
          {{ t('practice.hub.step.play') }}
          <AppIcon name="arrow-right" :size="22" />
        </button>
      </section>

      <!-- The unfinished session: one tap back into it. The whole card is the link; the button inside it is its look. -->
      <RouterLink
        v-if="activeSession"
        :to="{ name: 'practice-session', params: { id: activeSession.id } }"
        class="card mb-3.5 flex flex-col gap-3"
        :aria-label="continueLabel"
        data-testid="continue-card"
      >
        <span class="text-[13px] leading-[18px] text-muted">{{ t('practice.hub.continue') }}</span>
        <span class="flex flex-col gap-1">
          <span class="text-xl leading-6 font-semibold" data-testid="continue-title">{{ modeTitle(activeSession.mode) }}</span>
          <span v-if="activePlace" class="text-sm leading-5 text-muted" data-testid="continue-place">{{ activePlace }}</span>
          <span class="text-[13px] leading-[18px] text-muted" data-testid="continue-progress">
            {{ t('practice.session.progress', { done: activeSession.progress.done, total: activeSession.progress.total }) }}
          </span>
        </span>
        <ProgressLine :value="progressPercent(activeSession.progress)" />
        <span class="btn btn-primary btn-block" aria-hidden="true">{{ t('practice.hub.continue') }}</span>
      </RouterLink>

      <section class="overflow-hidden rounded-card border border-hairline bg-surface" data-testid="modes-list">
        <h2 class="px-3.5 pt-3.5 pb-2 text-lg leading-6 font-extrabold">{{ t('practice.hub.all_modes') }}</h2>
        <!-- Free only: the sessions left today. Quiet on purpose - the rows below are the screen. -->
        <p v-if="sessionLimit" class="-mt-1 px-3.5 pb-2 text-[13px] leading-[18px] text-muted" data-testid="practice-limit">
          {{ t('practice.limit.sessions', { remaining: Math.max(0, sessionLimit.remaining), limit: sessionLimit.limit }) }}
        </p>
        <div class="divide-y divide-divider" data-testid="mode-cards">
          <ModeCard
            v-for="info in modes"
            :key="info.mode"
            :info="info"
            :resume="resumeFor(info)"
            :busy="opening === info.mode"
            :learn="learnKind"
            @select="select"
            @learn="learn"
          />
        </div>

        <!-- The caravan of the day (docs/05 §5b.4): a link to its screen, on the words of this course. -->
        <RouterLink
          v-if="caravanOn"
          :to="{ name: 'game-daily', query: { course: slug } }"
          class="flex min-h-[60px] items-center gap-3 border-t border-divider px-3.5 py-2.5 active:bg-fill"
          data-testid="caravan-row"
        >
          <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-accent-soft text-accent" aria-hidden="true">
            <AppIcon name="globe-alt" :size="22" />
          </span>
          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-base leading-5 font-bold">{{ t('practice.hub.caravan.title') }}</span>
            <span class="text-[13px] leading-[18px] text-muted">{{ t('practice.hub.caravan.text') }}</span>
          </span>
          <AppIcon name="chevron-right" :size="16" class="shrink-0 text-muted" />
        </RouterLink>

        <!-- The battle with a friend: the last row, the whole row is its button (a new battle, or back into the one under way). -->
        <div v-if="battleOn" class="border-t border-divider" data-testid="battle-card">
          <button
            type="button"
            class="flex min-h-[60px] w-full items-center gap-3 px-3.5 py-2.5 text-left active:bg-fill"
            :aria-label="activeBattleId !== null ? t('practice.hub.battle.resume') : `${t('practice.hub.battle.title')}. ${t('practice.hub.battle.text')}`"
            data-testid="battle-play-btn"
            @click="playWithFriend"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-accent-soft text-accent" aria-hidden="true">
              <AppIcon name="users" :size="22" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
              <span class="text-base leading-5 font-bold">{{ t('practice.hub.battle.title') }}</span>
              <span class="text-[13px] leading-[18px] text-muted">{{ t('practice.hub.battle.text') }}</span>
            </span>
            <span v-if="activeBattleId !== null" class="shrink-0 text-[13px] leading-[18px] text-accent" data-testid="battle-resume">{{ t('practice.hub.battle.resume') }}</span>
            <AppIcon v-else name="chevron-right" :size="16" class="shrink-0 text-muted" />
          </button>
        </div>
      </section>
    </template>

    <ActiveSessionSheet
      v-if="conflict"
      :session="conflict"
      :place="conflictPlace"
      :busy="opening !== null"
      @continue="continueActive"
      @replace="startNew"
      @close="practice.clearConflict()"
    />

    <FriendPickerSheet
      v-if="pickerOpen"
      :friends="friends.friends?.friends ?? []"
      :status="pickerStatus"
      :message="friends.errorMessage"
      @pick="pickFriend"
      @invite="router.push({ name: 'friends' })"
      @retry="friends.load()"
      @close="pickerOpen = false"
    />

    <PaywallSheet
      v-if="showPaywall"
      :reason="limitHit ? 'practice_limit' : 'practice'"
      :limit-hit="limitHit"
      @close="closePaywall"
      @review="reviewFromPaywall"
    />
  </div>
</template>
