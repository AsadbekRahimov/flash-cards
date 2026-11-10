// Training with MATURE cards (audit 2026-11-10 §2 item 3(a)): twa-hot-paths.js opens lessons with fresh cards, which are all flash
// cards. Here every learner has graduated, due cards (`db:seed --class=LoadScenarioSeeder`), so a daily-review session serves
// reverse and audio cards - their options are built by the server from a pool of distractors (ExercisePromptBuilder), the
// steady state of an SRS app and the heaviest `review` the server has - and, for Pro learners, spelling and gap-fill.
//
// Pacing and thresholds as in twa-hot-paths.js (p95 < 300 ms, no 5xx, no 429); `training_distractor_cards` (rate > 0.3) fails the
// run when the cards served were not mature: the seed was not run, or the due cards ran out (re-run the seeder).
//
// Run: `make load-test-mature` (tests/load/k6/README.md).
import { fail } from 'k6';
import { BASE_URL, get, json, learnerFor, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { matureTraining, rampStages, tokenProblem } from './flows.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '1250', 10);
const HOLD = __ENV.HOLD || '10m';
const CARDS_PER_SESSION = parseInt(__ENV.CARDS_PER_SESSION || '8', 10);

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  http_req_duration: ['p(95)<300'],
  training_review_coverage: ['avg>=0.9'],
  training_distractor_cards: ['rate>0.3'],
  flow_completed: ['rate>0.95'],
};
for (const name of ['training_open', 'training_review', 'training_finish']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
}

export const options = {
  scenarios: {
    learners: { executor: 'ramping-vus', startVUs: 0, stages: rampStages(TARGET_VUS, HOLD), gracefulRampDown: '30s' },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const problem = tokenProblem(learners, tokenFileMeta[0], TARGET_VUS);
  if (problem) {
    fail(problem);
  }
  if (!Number.isInteger(CARDS_PER_SESSION) || CARDS_PER_SESSION < 1) {
    fail('CARDS_PER_SESSION must be a positive integer.');
  }
  const res = get('/api/twa/me', learners[0].token, 'setup');
  if (res.status !== 200 || json(res) === null) {
    fail(`GET /api/twa/me answered ${res.status}: ${BASE_URL} is not the staging the tokens were issued on?`);
  }
}

export default function () {
  matureTraining(learnerFor(learners, __VU).token, CARDS_PER_SESSION);
}
