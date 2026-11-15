// Shared helpers of the k6 scenarios (docs/STAGING_PHASE2_CHECKLIST.md §7.3).
// k6 modules run in k6's own runtime (not Node): `open()` works only in the init context.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';

export const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/+$/, '');
export const TOKENS_FILE = __ENV.TOKENS_FILE || '/data/load-tokens.json';

// Pause multiplier: 1 = a learner who reads a card for 3-6 s; 0.5 doubles the load per learner.
export const THINK_SCALE = parseFloat(__ENV.THINK_SCALE || '1');

// Any 5xx fails the run (threshold `server_errors: count==0`); a 429 means the harness itself is faster than the
// per-learner limiters (`twa-api` / `twa-review` 60 per minute): the scenario is then wrong, not the server.
export const serverErrors = new Counter('server_errors');
export const rateLimited = new Counter('rate_limited');

// 2xx and 3xx are "expected"; the rest counts into http_req_failed.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }));

/** Learners of the token file (written by `php artisan load:tokens`): shared by all VUs, parsed once. */
export function loadTokenFile() {
  return JSON.parse(open(TOKENS_FILE));
}

export function sharedLearners(name) {
  return new SharedArray(name, () => loadTokenFile().learners);
}

/**
 * Course slug and expiry of the token file, for setup(): `open()` is init-context only, so the file is read here once and
 * shared (not the learners again: 1 250 tokens per VU would be hundreds of MB).
 */
export function sharedTokenFileMeta(name) {
  return new SharedArray(name, () => {
    const file = loadTokenFile();

    return [{ course: file.course, expires_at: file.expires_at }];
  });
}

/** Token of virtual user `vu` (1-based): one learner per VU, so the per-learner limiters see realistic traffic. */
export function learnerFor(learners, vu) {
  return learners[(vu - 1) % learners.length];
}

export function authHeaders(token) {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' };
}

/** Counts 5xx / 429 of a response and returns it unchanged. */
export function track(res) {
  if (res.status >= 500) {
    serverErrors.add(1);
  }
  if (res.status === 429) {
    rateLimited.add(1);
  }
  return res;
}

/** GET with a bearer token; `name` becomes the `endpoint` tag the thresholds are set on. */
export function get(path, token, name) {
  return track(http.get(`${BASE_URL}${path}`, { headers: authHeaders(token), tags: { endpoint: name, name } }));
}

/** Parallel GETs from one screen, with the same tags and error counters as get(). */
export function getBatch(requests, token) {
  const headers = authHeaders(token);
  return http.batch(requests.map(([path, name]) => ({
    method: 'GET',
    url: `${BASE_URL}${path}`,
    params: { headers, tags: { endpoint: name, name } },
  }))).map(track);
}

export function post(path, body, token, name) {
  return track(
    http.post(`${BASE_URL}${path}`, JSON.stringify(body), { headers: authHeaders(token), tags: { endpoint: name, name } }),
  );
}

export function ok(res, name) {
  return check(res, { [`${name}: 200`]: (r) => r.status === 200 });
}

/** A think-time pause of min..max seconds, scaled by THINK_SCALE. */
export function think(min, max) {
  sleep(THINK_SCALE * (min + Math.random() * (max - min)));
}

/** Parsed JSON body, or null when the body is not JSON (an error page). */
export function json(res) {
  try {
    return res.json();
  } catch (e) {
    return null;
  }
}

// ---------------------------------------------------------------------------------------------------------------------
// Helpers of the scenarios beyond the hot paths (flows.js): ids, random answers, optional media.
// ---------------------------------------------------------------------------------------------------------------------

/** RFC 4122 version 4 UUID (`request_id` of an answer: the server validates the `uuid` rule and de-duplicates on it). */
export function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.floor(Math.random() * 16);
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function pick(list) {
  return list[Math.floor(Math.random() * list.length)];
}

function shuffled(list) {
  const copy = list.slice();
  for (let i = copy.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy;
}

/**
 * An answer for an element of a practice session, a game run or a battle round, built from what the API shows of it. The API never
 * sends the right answers (docs/16 §12), so this is a LEARNER WHO GUESSES: the server does the same work for a wrong answer as for
 * a right one (grading, the snapshot, the state, the counters), which is what the load test measures; the browser e2e
 * (resources/twa/scripts/e2e) reads the snapshot from the database to answer right, a load test against a remote staging cannot.
 * Consequence: a game run of a guesser usually ends when the flasks are out (a terminal state, counted as completed), a practice
 * session or a battle runs to its last element.
 */
export function answerFor(element) {
  if (Array.isArray(element.options) && element.options.length > 0) {
    return { option_id: pick(element.options).id };
  }
  if (Array.isArray(element.fragments) && element.fragments.length > 0) {
    return { order: shuffled(element.fragments.map((f) => f.id)) };
  }
  if (Array.isArray(element.left) && Array.isArray(element.right) && element.right.length > 0) {
    // A round of pairs: one pair per answer, the first left card not matched yet with any right card.
    const matched = new Set((element.matched || []).map((m) => m[0]));
    const left = element.left.find((card) => !matched.has(card.id)) || element.left[0];
    return { left_id: left.id, right_id: pick(element.right).id };
  }
  if (element.answer_length !== undefined || element.masked !== undefined || element.audio_url !== undefined || element.words !== undefined) {
    return { text: 'test' };
  }
  return { skip: true };
}

/**
 * GET of a media file (the clip of a task): 200 is a clip, 404 / 503 mean the staging has no audio for it - not a server error of the
 * scenario, so they are counted apart (`audio_missing`) and kept out of `server_errors` and `http_req_failed`.
 */
export const audioMissing = new Counter('audio_missing');

export function getAudio(path, token, name) {
  const res = http.get(`${BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: '*/*' },
    tags: { endpoint: name, name },
    responseCallback: http.expectedStatuses({ min: 200, max: 399 }, 404, 503),
  });
  if (res.status === 429) {
    rateLimited.add(1);
  } else if (res.status !== 200) {
    audioMissing.add(1);
  }
  return res;
}
