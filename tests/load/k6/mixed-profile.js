// A MIXED profile (audit 2026-11-10 §2 item 3(e)): the learners of one evening split over the activities the way the audit counts
// the cost of an active learner. The flows of twa-training-mature.js, game-caravan.js, practice-session.js and battle.js (flows.js)
// run side by side in one run, so the server sees the sum - and the contention between them (Redis limiters, the pool of pgbouncer,
// the Octane workers) that no single scenario shows. The browsing of twa-hot-paths.js (Today, profile, courses, practice hub,
// catalog: flows.js browse()) is the fifth flow, MIX_BROWSE, 0 by default so the documented default mix stays as it was.
//
// TARGET_VUS is the number of ACTIVE LEARNERS; MIX_* are their shares (a sum below 1 leaves the rest idle, above 1 is refused).
// Requests a minute per learner from the pacing of flows.js (THINK_SCALE=1, mean think times, the server time not counted;
// tests/load/k6/README.md "Смешанный профиль" has the sums):
//   MIX_TRAINING  0.45  mature-card training            10 requests / 39.5 s  ~15
//   MIX_GAME      0.20  caravan runs                    ~7 requests / 21.5 s  ~20 (a guesser loses the flasks after ~4 answers)
//   MIX_PRACTICE  0.20  practice sessions (PRACTICE_MODE)  7 requests / 25 s  ~17 (collocation, 5 elements)
//   MIX_BATTLE    0.15  learners in a battle (one VU per pair)  ~79 requests / ~67 s per pair  ~35 per learner
//   MIX_BROWSE    0     Today, profile, courses, hub, catalog  5 requests / 9 s  ~33 (6 for a member of a group: ~40)
// At 1250 learners: 1250 x (0.45 x 15.2 + 0.2 x 19.5 + 0.2 x 16.8 + 0.15 x 35) / 60 = ~400 requests a second of INTENDED demand;
// the capacity is the measured http_reqs/s of the steady hold. Suggested with browsing: MIX_BROWSE=0.15 and the rest x 0.85 (0.38 /
// 0.17 / 0.17 / 0.13): 1250 x (0.38 x 15.2 + 0.17 x 19.5 + 0.17 x 16.8 + 0.13 x 35 + 0.15 x 33.3) / 60 = ~448 a second.
//
// Every request and custom metric of an iteration carries the tag `flow` (training | game | practice | battle | browse, a tag of the
// VU), and the thresholds name a no-op sub-metric per flow, so the end-of-test summary shows http_reqs, http_req_duration and
// flow_completed of each flow apart; battle_endings{reason:...} tells a match played to its end from a room that closed.
//
// Who plays what (plan.js allocateMix / mixSlot): the shares are rounded by the largest remainder, so the participants never
// outnumber TARGET_VUS, and the token file is cut into ranges that do not overlap - the battle pairs first (indexes 0-1, 2-3, ...:
// the friends of LoadScenarioSeeder), then training, game, practice, browse. The mix is ONE scenario: k6 deals __VU over all the
// scenarios of a test in the order their VUs start, so separate scenarios could not tell their n-th VU; here the flow and the
// learner follow from __VU alone. A token file of TARGET_VUS learners is enough.
//
// Thresholds: the union of the single scenarios. Run: `make load-test-mixed`.
import { fail, sleep } from 'k6';
import exec from 'k6/execution';
import { BASE_URL, get, json, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { battle, browse, gameRun, matureTraining, practiceSession, unlockedStops } from './flows.js';
import { allocateMix, MIX_FLOWS, mixSlot, plannedRunSeconds, rampStages, tokenProblem } from './plan.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '1250', 10);
const HOLD = __ENV.HOLD || '10m';
const CARDS_PER_SESSION = parseInt(__ENV.CARDS_PER_SESSION || '8', 10);
const PRACTICE_MODE = __ENV.PRACTICE_MODE || 'collocation';
const BATTLE_MODE = __ENV.BATTLE_MODE || 'collocation';
// A battle iteration lasts a match (up to ~5 minutes for a stuck invitation): its last one gets 120 s to end on the ramp-down.
const GRACEFUL_RAMP_DOWN = '120s';

const share = (name, fallback) => {
  const value = parseFloat(__ENV[name] === undefined ? String(fallback) : __ENV[name]);
  return Number.isFinite(value) && value >= 0 ? value : fallback;
};
// Throws (and so stops k6 before the start) on a sum of shares above 1 or a bad TARGET_VUS.
const mix = allocateMix(TARGET_VUS, {
  training: share('MIX_TRAINING', 0.45),
  game: share('MIX_GAME', 0.2),
  practice: share('MIX_PRACTICE', 0.2),
  battle: share('MIX_BATTLE', 0.15),
  browse: share('MIX_BROWSE', 0),
});
if (mix.totalVus === 0) {
  throw new Error('The mix has no learners: TARGET_VUS and the MIX_* shares give 0 VUs.');
}
const stages = rampStages(mix.totalVus, HOLD);

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  flow_completed: ['rate>0.95'],
};
if (mix.training > 0) {
  thresholds.training_review_coverage = ['avg>=0.9'];
  thresholds.training_distractor_cards = ['rate>0.3'];
}
for (const name of ['training_open', 'training_review', 'training_finish', 'game_answer', 'game_map', 'practice_answer', 'practice_hub', 'battle_poll', 'battle_answer', 'battle_round']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
}
for (const name of ['game_start', 'practice_open', 'battle_invite']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<600'];
}
if (mix.browse > 0) {
  // The hot paths of twa-hot-paths.js (practice_hub is above already).
  for (const name of ['today', 'assignments', 'me', 'courses', 'billing_catalog']) {
    thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
  }
}
// No-op thresholds: k6 prints a sub-metric in the end-of-test summary (and --summary-export) only when a threshold names it. They
// always pass; they are here to split the summary by flow and the matches by how they ended.
for (const flow of MIX_FLOWS.filter((name) => mix.vus[name] > 0)) {
  thresholds[`http_reqs{flow:${flow}}`] = ['count>=0'];
  thresholds[`http_req_duration{flow:${flow}}`] = ['max>=0'];
  thresholds[`flow_completed{flow:${flow}}`] = ['rate>=0'];
}
if (mix.pairs > 0) {
  for (const reason of ['finished', 'closed', 'abandoned', 'poll_failed']) {
    thresholds[`battle_endings{reason:${reason}}`] = ['count>=0'];
  }
}

export const options = {
  scenarios: {
    mixed: { executor: 'ramping-vus', startVUs: 0, stages, gracefulRampDown: GRACEFUL_RAMP_DOWN },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const file = tokenFileMeta[0];
  const problem = tokenProblem(learners, file, mix.participants, plannedRunSeconds(stages, GRACEFUL_RAMP_DOWN));
  if (problem) {
    fail(problem);
  }
  console.log(`mix of ${TARGET_VUS}: training ${mix.training}, game ${mix.game}, practice ${mix.practice}, battle ${mix.pairs} pairs `
    + `(${2 * mix.pairs} learners), browse ${mix.browse} = ${mix.participants} learners in ${mix.totalVus} VUs; tokens ${JSON.stringify(mix.ranges)}`);

  const res = get('/api/twa/me', learners[0].token, 'setup');
  if (res.status !== 200 || json(res) === null) {
    fail(`GET /api/twa/me answered ${res.status}: ${BASE_URL} is not the staging the tokens were issued on?`);
  }
  let lessons = [];
  if (mix.game > 0) {
    const stops = unlockedStops(learners[mix.ranges.game.start].token, file.course);
    if (stops.status !== 200 || stops.lessons.length === 0) {
      fail(`The game map answered ${stops.status} with ${stops.lessons.length} unlocked stops: GAME_ACCESS, or run LoadScenarioSeeder (tests/load/k6/README.md).`);
    }
    lessons = stops.lessons.slice(0, 20);
  }

  return { course: file.course, lessons };
}

// The slot of this VU, worked out on its first iteration (init code runs once per VU too, but also in k6's own init pass).
let slot;
// The device of a browsing VU's learner (flows.js openToday(): what it remembers of the last `/today`).
const device = {};

/**
 * Sets a tag of this VU: k6 adds it to every metric sample the VU emits from then on (requests, checks, custom metrics). k6 1.x has
 * `exec.vu.metrics.tags`; `exec.vu.tags` is the older name of the same map, kept as a fallback.
 */
function tagVu(name, value) {
  const tags = exec.vu.metrics && exec.vu.metrics.tags ? exec.vu.metrics.tags : exec.vu.tags;
  tags[name] = value;
}

export default function (data) {
  if (slot === undefined) {
    slot = mixSlot(mix, __VU);
  }
  if (slot === null) {
    // A VU number beyond the plan (not with one scenario on one k6 instance): sit it out rather than share a learner.
    console.warn(`VU ${__VU} is outside the ${mix.totalVus} VUs of the mix: idle.`);
    sleep(60);
    return;
  }
  // Set on every iteration (cheap, and safe should a version of k6 reset the VU tags between iterations).
  tagVu('flow', slot.flow);
  const [first, second] = slot.tokens;
  switch (slot.flow) {
    case 'training':
      matureTraining(learners[first].token, CARDS_PER_SESSION);
      break;
    case 'game':
      gameRun(learners[first].token, data.course, data.lessons);
      break;
    case 'practice':
      practiceSession(learners[first].token, data.course, PRACTICE_MODE, null);
      break;
    case 'browse':
      browse(learners[first].token, data.course, device);
      break;
    default:
      battle(learners[first], learners[second], data.course, BATTLE_MODE);
  }
}
