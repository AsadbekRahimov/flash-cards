// Telegram webhook (NFR-01): POST /telegram/webhook/{secret} answers < 300 ms, the work goes to the `high` queue.
//
// The fixture (fixtures/telegram-update.json) is `/start_training` from a supergroup that is not registered: the handler
// drops it silently (Group Lock, docs/12), so the run measures the webhook itself and the queue, and sends nothing to
// Telegram. Every request has its own update_id and sender, so the per-user inbound limit does not swallow the load.
//
// Needs: TELEGRAM_URL_SECRET and TELEGRAM_HEADER_SECRET (the staging values of the same names). The caller's IP must pass
// TELEGRAM_IP_ALLOWLIST_ENABLED (switch it off on staging for the run, or run from an allowed address).
//
// Run: `make load-test-webhook`.
import http from 'k6/http';
import { check, fail } from 'k6';
// `server_errors` is counted by track() and checked in the thresholds below.
import { BASE_URL, track } from './lib.js';

const URL_SECRET = __ENV.TELEGRAM_URL_SECRET || '';
const HEADER_SECRET = __ENV.TELEGRAM_HEADER_SECRET || '';
const RPS = parseInt(__ENV.WEBHOOK_RPS || '50', 10);
const HOLD = __ENV.HOLD || '5m';

const template = open('./fixtures/telegram-update.json');

export const options = {
  scenarios: {
    webhook: {
      executor: 'ramping-arrival-rate',
      startRate: 1,
      timeUnit: '1s',
      preAllocatedVUs: Math.max(50, RPS),
      maxVUs: RPS * 4,
      stages: [
        { duration: '1m', target: Math.round(RPS / 2) },
        { duration: '1m', target: RPS },
        { duration: HOLD, target: RPS },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    server_errors: ['count==0'],
    http_req_failed: ['rate<0.01'],
    // NFR-01: the webhook answers fast; the handler itself runs in the queue.
    http_req_duration: ['p(95)<300'],
    'http_req_duration{endpoint:telegram_webhook}': ['p(95)<300'],
    checks: ['rate>0.99'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  if (URL_SECRET === '' || HEADER_SECRET === '') {
    fail('Set TELEGRAM_URL_SECRET and TELEGRAM_HEADER_SECRET (the staging values).');
  }
}

export default function () {
  // __VU * 1e6 + __ITER keeps update ids unique across VUs; the sender id is a "stranger" from a range of its own.
  const updateId = 900000000 + __VU * 1000000 + __ITER;
  const body = template
    .replaceAll('__UPDATE_ID__', String(updateId))
    .replaceAll('__USER_ID__', String(9000000000 + updateId))
    .replaceAll('__DATE__', String(Math.floor(Date.now() / 1000)));

  const res = track(
    http.post(`${BASE_URL}/telegram/webhook/${URL_SECRET}`, body, {
      headers: { 'Content-Type': 'application/json', 'X-Telegram-Bot-Api-Secret-Token': HEADER_SECRET },
      tags: { endpoint: 'telegram_webhook', name: 'telegram_webhook' },
    }),
  );

  check(res, {
    'webhook: 200': (r) => r.status === 200,
    'webhook: ok:true': (r) => r.status === 200 && r.json('ok') === true,
  });
}
