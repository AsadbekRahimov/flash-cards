import type { OnboardingState, OnboardingValues } from '../onboardingTypes'

/** `GET /onboarding` of a newcomer (docs/40 §2.1): nothing saved, the options of the contract; `values` / the rest may be overridden. */
export function onboardingFixture(overrides: Partial<Omit<OnboardingState, 'values'>> & { values?: Partial<OnboardingValues> } = {}): OnboardingState {
  const { values, ...rest } = overrides
  return {
    completed: false,
    step: 'name',
    options: {
      age_ranges: ['lt14', '14_17', '18_24', '25_34', '35_44', '45p'],
      goals: ['career', 'study', 'travel', 'relocation', 'communication', 'media'],
      levels: [
        { level: 'A1', levels: ['A1', 'A2'], course_slug: 'english-a1-a2', title: 'A1–A2', stages_count: 20 },
        { level: 'B1', levels: ['B1'], course_slug: 'english-b1', title: 'B1', stages_count: 20 },
        { level: 'B2', levels: ['B2'], course_slug: 'english-b2', title: 'B2', stages_count: 20 },
        { level: 'C1', levels: ['C1'], course_slug: 'english-c1', title: 'C1', stages_count: 20 },
        { level: 'C2', levels: ['C2'], course_slug: 'english-c2', title: 'C2', stages_count: 20 },
      ],
      daily_goals: [10, 20, 40],
      daily_goal_max: null,
      recommended_daily_goal: 20,
      reminder_times: ['08:00', '12:30', '18:00', '20:00', '21:30'],
      reminder_step_minutes: 15,
    },
    ...rest,
    values: {
      display_name: null,
      first_name: 'Aziz',
      age_range: null,
      goals: null,
      level: null,
      course: null,
      daily_goal: null,
      reminder: null,
      ...values,
    },
  }
}
