// A BURST of Mini App opens (audit 2026-11-18 «Как получить рабочий предел RPS», items 2-3): a broadcast of the bot or an announcement
// in a class group makes many learners tap the button within a minute. The other scenarios hold a fixed number of VUs, and a VU
// waits for its answer before it sends the next request - so when the server slows down they slow down with it and the load eases off
// by itself. Here the opens ARRIVE at a set rate (ramping-arrival-rate) whatever the server does: an open the generator could not start
// in time (all VUs busy, maxVUs reached) is not sent later but counted in `dropped_iterations`.
//
// From 0 to BURST_RATE opens a second over BURST_RAMP (default 30s), held for HOLD (default 2m). One iteration = one open by one
// learner, with no think time:
//   - a self-study learner (1 - CLASS_SHARE of the opens): `GET /today`, then `GET /assignments` only when the answer does not say
//     `assignments_possible: false` (stores/assignments.ts; every learner of load:tokens is in no group, so it is skipped), `GET /me`;
//   - a member of a class who opens the link of an announcement (`/twa/?from=assignment&lesson=<id>`, CLASS_SHARE of the opens): the
//     device remembers "maybe", so `/today` and `/assignments` go in parallel, `GET /me`, then the lesson opens at once
//     (`POST /training/sessions {mode: lesson, source: assignment}`) - the first open assignment of the list, or, when the list is
//     empty, the first lesson of the course (the lesson of the announcement). LoadScenarioSeeder makes no centers, groups or
//     assignments, so on a staging seeded only by `make load-seed` the list is empty and `/today` says false: `burst_assignment_found`
//     shows that share (0 = the lesson came from the course, not from an assignment).
//
// Learners (plan.js burstToken): the open number i (exec.scenario.iterationInTest, unique over the run) plays the token i mod the size
// of the file, so a learner opens again only after all the others have - at least BURST_REOPEN_GAP_SECONDS (60 s) later at the full
// rate; setup() refuses a file too small for that (burstPool). `/api/twa/auth` (initData HMAC and its limiter) is NOT under load: the
// tokens are issued in advance by load:tokens.
//
// Thresholds: p95 < 300 ms on the requests of an open (and on the lesson open), no 5xx, no 429, `dropped_iterations` at most
// BURST_MAX_DROPPED (default 0). Run: `make load-test-burst`.
import { fail } from 'k6';
import exec from 'k6/execution';
import { Rate } from 'k6/metrics';
import { BASE_URL, get, json, ok, sharedLearners, sharedTokenFileMeta } from './lib.js';
import { openAssignedLesson, openToday } from './flows.js';
import {
  BURST_REOPEN_GAP_SECONDS,
  burstPool,
  burstProblem,
  burstStages,
  burstToken,
  burstVus,
  isClassOpen,
  plannedOpens,
  plannedRunSeconds,
  tokenProblem,
} from './plan.js';

const BURST_RATE = Number(__ENV.BURST_RATE || '20');
const BURST_RAMP = __ENV.BURST_RAMP || '30s';
const HOLD = __ENV.HOLD || '2m';
const CLASS_SHARE = Number(__ENV.CLASS_SHARE === undefined || __ENV.CLASS_SHARE === '' ? '0.2' : __ENV.CLASS_SHARE);
const MAX_DROPPED = parseInt(__ENV.BURST_MAX_DROPPED || '0', 10);
// The default of the arrival-rate executors: the opens started before the end get 30 s to finish.
const GRACEFUL_STOP = '30s';

// Throws (and so stops k6 before the start) on a bad rate, duration or share: the stages are built from them.
const problem = burstProblem(BURST_RATE, BURST_RAMP, HOLD, CLASS_SHARE);
if (problem) {
  throw new Error(problem);
}
if (!Number.isInteger(MAX_DROPPED) || MAX_DROPPED < 0) {
  throw new Error(`BURST_MAX_DROPPED must be a whole number >= 0, got ${__ENV.BURST_MAX_DROPPED}.`);
}
const stages = burstStages(BURST_RATE, BURST_RAMP, HOLD);
const OPENS = plannedOpens(BURST_RATE, BURST_RAMP, HOLD);
const POOL = burstPool(BURST_RATE, OPENS);
const vus = burstVus(BURST_RATE, parseInt(__ENV.BURST_PREALLOCATED_VUS || '', 10), parseInt(__ENV.BURST_MAX_VUS || '', 10));

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');
/** Class opens whose `/assignments` listed an open assignment (its lesson was opened); 0 on a staging without centers and groups. */
const assignmentFound = new Rate('burst_assignment_found');

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  // Opens the generator could not start on time: either maxVUs is too low for the latency, or the server is too slow for the rate.
  dropped_iterations: [`count<=${MAX_DROPPED}`],
};
for (const name of ['today', 'assignments', 'me']) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
}
if (CLASS_SHARE > 0) {
  thresholds['http_req_duration{endpoint:training_open}'] = ['p(95)<300'];
}

export const options = {
  scenarios: {
    burst: {
      executor: 'ramping-arrival-rate',
      startRate: 0,
      timeUnit: '1s',
      preAllocatedVUs: vus.preAllocatedVUs,
      maxVUs: vus.maxVUs,
      stages,
      gracefulStop: GRACEFUL_STOP,
    },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const file = tokenFileMeta[0];
  const refusal = tokenProblem(learners, file, POOL, plannedRunSeconds(stages, GRACEFUL_STOP));
  if (refusal) {
    fail(refusal);
  }
  // tokenProblem() has made sure of the gap: learners.length >= POOL, so a reopen comes >= BURST_REOPEN_GAP_SECONDS later.
  const reuse = OPENS <= learners.length
    ? 'each learner opens once'
    : `up to ${Math.ceil(OPENS / learners.length)} opens a learner, again after >= ${Math.floor(learners.length / BURST_RATE)} s (at least ${BURST_REOPEN_GAP_SECONDS})`;
  console.log(`burst: 0 -> ${BURST_RATE} opens/s over ${BURST_RAMP}, held ${HOLD}: ~${OPENS} opens (${Math.round(CLASS_SHARE * 100)} % class) `
    + `by ${Math.min(OPENS, learners.length)} learners, ${reuse}; VUs ${vus.preAllocatedVUs}..${vus.maxVUs}`);

  const res = get(`/api/twa/courses/${file.course}`, learners[0].token, 'setup');
  const detail = json(res);
  if (res.status !== 200 || detail === null) {
    fail(`GET /api/twa/courses/${file.course} answered ${res.status}: ${BASE_URL} is not the staging the tokens were issued on?`);
  }
  // The lesson of the announcement when the list of assignments is empty: the first lesson of the course, the one every learner can open.
  const first = (detail.stages || []).flatMap((stage) => stage.lessons || [])[0];
  const lessonId = first ? first.id : null;
  if (CLASS_SHARE > 0 && lessonId === null) {
    fail(`The course ${file.course} has no lessons: the class opens have nothing to open (CLASS_SHARE=0 runs without them).`);
  }

  return { lessonId };
}

export default function (data) {
  const open = exec.scenario.iterationInTest;
  const token = learners[burstToken(open, learners.length)].token;
  const member = isClassOpen(open, CLASS_SHARE);

  // The device of a member remembers "maybe" from its earlier opens; a self-study learner's remembers false or nothing - the same path.
  const { assignments } = openToday(token, { assignmentsPossible: member ? true : undefined });
  ok(get('/api/twa/me', token, 'me'), 'me');
  if (!member) {
    return;
  }

  const list = assignments !== null && assignments.status === 200 ? json(assignments)?.assignments : null;
  const assigned = Array.isArray(list) ? list.find((item) => !item.completed && Number.isInteger(item.lesson_id)) : undefined;
  assignmentFound.add(assigned !== undefined);
  openAssignedLesson(token, assigned !== undefined ? assigned.lesson_id : data.lessonId);
}
