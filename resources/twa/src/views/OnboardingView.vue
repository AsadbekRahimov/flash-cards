<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import { useRouter } from 'vue-router'
import AgeStep from '@/components/onboarding/AgeStep.vue'
import DailyGoalStep from '@/components/onboarding/DailyGoalStep.vue'
import GoalsStep from '@/components/onboarding/GoalsStep.vue'
import LevelStep from '@/components/onboarding/LevelStep.vue'
import NameStep from '@/components/onboarding/NameStep.vue'
import PlacementStep from '@/components/onboarding/PlacementStep.vue'
import PlanStep from '@/components/onboarding/PlanStep.vue'
import ReminderStep from '@/components/onboarding/ReminderStep.vue'
import WelcomeStep from '@/components/onboarding/WelcomeStep.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import SegmentProgress from '@/components/ui/SegmentProgress.vue'
import { ONBOARDING_STEPS, type OnboardingStep } from '@/api/onboardingTypes'
import { useOnboardingStore } from '@/stores/onboarding'
import { useTelegram } from '@/composables/useTelegram'
import { useOnboardingI18n } from '@/i18n/onboarding'

/**
 * The onboarding of the Mini App, O1-O8 (docs/40 §2.1, kit Muhrla 02-onboarding): one screen, the step is the parameter of the route
 * (`/onboarding` - O1 "Welcome", `/onboarding/name` … `/onboarding/plan`). Every step saves its answer on the server at once, so a
 * closed app resumes where it stopped (the guard of the router opens the step the server keeps).
 *
 * - The progress: 7 segments for O2-O8 and "n / 7"; no progress on O1.
 * - Telegram's system "Back" on O2-O8 leads to the step before (the answers stay in the store and on the server); none on O1 (Telegram's
 *   "Close" is there). No "Back" of our own is drawn.
 * - A step enters from the right (24 px and a fade, 260 ms; ANIMATIONS.md §7), going back - from the left; without motion only a fade.
 * - The state is loaded once; while it loads O2-O8 show a skeleton, a failed load - the error with "Retry". O1 needs no data.
 * - 409 `onboarding_completed` on any step (finished in the bot or on another device) - "Today".
 * - `/onboarding/placement` - the placement test of O5 (docs/40 §2.1a): its own segments (the questions) instead of the 7 steps; Back and
 *   its last button lead to O5, which shows the result as the chosen row.
 */
const props = defineProps<{ step: string | null }>()

const router = useRouter()
const store = useOnboardingStore()
const { showBackButton, webApp } = useTelegram()
const { t } = useOnboardingI18n()

type Screen = 'welcome' | OnboardingStep

/** The screens in their order: O1 and the seven steps. */
const SCREENS: readonly Screen[] = ['welcome', ...ONBOARDING_STEPS]

const STEP_COMPONENTS: Readonly<Record<Screen, Component>> = {
  welcome: WelcomeStep,
  name: NameStep,
  age: AgeStep,
  goals: GoalsStep,
  level: LevelStep,
  daily_goal: DailyGoalStep,
  reminder: ReminderStep,
  plan: PlanStep,
}

/** The placement test of O5: a screen of its own between O5 and O6 (not a step: nothing is saved by it, the progress is its own). */
const PLACEMENT = 'placement'

const screen = computed((): Screen | typeof PLACEMENT => {
  const step = props.step
  if (step === PLACEMENT) return PLACEMENT
  return step !== null && (SCREENS as readonly string[]).includes(step) ? (step as Screen) : 'welcome'
})
const isPlacement = computed(() => screen.value === PLACEMENT)
/** The place of the screen in the order (the test - right after O5, so entering it slides forward and leaving it back). */
const position = computed(() => (screen.value === PLACEMENT ? SCREENS.indexOf('level') + 0.5 : SCREENS.indexOf(screen.value)))
/** The step of the progress: the test counts as O5. */
const index = computed(() => Math.floor(position.value))
/** O2 is "1 / 7" … O8 is "7 / 7". */
const TOTAL = SCREENS.length - 1

const needsData = computed(() => screen.value !== 'welcome')

/** The direction of the last move: the next step enters from the right, the one before from the left. */
const direction = ref<'next' | 'back' | null>(null)
const animate = ref(false)

watch(position, (now, before) => {
  direction.value = now > before ? 'next' : 'back'
  // Under `prefers-reduced-motion` the CSS makes it a short fade, which ends (and takes the class off) like the slide.
  animate.value = true
})

function locationOf(target: Screen) {
  return target === 'welcome' ? { name: 'onboarding' as const } : { name: 'onboarding' as const, params: { step: target } }
}

function goNext(): void {
  const next = SCREENS[index.value + 1]
  if (next !== undefined) void router.push(locationOf(next))
}

function goTo(target: string): void {
  if ((SCREENS as readonly string[]).includes(target)) void router.replace(locationOf(target as Screen))
}

/** O5 asks for the placement test. */
function openPlacement(): void {
  void router.push({ name: 'onboarding', params: { step: PLACEMENT } })
}

/** "Back": the step before; to it by `back()` when the learner came from it (the history does not grow with every "Back"). The test - O5. */
function goBack(): void {
  const previous = isPlacement.value ? 'level' : SCREENS[index.value - 1]
  if (previous === undefined) return
  const target = router.resolve(locationOf(previous))
  if (router.options.history.state.back === target.fullPath) router.back()
  else void router.replace(target)
}

/** The onboarding is over: "Today" (it offers the first lesson; `from=onboarding` tells it where the learner came from). */
function finish(): void {
  void router.replace({ name: 'today', query: { from: 'onboarding' } })
}

function leave(): void {
  void router.replace({ name: 'today' })
}

// Telegram's "Back": shown on O2-O8, hidden on O1; the handler reads the step at the press.
let releaseBack: (() => void) | null = null
function syncBackButton(): void {
  if (screen.value === 'welcome') {
    releaseBack?.()
    releaseBack = null
    try {
      webApp?.BackButton?.hide()
    } catch {
      // outside Telegram
    }
    return
  }
  releaseBack ??= showBackButton(goBack)
}
watch(screen, syncBackButton)

onMounted(async () => {
  syncBackButton()
  if (store.status !== 'ready') await store.load()
  // Finished elsewhere (the bot, another device): nothing to ask.
  if (store.data?.completed === true) leave()
})

onBeforeUnmount(() => {
  releaseBack?.()
  releaseBack = null
})
</script>

<template>
  <section class="flex flex-1 flex-col bg-page" data-testid="onboarding" :data-step="screen">
    <!-- The column of `.page` without its bottom padding: the sticky buttons of a step stand on the bottom edge with the safe zone. -->
    <div class="mx-auto flex w-full max-w-xl flex-1 flex-col px-5 pt-2">
      <div v-if="screen !== 'welcome' && !isPlacement" class="flex items-center gap-3 pt-2 pb-4" data-testid="onboarding-progress">
        <SegmentProgress
          :total="TOTAL"
          :current="index - 1"
          :label="t('onboarding.progress_label', { n: index, total: TOTAL })"
          class="flex-1"
        />
        <span class="t-label-s font-mono shrink-0 text-muted" aria-hidden="true" data-testid="onboarding-counter">{{ index }} / {{ TOTAL }}</span>
      </div>

      <div v-if="needsData && store.data === null && store.status !== 'error'" class="flex flex-col items-center gap-3 pt-6" data-testid="onboarding-skeleton">
        <span class="skeleton size-[88px] rounded-full" />
        <span class="skeleton h-7 w-56" />
        <span class="skeleton h-5 w-64" />
        <span class="skeleton mt-4 h-14 w-full rounded-md" />
        <span class="skeleton h-14 w-full rounded-md" />
        <span class="skeleton h-14 w-full rounded-md" />
      </div>
      <ErrorState v-else-if="needsData && store.data === null" :message="store.loadError" @retry="store.load()" />
      <div
        v-else
        :key="screen"
        class="flex flex-1 flex-col"
        :class="animate && direction ? `onboarding-enter-${direction}` : undefined"
        data-testid="onboarding-step"
        @animationend.self="animate = false"
      >
        <PlacementStep v-if="screen === PLACEMENT" @back="goBack" @exit="leave" />
        <component :is="STEP_COMPONENTS[screen]" v-else @next="goNext" @exit="leave" @done="finish" @missing="goTo" @placement="openPlacement" />
      </div>
    </div>
  </section>
</template>

<style scoped>
/*
 * The step enters (ANIMATIONS.md §7): 24 px from the side and a fade, 260 ms. `backwards`: once it has ended the step has no transform
 * left - a transform would make it the containing block of the fixed paywall sheet and of the sticky buttons.
 */
.onboarding-enter-next {
  animation: onboarding-in-next var(--dur-enter) var(--ease-out) backwards;
}
.onboarding-enter-back {
  animation: onboarding-in-back var(--dur-enter) var(--ease-out) backwards;
}
@keyframes onboarding-in-next {
  from {
    opacity: 0;
    transform: translateX(24px);
  }
}
@keyframes onboarding-in-back {
  from {
    opacity: 0;
    transform: translateX(-24px);
  }
}
@media (prefers-reduced-motion: reduce) {
  .onboarding-enter-next,
  .onboarding-enter-back {
    animation: onboarding-fade 120ms linear backwards;
  }
}
@keyframes onboarding-fade {
  from {
    opacity: 0;
  }
}
</style>
