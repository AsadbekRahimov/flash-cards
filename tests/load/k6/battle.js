// Battle (audit 2026-11-10 §2 item 3(d)): two learners play a match from the invitation to the result, polling on the beat of the
// client since the poll change (`POLL_INTERVAL_MS` in resources/twa/src/stores/battle.ts: 5 s while the invitation waits, 3 s in the
// room, 2 s from the countdown on). One VU = one PAIR (it plays both sides, tick by tick), so TARGET_PAIRS VUs are 2 x TARGET_PAIRS
// learners and ~2 x 30 polls a minute per pair plus the answers: ~40 requests a minute per player.
//
// The polls are outside `twa-api` (their own limiter, 90 a minute), `battle_poll` has its own threshold. The API never sends the right
// answers: the players guess (lib.js answerFor). BATTLE_MODE = collocation (default) | sentence | dictation | pairs.
//
// Needs on the staging: PRACTICE_ACCESS=all (or pilot), PRACTICE_BATTLE=true, PRACTICE_FREE_BATTLES_PER_DAY empty or large (Free: 1 a
// day), and the friends of LoadScenarioSeeder (pairs 1-2, 3-4, ...). `twa-battle-invite` allows 5 invitations a minute per learner.
//
// Run: `make load-test-battle`.
import { fail } from 'k6';
import { sharedLearners, sharedTokenFileMeta } from './lib.js';
import { battle, battlePair, rampStages, tokenProblem } from './flows.js';

const TARGET_PAIRS = parseInt(__ENV.TARGET_PAIRS || '100', 10);
const HOLD = __ENV.HOLD || '10m';
const MODE = __ENV.BATTLE_MODE || 'collocation';

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  flow_completed: ['rate>0.95'],
  'http_req_duration{endpoint:battle_poll}': ['p(95)<300'],
  'http_req_duration{endpoint:battle_answer}': ['p(95)<300'],
  'http_req_duration{endpoint:battle_round}': ['p(95)<300'],
  'http_req_duration{endpoint:battle_invite}': ['p(95)<600'],
};

export const options = {
  scenarios: {
    battle: { executor: 'ramping-vus', startVUs: 0, stages: rampStages(TARGET_PAIRS, HOLD), gracefulRampDown: '120s' },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  if (!['pairs', 'collocation', 'sentence', 'dictation'].includes(MODE)) {
    fail('BATTLE_MODE must be pairs, collocation, sentence or dictation.');
  }
  const file = tokenFileMeta[0];
  const problem = tokenProblem(learners, file, TARGET_PAIRS * 2);
  if (problem) {
    fail(problem);
  }

  return { course: file.course, mode: MODE };
}

export default function (data) {
  // The VU numbers of one scenario are consecutive, so `(__VU - 1) % pairs` gives every VU a pair of its own.
  const [a, b] = battlePair(learners, 0, (__VU - 1) % TARGET_PAIRS);
  battle(a, b, data.course, data.mode);
}
