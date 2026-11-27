/**
 * The onboarding of the Mini App (docs/40 §2.1, docs/04 A.24): `GET /onboarding`, `PUT /onboarding/steps/{step}`,
 * `POST /onboarding/complete`. Every step writes its own column at once; the server keeps the step to show next.
 */

/** The steps the server knows, in their order; `plan` is the summary (O8), nothing is saved on it. */
export const ONBOARDING_STEPS = ['name', 'age', 'goals', 'level', 'daily_goal', 'reminder', 'plan'] as const

export type OnboardingStep = (typeof ONBOARDING_STEPS)[number]

/** The steps that are saved with a PUT. */
export type OnboardingSavedStep = Exclude<OnboardingStep, 'plan'>

export const AGE_RANGES = ['lt14', '14_17', '18_24', '25_34', '35_44', '45p'] as const

export type AgeRange = (typeof AGE_RANGES)[number]

export const ONBOARDING_GOALS = ['career', 'study', 'travel', 'relocation', 'communication', 'media'] as const

export type OnboardingGoal = (typeof ONBOARDING_GOALS)[number]

/** What the learner said to Telegram's "allow messages" request (O7); the server only puts it into the event. */
export type WriteAccess = 'granted' | 'denied' | 'not_asked'

/** A row of O5: a course of the catalog for one or more levels (A1 and A2 lead to one course -> one row "A1–A2"). */
export interface OnboardingLevelOption {
  /** The level that is sent back (`PUT steps/level {level}`): the first of `levels`. */
  level: string
  levels: string[]
  course_slug: string
  /** The name of the course in the learner's language ("Английский B1"). */
  title: string
  /** The levels of the row as the chip shows them ("A1–A2", "B1"; "A1, C1" for levels that are not neighbours). */
  level_label: string
  stages_count: number | null
}

/** The course the chosen level leads to. */
export interface OnboardingCourse {
  slug: string
  title: string
  level_label: string | null
  stages_count: number | null
}

export interface OnboardingReminder {
  enabled: boolean
  /** `HH:MM`; null when off. */
  time: string | null
}

export interface OnboardingValues {
  /** The name the learner chose (O2); null - not chosen yet. Shown only on the learner's own screens (docs/40 §3, В1). */
  display_name: string | null
  /** Telegram's snapshot; not the hint of O2 (that one is `initDataUnsafe`). */
  first_name: string | null
  age_range: AgeRange | null
  goals: OnboardingGoal[] | null
  level: string | null
  course: OnboardingCourse | null
  daily_goal: number | null
  reminder: OnboardingReminder | null
}

export interface OnboardingOptions {
  age_ranges: AgeRange[]
  goals: OnboardingGoal[]
  levels: OnboardingLevelOption[]
  daily_goals: number[]
  /** The highest goal the plan allows; null = no limit (docs/05 §8c-1). */
  daily_goal_max: number | null
  recommended_daily_goal: number | null
  reminder_times: string[]
  reminder_step_minutes: number
}

/** `OnboardingStateResource`. */
export interface OnboardingState {
  completed: boolean
  /** The next step to show; null - not started. */
  step: OnboardingStep | null
  values: OnboardingValues
  options: OnboardingOptions
}

/** The bodies of `PUT /onboarding/steps/{step}`. */
export interface OnboardingStepBodies {
  name: { display_name: string }
  age: { age_range: AgeRange }
  goals: { goals: OnboardingGoal[] }
  level: { level: string; course_slug: string }
  daily_goal: { daily_goal: number }
  reminder: { enabled: boolean; time: string | null; write_access: WriteAccess }
}

/** `POST /onboarding/complete`. */
export interface OnboardingCompleteResponse {
  completed: true
  course: { slug: string; title: string } | null
  next_lesson_id: number | null
}

/** `onboarding` of the learner in `/auth` and `/me`; absent on an older server. */
export interface LearnerOnboarding {
  completed: boolean
  step: string | null
}
