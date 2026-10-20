import type { LearnerSettings } from '@/api/types'

/** A complete `settings` block of GET /me for specs that build a MeResponse by hand. */
export function settingsFixture(overrides: Partial<LearnerSettings> = {}): LearnerSettings {
  return {
    interface_locale: 'ru',
    timezone: 'Asia/Tashkent',
    reminder_time: '19:00',
    daily_goal: 20,
    goal: null,
    level: null,
    leagues_opt_out: false,
    timezone_change_available_at: null,
    timezone_undo_available_until: null,
    active_course: null,
    exercises: { reverse: true, audio: true, spelling: true, gap_fill: true },
    options: {
      locales: [
        { id: 'ru', label: '🇷🇺 Русский' },
        { id: 'uz', label: "🇺🇿 O'zbekcha" },
      ],
      timezones: [
        { id: 'Asia/Tashkent', label: 'Ташкент (UTC+5)' },
        { id: 'Europe/Moscow', label: 'Москва (UTC+3)' },
      ],
      reminder_times: ['08:00', '13:00', '19:00', '21:00'],
      daily_goals: [10, 20, 40],
    },
    ...overrides,
  }
}
