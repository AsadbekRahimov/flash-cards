// Practice (audit 2026-11-10 §2 item 3(c)): a session of ONE mode from the hub to the last answer. PRACTICE_MODE = pairs |
// collocation (default) | sentence | dictation: run the script once per mode to see which one is the expensive one. 10-40 requests a
// minute per learner (an answer is ~8 SQL, a session open builds a snapshot of the material).
//
// The API never sends the right answers: the learner guesses (lib.js answerFor); the server grades a wrong answer the same way.
//
// Needs on the staging: PRACTICE_ACCESS=all (or pilot: the seeder puts the test learners in it), PRACTICE_FREE_SESSIONS_PER_DAY empty
// or large (the Free limit is 2 a day: 403 `practice_limit_reached`, counted in `flow_aborted`), published practice items for the course
// (`php artisan practice:import ... --publish`) and, for the dictation, their audio.
//
// Run: `make load-test-practice PRACTICE_MODE=sentence`.
import { fail } from 'k6';
import { BASE_URL, get, json, learnerFor, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { practiceSession, rampStages, tokenProblem } from './flows.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '300', 10);
const HOLD = __ENV.HOLD || '10m';
const MODE = __ENV.PRACTICE_MODE || 'collocation';

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  flow_completed: ['rate>0.95'],
  'http_req_duration{endpoint:practice_answer}': ['p(95)<300'],
  'http_req_duration{endpoint:practice_open}': ['p(95)<600'],
  'http_req_duration{endpoint:practice_hub}': ['p(95)<300'],
};

export const options = {
  scenarios: {
    practice: { executor: 'ramping-vus', startVUs: 0, stages: rampStages(TARGET_VUS, HOLD), gracefulRampDown: '30s' },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  if (!['pairs', 'collocation', 'sentence', 'dictation'].includes(MODE)) {
    fail('PRACTICE_MODE must be pairs, collocation, sentence or dictation.');
  }
  const file = tokenFileMeta[0];
  const problem = tokenProblem(learners, file, TARGET_VUS);
  if (problem) {
    fail(problem);
  }
  const res = get(`/api/twa/practice/hub?course=${encodeURIComponent(file.course)}`, learners[0].token, 'setup');
  const hub = json(res);
  if (res.status !== 200 || hub === null) {
    fail(`GET /api/twa/practice/hub answered ${res.status}: PRACTICE_ACCESS is off, or ${BASE_URL} is not the staging the tokens were issued on.`);
  }
  const mode = (hub.modes || []).find((m) => m.mode === MODE);
  if (!mode || (mode.state && mode.state !== 'available')) {
    fail(`The mode ${MODE} is not available for the course ${file.course} (${JSON.stringify(mode || null)}): import and publish the practice items, run the LoadScenarioSeeder.`);
  }

  return { course: file.course, mode: MODE };
}

export default function (data) {
  practiceSession(learnerFor(learners, __VU).token, data.course, data.mode, null);
}
