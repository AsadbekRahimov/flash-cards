// The game "Caravan" (audit 2026-11-10 §2 item 3(b)): a run of one stop from its start to its end - the map, `POST /game/runs`
// (the snapshot of the run is built here), every answer, the summary. 10-40 requests a minute per learner.
//
// The API never sends the right answers, so the learner of this script guesses (lib.js answerFor): the server does the same work for
// a wrong answer, and a run of a guesser ends when the flasks are out or at the last element - both count as completed.
//
// Needs on the staging: GAME_ACCESS=all (or pilot: the seeder puts the test learners in it), GAME_FREE_RUNS_PER_DAY empty or large
// (a Free learner has 1 run a day, the run then answers 403/409 `game_limit_*` and the scenario says so in `flow_aborted`), the
// seed of tests/load/k6/README.md.
//
// Run: `make load-test-game`.
import { fail } from 'k6';
import { learnerFor, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { gameRun, rampStages, tokenProblem, unlockedStops } from './flows.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '300', 10);
const HOLD = __ENV.HOLD || '10m';

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');

// The first numbers of a run that has no baseline yet: the answer is ~8 SQL (audit §2), the start builds a snapshot. Adjust to the
// first measurement on the real hardware.
const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  flow_completed: ['rate>0.95'],
  'http_req_duration{endpoint:game_answer}': ['p(95)<300'],
  'http_req_duration{endpoint:game_start}': ['p(95)<600'],
  'http_req_duration{endpoint:game_map}': ['p(95)<300'],
  'http_req_duration{endpoint:game_summary}': ['p(95)<300'],
};

export const options = {
  scenarios: {
    caravan: { executor: 'ramping-vus', startVUs: 0, stages: rampStages(TARGET_VUS, HOLD), gracefulRampDown: '30s' },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const file = tokenFileMeta[0];
  const problem = tokenProblem(learners, file, TARGET_VUS);
  if (problem) {
    fail(problem);
  }
  const stops = unlockedStops(learners[0].token, file.course);
  if (stops.status !== 200) {
    fail(`GET /api/twa/game/map answered ${stops.status}: GAME_ACCESS is off, or this is not the staging the tokens were issued on.`);
  }
  if (stops.lessons.length === 0) {
    fail('No unlocked stop on the map: run `php artisan db:seed --class=LoadScenarioSeeder` (it introduces the learners to the words of the course).');
  }

  return { course: file.course, lessons: stops.lessons.slice(0, 20) };
}

export default function (data) {
  gameRun(learnerFor(learners, __VU).token, data.course, data.lessons);
}
