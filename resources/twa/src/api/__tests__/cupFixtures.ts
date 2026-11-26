import type {
  CupBoardResponse,
  CupDuel,
  CupDuelAvailability,
  CupDuelListResponse,
  CupFields,
  CupInvite,
  CupListResponse,
  CupRound,
  CupRules,
  CupSetup,
  CupStandingRow,
  CupTask,
} from '../types'

/** Fixtures of the friends' cup (docs/04 A.23) in the shapes `CupContractTest` holds on the server. */

export const cupRules = (over: Partial<CupRules> = {}): CupRules => ({
  points_per_answer: 2,
  typo_points: 0,
  max_day_points: 20,
  counted_days: 5,
  days: 7,
  max_total: 100,
  ...over,
})

export const cupFields = (over: Partial<CupFields> = {}): CupFields => ({
  id: 5,
  status: 'running',
  group_title: 'English Friends',
  level: 'A2',
  days: 7,
  counted_days: 5,
  round_tasks: 10,
  round_minutes: 10,
  timezone: 'Asia/Tashkent',
  // Day 1 begins at midnight of Tashkent, 1 December (19:00 UTC the day before).
  starts_at: '2026-11-30T19:00:00.000Z',
  ends_at: '2026-12-07T19:00:00.000Z',
  day: 3,
  summaries_enabled: true,
  summary_time: '20:00',
  paused: null,
  ...over,
})

export const cupInvite = (over: Partial<CupInvite> = {}): CupInvite => ({
  ...cupFields(),
  participants_count: 4,
  rules: cupRules(),
  me: { joined: false, participant_id: null, status: null, display_name: 'Aziza', left: false },
  ...over,
})

export const standing = (over: Partial<CupStandingRow> = {}): CupStandingRow => ({
  participant_id: 1,
  rank: 1,
  display_name: 'Aziza',
  pro_emoji: null,
  total: 30,
  round_points: 30,
  days_played: 2,
  left: false,
  me: false,
  ...over,
})

export const cupBoard = (over: Partial<CupBoardResponse> = {}): CupBoardResponse => ({
  cup: { ...cupFields(), rules: cupRules() },
  standings: [
    standing({ participant_id: 1, rank: 1, display_name: 'Aziza', total: 30, pro_emoji: '🦄' }),
    standing({ participant_id: 2, rank: 2, display_name: 'Me', total: 20, me: true }),
    standing({ participant_id: 3, rank: 2, display_name: 'Bobur', total: 20 }),
    standing({ participant_id: 4, rank: 4, display_name: 'Dilnoza', total: 4, left: true }),
  ],
  me: {
    participant_id: 2,
    display_name: 'Me',
    status: 'active',
    first_day: 1,
    renames_left: 3,
    total: 20,
    final_rank: null,
    today: { state: 'not_started', day: 3, attempt_id: null, points: null, expires_at: null },
    days: [1, 2, 3, 4, 5, 6, 7].map((day) => ({
      day,
      points: day === 1 ? 12 : day === 2 ? 8 : 0,
      round_points: day === 1 ? 12 : day === 2 ? 8 : 0,
      duel_points: 0,
      played: day <= 2,
      counted: day <= 2,
      available: true,
    })),
  },
  can_manage: false,
  server_now: '2026-12-02T10:00:00.000Z',
  ...over,
})

export const cupTask = (over: Partial<CupTask> = {}): CupTask => ({
  position: 1,
  id: 'e1',
  kind: 'translate_fwd',
  prompt: 'apple',
  audio: false,
  options: [
    { id: 'o1', text: 'яблоко' },
    { id: 'o2', text: 'груша' },
    { id: 'o3', text: 'слива' },
    { id: 'o4', text: 'вишня' },
  ],
  ...over,
})

export const cupRound = (over: Partial<CupRound> = {}): CupRound => ({
  attempt_id: 77,
  cup_id: 5,
  participant_id: 2,
  day: 3,
  status: 'active',
  finish_reason: null,
  total: 10,
  answered: 0,
  correct: 0,
  points: 0,
  max_points: 20,
  position: 1,
  started_at: '2026-12-02T10:00:00.000Z',
  expires_at: '2026-12-02T10:10:00.000Z',
  server_now: '2026-12-02T10:00:00.000Z',
  current: cupTask(),
  answers: [],
  review: null,
  ...over,
})

export const finishedRound = (over: Partial<CupRound> = {}): CupRound =>
  cupRound({
    status: 'finished',
    finish_reason: 'completed',
    answered: 10,
    correct: 7,
    points: 14,
    position: 10,
    current: null,
    review: [
      { element_id: 'e1', kind: 'translate_fwd', verdict: 'correct', points: 2, answer: { option_id: 'o1' }, reveal: { correct_id: 'o1', correct_text: 'яблоко' } },
      { element_id: 'e2', kind: 'spell', verdict: 'typo', points: 0, answer: { text: 'aple' }, reveal: { correct_text: 'apple' } },
      { element_id: 'e3', kind: 'gap', verdict: null, points: 0, answer: null, reveal: { correct_text: 'tea' } },
    ],
    ...over,
  })

export const cupSetup = (over: Partial<CupSetup> = {}): CupSetup => ({
  ...cupFields({ status: 'draft', starts_at: null, ends_at: null, day: null }),
  locale: 'ru',
  summary_minute: 1200,
  cancel_reason: null,
  options: { levels: ['A1', 'A2', 'B1'], locales: ['ru', 'uz'] },
  rules: cupRules(),
  ...over,
})

export const cupListItem = (over: Partial<CupListResponse['cups'][number]> = {}): CupListResponse['cups'][number] => ({
  ...cupFields(),
  me: { participant_id: 2, display_name: 'Me', status: 'active', total: 20, days_played: 2, final_rank: null },
  ...over,
})

/** May I challenge today (`me.duel` / `availability`, docs/04 A.23d). */
export const duelAvailability = (over: Partial<CupDuelAvailability> = {}): CupDuelAvailability => ({
  day: 3,
  can_challenge: true,
  rated_available: true,
  challenges_left: 20,
  open_duel_id: null,
  ...over,
})

/** A duel (`CupDuelResource`): by default my open personal challenge to Aziza (participant 1), rated, 10 minutes to go. */
export const cupDuel = (over: Partial<CupDuel> = {}): CupDuel => ({
  id: 41,
  status: 'open',
  end_reason: null,
  rated_offered: true,
  rated: null,
  public: false,
  mode: 'pairs',
  day: 3,
  accepted_day: null,
  expires_at: '2026-12-02T10:10:00.000Z',
  battle_id: null,
  role: 'challenger',
  challenger: { participant_id: 2, display_name: 'Me', pro_emoji: null },
  opponent: { participant_id: 1, display_name: 'Aziza', pro_emoji: '🦄' },
  points: null,
  winner_participant_id: null,
  server_now: '2026-12-02T10:00:00.000Z',
  ...over,
})

/** GET /cups/{id}/duels. */
export const duelList = (over: Partial<CupDuelListResponse> = {}): CupDuelListResponse => ({
  incoming: [],
  open: [],
  mine: null,
  today: [],
  availability: duelAvailability(),
  server_now: '2026-12-02T10:00:00.000Z',
  ...over,
})
