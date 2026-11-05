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
