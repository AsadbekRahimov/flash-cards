// A MIXED profile (audit 2026-11-10 §2 item 3(e)): the learners of one evening split over the activities the way the audit counts
// the cost of an active learner (requests a minute): training ~13-20, game ~10-40, practice ~10-40, battle ~40. The flows of
// twa-training-mature.js, game-caravan.js, practice-session.js and battle.js (flows.js) run side by side in one run, so the server sees
// the sum - and the contention between them (Redis limiters, the pool of pgbouncer, the Octane workers) that no single scenario shows.
// The browsing of twa-hot-paths.js (Today, profile, courses, catalog) is not in it: run that script separately for the light path.
//
// TARGET_VUS is the number of ACTIVE LEARNERS; MIX_* are their shares (they need not add up to 1; the rest is ignored):
//   MIX_TRAINING  0.45  mature-card training            ~16 req/min per learner
//   MIX_GAME      0.20  caravan runs                    ~20
//   MIX_PRACTICE  0.20  practice sessions (PRACTICE_MODE)  ~20
//   MIX_BATTLE    0.15  learners in a battle (PAIRS = share x TARGET_VUS / 2)  ~40
// At 1250 learners that is 1250 x (0.45 x 16 + 0.2 x 20 + 0.2 x 20 + 0.15 x 40) = ~28 000 requests a minute = ~470 a second at the
// server (plus the polls). The token file needs TARGET_VUS learners; the battle pairs take the LAST 2 x pairs of them (the
// seeder pairs 1-2, 3-4, ...), the other VUs the first ones: no learner is played by two VUs.
//
// Thresholds: the union of the single scenarios. Run: `make load-test-mixed`.
import { fail } from 'k6';
import { BASE_URL, get, json, learnerFor, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { battle, battlePair, gameRun, matureTraining, practiceSession, rampStages, tokenProblem, unlockedStops } from './flows.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '1250', 10);
const HOLD = __ENV.HOLD || '10m';
const CARDS_PER_SESSION = parseInt(__ENV.CARDS_PER_SESSION || '8', 10);
const PRACTICE_MODE = __ENV.PRACTICE_MODE || 'collocation';
const BATTLE_MODE = __ENV.BATTLE_MODE || 'collocation';

const share = (name, fallback) => {
  const value = parseFloat(__ENV[name] === undefined ? String(fallback) : __ENV[name]);
  return Number.isFinite(value) && value >= 0 ? value : fallback;
};
const training = Math.round(TARGET_VUS * share('MIX_TRAINING', 0.45));
const game = Math.round(TARGET_VUS * share('MIX_GAME', 0.2));
const practice = Math.round(TARGET_VUS * share('MIX_PRACTICE', 0.2));
const pairs = Math.round((TARGET_VUS * share('MIX_BATTLE', 0.15)) / 2);
const solo = training + game + practice;
// The pool of the battle: the last `2 x pairs` learners of the file, from an even index (the seeder's pairs are 1-2, 3-4, ...).
const needed = solo + 2 * pairs;

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');
const poolStart = Math.floor((learners.length - 2 * pairs) / 2) * 2;

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  flow_completed: ['rate>0.95'],
  training_review_coverage: ['avg>=0.9'],
  training_distractor_cards: ['rate>0.3'],
};
for (const name of ['training_open', 'training_review', 'training_finish', 'game_answer', 'game_map', 'practice_answer', 'practice_hub', 'battle_poll', 'battle_answer', 'battle_round']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
}
for (const name of ['game_start', 'practice_open', 'battle_invite']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<600'];
}

function ramp(exec, target, grace) {
  return { executor: 'ramping-vus', exec, startVUs: 0, stages: rampStages(target, HOLD), gracefulRampDown: grace };
}

const scenarios = {};
if (training > 0) scenarios.training = ramp('trainingFlow', training, '30s');
if (game > 0) scenarios.game = ramp('gameFlow', game, '30s');
if (practice > 0) scenarios.practice = ramp('practiceFlow', practice, '30s');
if (pairs > 0) scenarios.battle = ramp('battleFlow', pairs, '120s');

export const options = {
  scenarios,
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const file = tokenFileMeta[0];
  const problem = tokenProblem(learners, file, needed);
  if (problem) {
    fail(problem);
  }
  // The VUs of the solo scenarios take the learner (__VU - 1) of the file: the VU ids run up to solo + pairs, all of them below the pool.
  if (solo + pairs > poolStart) {
    fail(`${solo + pairs} VUs and ${pairs} battle pairs do not fit ${learners.length} learners: run \`make load-tokens TARGET_VUS=${needed + pairs}\`.`);
  }

  const res = get('/api/twa/me', learners[0].token, 'setup');
  if (res.status !== 200 || json(res) === null) {
    fail(`GET /api/twa/me answered ${res.status}: ${BASE_URL} is not the staging the tokens were issued on?`);
  }
  let lessons = [];
  if (game > 0) {
    const stops = unlockedStops(learners[0].token, file.course);
    if (stops.status !== 200 || stops.lessons.length === 0) {
      fail(`The game map answered ${stops.status} with ${stops.lessons.length} unlocked stops: GAME_ACCESS, or run LoadScenarioSeeder (tests/load/k6/README.md).`);
    }
    lessons = stops.lessons.slice(0, 20);
  }

  return { course: file.course, lessons };
}

export function trainingFlow() {
  matureTraining(learnerFor(learners, __VU).token, CARDS_PER_SESSION);
}

export function gameFlow(data) {
  gameRun(learnerFor(learners, __VU).token, data.course, data.lessons);
}

export function practiceFlow(data) {
  practiceSession(learnerFor(learners, __VU).token, data.course, PRACTICE_MODE, null);
}

export function battleFlow(data) {
  const [a, b] = battlePair(learners, poolStart, (__VU - 1) % pairs);
  battle(a, b, data.course, BATTLE_MODE);
}
