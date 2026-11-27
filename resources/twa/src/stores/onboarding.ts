import { defineStore } from 'pinia'
import { api } from '@/api'
import { ApiClientError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useTelegram } from '@/composables/useTelegram'
import { markSeen } from '@/composables/seenFlag'
import { errorMessage } from '@/i18n/errors'
import {
  ONBOARDING_STEPS,
  type AgeRange,
  type OnboardingGoal,
  type OnboardingSavedStep,
  type OnboardingState,
  type OnboardingStep,
  type OnboardingStepBodies,
  type WriteAccess,
} from '@/api/onboardingTypes'

/**
 * The flag of the intro slides of "Today" (`TodayView`, `IntroSheet`): a learner who went through the onboarding of the Mini App has
 * already been told what the app is (docs/40 §2.1), so it is set when the onboarding is finished. The same key as in `TodayView.vue`.
 */
export const INTRO_KEY = 'lexiflow:intro-seen'

/** The reminder of O7 when nothing was saved yet (ONBOARDING.md: 20:00, the switch on). */
export const DEFAULT_REMINDER_TIME = '20:00'

export type OnboardingStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * How a save ended, for the screen:
 * - `ok` - saved, go on;
 * - `completed` - 409 `onboarding_completed`: the onboarding was finished elsewhere (the bot, another device) - leave for "Today";
 * - `pro_required` - 403 `daily_goal_pro_required`: the goal is behind Pro, the choice went back to the plan's limit;
 * - `invalid` - 422 `display_name_invalid`: the name is refused, `nameError` says why;
 * - `error` - anything else (the connection first of all): `saveError` says what, the answer stays in the store for "Retry".
 */
export type SaveOutcome = 'ok' | 'completed' | 'pro_required' | 'invalid' | 'error'

/** How the finish ended: `ok`; `incomplete` - a step is missing (`missingStep`); `error` - `saveError` says what. */
export type CompleteOutcome = 'ok' | 'incomplete' | 'error'

/** The answers of the learner on the screens: kept here, so "Back" and a failed save lose nothing. */
export interface OnboardingDraft {
  name: string
  /** The name came from Telegram and was not touched: O2 says so under the field. */
  nameFromTelegram: boolean
  age: AgeRange | null
  goals: OnboardingGoal[]
  /** `level` of the chosen row of O5 (`options.levels[].level`). */
  level: string | null
  dailyGoal: number | null
  /** `HH:MM`; null - "Custom" with no time yet. */
  reminderTime: string | null
  /** The time is the learner's own ("Custom"), not one of the tiles. */
  reminderCustom: boolean
  /** The switch "Messages from the bot" of O7. */
  botMessages: boolean
  writeAccess: WriteAccess
}

interface OnboardingStoreState {
  data: OnboardingState | null
  status: OnboardingStatus
  loadError: string
  draft: OnboardingDraft
  /** The values of the server have been put into the draft once (the learner's later choices are not overwritten by a reload). */
  drafted: boolean
  /** The step being saved, or `complete`; null - nothing in flight. */
  saving: OnboardingSavedStep | 'complete' | null
  /** Why the last save failed (localized); '' - it did not. */
  saveError: string
  /** The step the error belongs to: a screen shows only its own. */
  saveErrorStep: OnboardingSavedStep | 'complete' | null
  /** `details.reason` of 422 `display_name_invalid`; null - none. */
  nameError: string | null
  /** The last daily goal was refused with 403 `daily_goal_pro_required` (the trial ended between the load and the tap). */
  goalProRequired: boolean
  /** The step 422 `onboarding_incomplete` named (`details.missing[0]`). */
  missingStep: OnboardingStep | null
}

const emptyDraft = (): OnboardingDraft => ({
  name: '',
  nameFromTelegram: false,
  age: null,
  goals: [],
  level: null,
  dailyGoal: null,
  reminderTime: DEFAULT_REMINDER_TIME,
  reminderCustom: false,
  botMessages: true,
  writeAccess: 'not_asked',
})

function isStep(value: unknown): value is OnboardingStep {
  return typeof value === 'string' && (ONBOARDING_STEPS as readonly string[]).includes(value)
}

export const useOnboardingStore = defineStore('onboarding', {
  state: (): OnboardingStoreState => ({
    data: null,
    status: 'idle',
    loadError: '',
    draft: emptyDraft(),
    drafted: false,
    saving: null,
    saveError: '',
    saveErrorStep: null,
    nameError: null,
    goalProRequired: false,
    missingStep: null,
  }),

  getters: {
    /** The highest daily goal the plan allows; null - no limit. */
    goalMax: (s): number | null => s.data?.options.daily_goal_max ?? null,
  },

  actions: {
    /** Loads the state; a screen that already has it stays as it is while reloading. */
    async load(): Promise<void> {
      if (this.status === 'loading') return
      this.status = this.data === null ? 'loading' : this.status
      this.loadError = ''
      try {
        await useAuthStore().ensure()
        this.take(await api.onboarding.get())
        this.status = 'ready'
      } catch (err) {
        this.status = 'error'
        this.loadError = errorMessage(err, 'settings')
      }
    },

    /** The answer of the server: the state, the learner's `onboarding` of the auth store, the draft (once). */
    take(state: OnboardingState): void {
      this.data = state
      const learner = useAuthStore().learner
      if (learner) {
        learner.onboarding = { completed: state.completed, step: state.step }
        if (state.completed) learner.onboarding_completed = true
        if (state.values.display_name !== null) learner.display_name = state.values.display_name
      }
      if (!this.drafted) {
        this.drafted = true
        this.fillDraft(state)
      }
    },

    /** The saved answers into the draft; what was never saved gets the default of the kit. */
    fillDraft(state: OnboardingState): void {
      const { values, options } = state
      const draft = emptyDraft()
      const telegramName = useTelegram().firstName()
      if (values.display_name !== null) {
        draft.name = values.display_name
      } else if (telegramName !== null) {
        draft.name = telegramName
        draft.nameFromTelegram = true
      }
      draft.age = values.age_range
      draft.goals = [...(values.goals ?? [])]
      const levelRow = options.levels.find((row) => row.course_slug === values.course?.slug)
        ?? options.levels.find((row) => values.level !== null && row.levels.includes(values.level))
      draft.level = levelRow?.level ?? null
      draft.dailyGoal = values.daily_goal ?? this.defaultGoal(state)
      if (values.reminder !== null) {
        const time = values.reminder.time ?? DEFAULT_REMINDER_TIME
        draft.reminderTime = time
        draft.reminderCustom = !options.reminder_times.includes(time)
      }
      this.draft = draft
    },

    /** The goal to start O6 with: the recommended one, or the plan's limit when the recommended one is behind Pro. */
    defaultGoal(state: OnboardingState): number | null {
      const { daily_goals: goals, daily_goal_max: max, recommended_daily_goal: recommended } = state.options
      const open = goals.filter((goal) => max === null || goal <= max)
      if (recommended !== null && open.includes(recommended)) return recommended
      return open.length > 0 ? Math.max(...open) : null
    },

    /** The body of a step from the draft; null - nothing to send yet. */
    bodyOf<S extends Exclude<OnboardingSavedStep, 'reminder'>>(step: S): OnboardingStepBodies[S] | null {
      const draft = this.draft
      let body: OnboardingStepBodies[Exclude<OnboardingSavedStep, 'reminder'>] | null = null
      if (step === 'name') body = draft.name.trim() === '' ? null : { display_name: draft.name.trim() }
      else if (step === 'age') body = draft.age === null ? null : { age_range: draft.age }
      else if (step === 'goals') body = draft.goals.length === 0 ? null : { goals: [...draft.goals] }
      else if (step === 'level') {
        const row = this.data?.options.levels.find((option) => option.level === draft.level)
        body = row === undefined ? null : { level: row.level, course_slug: row.course_slug }
      } else if (step === 'daily_goal') body = draft.dailyGoal === null ? null : { daily_goal: draft.dailyGoal }
      return body as OnboardingStepBodies[S] | null
    },

    /** Saves a step from the draft (O2-O6). */
    async saveStep(step: Exclude<OnboardingSavedStep, 'reminder'>): Promise<SaveOutcome> {
      const body = this.bodyOf(step)
      if (body === null) return 'error'
      return this.send(step, body)
    },

    /** Saves the reminder (O7): "Continue" sends the time, "Do not remind" sends `enabled: false`. */
    async saveReminder(enabled: boolean): Promise<SaveOutcome> {
      const time = enabled ? this.draft.reminderTime : null
      if (enabled && time === null) return 'error'
      return this.send('reminder', { enabled, time, write_access: this.draft.writeAccess })
    },

    async send<S extends OnboardingSavedStep>(step: S, body: OnboardingStepBodies[S]): Promise<SaveOutcome> {
      if (this.saving !== null) return 'error'
      this.saving = step
      this.saveError = ''
      this.saveErrorStep = null
      this.nameError = null
      this.goalProRequired = false
      try {
        await useAuthStore().ensure()
        this.take(await api.onboarding.saveStep(step, body))
        return 'ok'
      } catch (err) {
        return this.refused(step, err)
      } finally {
        this.saving = null
      }
    },

    refused(step: OnboardingSavedStep, err: unknown): SaveOutcome {
      if (err instanceof ApiClientError && err.code === 'onboarding_completed') {
        this.markCompleted()
        return 'completed'
      }
      if (err instanceof ApiClientError && err.code === 'daily_goal_pro_required') {
        // Not an error to tell: the goal is behind Pro now. The limit of the answer locks the goals above it, the choice goes back to it.
        this.goalProRequired = true
        const max = err.details?.max
        if (typeof max === 'number' && this.data) {
          this.data.options.daily_goal_max = max
          this.draft.dailyGoal = this.defaultGoal(this.data)
        }
        return 'pro_required'
      }
      if (err instanceof ApiClientError && err.code === 'display_name_invalid') {
        this.nameError = typeof err.details?.reason === 'string' ? err.details.reason : 'invalid'
        return 'invalid'
      }
      this.saveError = errorMessage(err, 'settings')
      this.saveErrorStep = step
      return 'error'
    },

    /**
     * Finishes the onboarding: the course is set by the server, the intro slides of "Today" are not shown any more, the guard of the
     * router lets the learner through from now on.
     */
    async complete(): Promise<CompleteOutcome> {
      if (this.saving !== null) return 'error'
      this.saving = 'complete'
      this.saveError = ''
      this.saveErrorStep = null
      this.missingStep = null
      try {
        await useAuthStore().ensure()
        await api.onboarding.complete()
        this.markCompleted()
        return 'ok'
      } catch (err) {
        if (err instanceof ApiClientError && err.code === 'onboarding_incomplete') {
          const missing = err.details?.missing
          const first = Array.isArray(missing) ? missing[0] : null
          this.missingStep = isStep(first) ? first : 'level'
          return 'incomplete'
        }
        if (err instanceof ApiClientError && err.code === 'onboarding_completed') {
          this.markCompleted()
          return 'ok'
        }
        this.saveError = errorMessage(err, 'settings')
        this.saveErrorStep = 'complete'
        return 'error'
      } finally {
        this.saving = null
      }
    },

    /** The onboarding is over (here or elsewhere): the learner of the auth store says so, the intro slides are seen. */
    markCompleted(): void {
      markSeen(INTRO_KEY)
      if (this.data) this.data.completed = true
      const learner = useAuthStore().learner
      if (learner) {
        learner.onboarding_completed = true
        learner.onboarding = { completed: true, step: null }
      }
    },

    /** The error of a step is not news any more (the learner changed the answer). */
    clearError(): void {
      this.saveError = ''
      this.saveErrorStep = null
      this.nameError = null
    },
  },
})
