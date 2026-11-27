/**
 * The flag of the intro slides of "Today" (`TodayView`, `IntroSheet`, `seenFlag`): the first visit of a newcomer opens them once. A
 * learner who went through the onboarding of the Mini App has already been told what the app is (docs/40 §2.1), so the onboarding sets
 * it when it is finished (`stores/onboarding.ts`). One key for both.
 */
export const INTRO_KEY = 'lexiflow:intro-seen'
