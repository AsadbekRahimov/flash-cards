// Hot paths of a learner in the Mini App (docs/11 O4, NFR-03): "Today" with assignments, profile, courses, practice hub,
// billing catalog, training (open -> review cycle -> finish).
//
// Thresholds (owner's decision 03.11.2026): p95 < 300 ms on every hot endpoint at 1 250 active learners, no 5xx.
//
// Pacing: one VU = one learner with a token of its own. `twa-api` allows 60 requests a minute per learner for ALL routes,
// `twa-review` 60 and `twa-session-create` 10 more. Like the Mini App (resources/twa/src/stores/training.ts, submit()), the
// scenario gets the first card from `POST /training/sessions` and subsequent cards from `POST /review`. The
// fallback `GET /next` is for older servers without a `card` key. With CARDS_PER_SESSION=8 an iteration makes
// 16 requests (6 browsing + open + 8 review + finish) with at least ~30.5 s of pauses at THINK_SCALE=1 (0.5-1.5 s,
// 4 x 1-3 s, 8 x 3-6 s, 2-5 s; the request time is not counted), so the worst case is ~31 requests a minute against
// `twa-api` 60, ~16 reviews against `twa-review` 60 and ~2 session opens against `twa-session-create` 10: a well-paced run
// never sees a 429 (the `rate_limited` threshold checks exactly that).
//
// Run: `make load-test` (see docs/STAGING_PHASE2_CHECKLIST.md §7.3).
import { check, fail } from 'k6';
import { Trend } from 'k6/metrics';
// `server_errors` and `rate_limited` (lib.js) are counted by get()/post() and checked in the thresholds below.
import { BASE_URL, get, getBatch, post, ok, think, json, learnerFor, sharedLearners, sharedTokenFileMeta } from './lib.js';

const TARGET_VUS = parseInt(__ENV.TARGET_VUS || '1250', 10);
const HOLD = __ENV.HOLD || '10m';
const CARDS_PER_SESSION = parseInt(__ENV.CARDS_PER_SESSION || '8', 10);

const learners = sharedLearners('learners');
const tokenFileMeta = sharedTokenFileMeta('token-file-meta');
const reviewCoverage = new Trend('training_review_coverage');

const HOT = ['me', 'today', 'assignments', 'courses', 'practice_hub', 'billing_catalog', 'training_open', 'training_review'];

const thresholds = {
  server_errors: ['count==0'],
  rate_limited: ['count==0'],
  checks: ['rate>0.99'],
  http_req_failed: ['rate<0.01'],
  http_req_duration: ['p(95)<300'],
  // An exhausted course or daily quota can make a run look fast by skipping most of its writes.
  training_review_coverage: ['avg>=0.9'],
  // finish writes the summary and may evaluate referrals: still must stay in the same budget.
  'http_req_duration{endpoint:training_finish}': ['p(95)<300'],
};
for (const name of HOT) {
  thresholds[`http_req_duration{endpoint:${name}}`] = ['p(95)<300'];
}

export const options = {
  scenarios: {
    learners: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '2m', target: Math.round(TARGET_VUS * 0.2) },
        { duration: '3m', target: Math.round(TARGET_VUS * 0.5) },
        { duration: '3m', target: TARGET_VUS },
        { duration: HOLD, target: TARGET_VUS },
        { duration: '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  const file = tokenFileMeta[0];

  if (learners.length < TARGET_VUS) {
    fail(`The token file holds ${learners.length} learners, TARGET_VUS is ${TARGET_VUS}: run \`load:tokens --count=${TARGET_VUS}\`.`);
  }
  if (!Number.isInteger(CARDS_PER_SESSION) || CARDS_PER_SESSION < 1) {
    fail('CARDS_PER_SESSION must be a positive integer.');
  }
  if (file.expires_at * 1000 < Date.now() + 30 * 60 * 1000) {
    fail('The tokens expire in less than 30 minutes: issue new ones with `load:tokens`.');
  }
  if (!file.course) {
    fail('The token file has no course: publish a course, then run `load:tokens` again.');
  }

  const res = get(`/api/twa/courses/${file.course}`, learners[0].token, 'setup');
  const detail = json(res);
  if (res.status !== 200 || detail === null) {
    fail(`GET /api/twa/courses/${file.course} answered ${res.status}: ${BASE_URL} is not the staging the tokens were issued on?`);
  }

  const lessonIds = [];
  for (const stage of detail.stages || []) {
    for (const lesson of stage.lessons || []) {
      lessonIds.push(lesson.id);
    }
  }
  if (lessonIds.length === 0) {
    fail(`The course ${file.course} has no lessons.`);
  }

  return { course: file.course, lessonIds: lessonIds.slice(0, 20) };
}

export default function (data) {
  const learner = learnerFor(learners, __VU);
  const token = learner.token;

  // 1. The main screen loads the two reads in parallel, as TodayView does.
  const [today, assignments] = getBatch([
    ['/api/twa/today', 'today'],
    ['/api/twa/assignments', 'assignments'],
  ], token);
  ok(today, 'today');
  ok(assignments, 'assignments');
  think(0.5, 1.5);
  ok(get('/api/twa/me', token, 'me'), 'me');
  think(1, 3);

  // 2. Browsing: courses, practice hub, billing catalog.
  ok(get('/api/twa/courses', token, 'courses'), 'courses');
  think(1, 3);
  ok(get(`/api/twa/practice/hub?course=${data.course}`, token, 'practice_hub'), 'practice_hub');
  think(1, 3);
  ok(get('/api/twa/billing/catalog', token, 'billing_catalog'), 'billing_catalog');
  think(1, 3);

  // 3. Training: a lesson session, cards one by one.
  const lessonId = data.lessonIds[(__VU + __ITER) % data.lessonIds.length];
  const opened = post('/api/twa/training/sessions', { mode: 'lesson', lesson_id: lessonId, source: 'self' }, token, 'training_open');
  if (!ok(opened, 'training_open')) {
    reviewCoverage.add(0);
    think(5, 10);
    return;
  }
  const start = json(opened);
  if (!check(start, { 'training_open: session id': (body) => Number.isInteger(body?.session_id) })) {
    reviewCoverage.add(0);
    think(5, 10);
    return;
  }
  const sessionId = start.session_id;

  let card = start.card;
  if (!('card' in start)) {
    const next = get(`/api/twa/training/sessions/${sessionId}/next`, token, 'training_next');
    card = ok(next, 'training_next') ? json(next)?.card : null;
  }

  let reviewedCards = 0;
  while (reviewedCards < CARDS_PER_SESSION && card) {
    think(3, 6); // the learner reads the card and answers

    const typed = card.prompt && (card.prompt.type === 'spelling' || card.prompt.type === 'gap_fill');
    const body = {
      word_id: card.word_id,
      time_spent_ms: 3000 + Math.floor(Math.random() * 3000),
      serve_token: card.serve_token,
    };
    if (typed) {
      body.answer = 'test';
    } else {
      body.quality = 3 + Math.floor(Math.random() * 3); // 3..5
    }
    const reviewed = post(`/api/twa/training/sessions/${sessionId}/review`, body, token, 'training_review');
    if (!ok(reviewed, 'training_review')) {
      break;
    }
    reviewedCards++;

    // The next card rides on the review answer. `card: null` ends the session; an answer without the `card` key
    // makes the client fetch it with `GET /next` (the old API shape).
    const answer = json(reviewed);
    if (answer !== null && 'card' in answer) {
      card = answer.card;
    } else {
      const next = get(`/api/twa/training/sessions/${sessionId}/next`, token, 'training_next');
      const step = ok(next, 'training_next') ? json(next) : null;
      card = step === null ? null : step.card;
    }
  }

  ok(post(`/api/twa/training/sessions/${sessionId}/finish`, {}, token, 'training_finish'), 'training_finish');
  reviewCoverage.add(reviewedCards / CARDS_PER_SESSION);
  const missing = CARDS_PER_SESSION - reviewedCards;
  think(2 + missing * 3, 5 + missing * 6);
}
