// One iteration of each kind of learner activity of the Mini App, as functions: the scenario files (twa-hot-paths.js,
// twa-training-mature.js, game-caravan.js, practice-session.js, battle.js, arrival-burst.js) call one each, mixed-profile.js calls all
// of them side by side. The shapes of the calls follow resources/twa/src (views/TodayView.vue, stores/assignments.ts, training.ts,
// game, practice, battle) and docs/04 A.x.
//
// Pacing follows the cost table of docs/AUDIT_2026-11-10_RU.md §2: training ~13-20 requests a minute per learner, game and
// practice 10-40, battle ~40 (two polls every 2 s for a pair = 30 a minute per player, plus the answers). All of the
// non-poll requests share the per-learner limiter `twa-api` (60 a minute): the think times keep a well-paced run under it, and the
// `rate_limited` threshold of the scenarios says when they do not.
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { get, getBatch, post, ok, think, json, uuid, answerFor, getAudio, pick } from './lib.js';

/**
 * Flows that ended on a refusal or an unexpected answer of the API (limit reached, no material, 4xx): the run measured less than it
 * should. Tags: `step` (where it stopped: training_mature, game_run, practice_answer, battle, ...) and `status`; the mix adds `flow`.
 */
export const flowAborted = new Counter('flow_aborted');
/** A flow that reached its natural end (a session finished, a run ended, a match closed): `rate>0.95` in the thresholds. */
export const flowCompleted = new Rate('flow_completed');
/** Mature training: how many of the served cards carry options (the meaning / reverse / audio path with the distractors). */
export const distractorCards = new Rate('training_distractor_cards');
export const cardsServed = new Counter('training_cards_served');
export const trainingCoverage = new Trend('training_review_coverage');
export const gameAnswers = new Counter('game_answers');
export const practiceAnswers = new Counter('practice_answers');
export const battleAnswers = new Counter('battle_answers');
export const battleDuration = new Trend('battle_duration_s');
/**
 * How a match ended, tag `reason`: `finished` - played to its last round; `closed` - the room closed before that (cancelled,
 * declined, the invitation or the room timed out on the server); `abandoned` - still open after 5 minutes, the script cancelled or
 * surrendered it; `poll_failed` - the poll answered no JSON. `flow_completed` counts the first two alike; this tells them apart.
 */
export const battleEndings = new Counter('battle_endings');
/** Opens of Today that asked `GET /assignments` (true) or skipped it on `assignments_possible: false` (false). */
export const assignmentsRequested = new Rate('assignments_requested');

const BATTLE_POLL_SECONDS = { invited: 5, room: 3, countdown: 2, playing: 2, finished: 2, closed: 2 };

function refused(res, what) {
  flowAborted.add(1, { step: what, status: String(res.status) });
  flowCompleted.add(false);
  const body = json(res);
  // The code of the refusal is the thing to read in the output of k6 (limits, access, material): print the first of each kind.
  if (!refused.seen) {
    refused.seen = {};
  }
  const key = `${what}:${res.status}:${body?.error?.code || body?.code || ''}`;
  if (!refused.seen[key]) {
    refused.seen[key] = true;
    console.warn(`${what}: ${res.status} ${(res.body || '').slice(0, 200)}`);
  }
}

function elapsed(since) {
  return (Date.now() - since) / 1000;
}

// ---------------------------------------------------------------------------------------------------------------------
// (0) Opening the Mini App and browsing: Today, profile, courses, practice hub, catalog
// ---------------------------------------------------------------------------------------------------------------------

/**
 * The first screen, the way TodayView.vue loads it: `GET /today` and, by the rule of stores/assignments.ts, `GET /assignments`.
 * `device` is what the device remembers of the last `/today` of this learner (`{ assignmentsPossible: undefined | true | false }`,
 * localStorage `lexiflow:assignments-possible` in the client) and is updated here:
 *  - remembered true (a member of a center's group): the list goes out at once, in parallel with `/today`;
 *  - otherwise (first open, or the last answer was false): `/today` first; `assignments_possible: false` - no `/assignments` at all;
 *    true or no field (an older server) - the list is asked then.
 * A failed `/today` asks nothing more (the client has no answer to follow). Returns the two responses (`assignments` null when not
 * asked).
 */
export function openToday(token, device) {
  const early = device.assignmentsPossible === true;
  let today;
  let assignments = null;
  if (early) {
    [today, assignments] = getBatch([
      ['/api/twa/today', 'today'],
      ['/api/twa/assignments', 'assignments'],
    ], token);
  } else {
    today = get('/api/twa/today', token, 'today');
  }
  const todayOk = ok(today, 'today');
  if (todayOk) {
    const body = json(today);
    // Exactly false skips the list; absent (an older server) or true keeps the old path.
    device.assignmentsPossible = !(body !== null && body.assignments_possible === false);
    if (!early && device.assignmentsPossible) {
      assignments = get('/api/twa/assignments', token, 'assignments');
    }
  }
  assignmentsRequested.add(assignments !== null);
  if (assignments !== null) {
    ok(assignments, 'assignments');
  }
  return { today, assignments };
}

/**
 * Opening the Mini App and looking around (the light path of twa-hot-paths.js, docs/11 O4): Today (+ assignments by the rule of
 * openToday()), profile, courses, practice hub, billing catalog. 5 requests (6 for a member of a group) with ~9 s of pauses at
 * THINK_SCALE=1 (0.5-1.5 s, 4 x 1-3 s): ~33 requests a minute of a learner who only browses, under `twa-api` 60.
 */
export function browse(token, course, device) {
  openToday(token, device);
  think(0.5, 1.5);
  ok(get('/api/twa/me', token, 'me'), 'me');
  think(1, 3);

  ok(get('/api/twa/courses', token, 'courses'), 'courses');
  think(1, 3);
  ok(get(`/api/twa/practice/hub?course=${encodeURIComponent(course)}`, token, 'practice_hub'), 'practice_hub');
  think(1, 3);
  ok(get('/api/twa/billing/catalog', token, 'billing_catalog'), 'billing_catalog');
  think(1, 3);
}

/**
 * The lesson of an announcement (`/twa/?from=assignment&lesson=<id>`, TodayView.vue): after Today the client opens the lesson at once
 * with `source: assignment`; an open session of the same lesson is returned again, so an open of the same learner a minute later does
 * not stack sessions. Returns the response (201 / 200 with `session_id` and the first card).
 */
export function openAssignedLesson(token, lessonId) {
  const opened = post('/api/twa/training/sessions', { mode: 'lesson', lesson_id: lessonId, source: 'assignment' }, token, 'training_open');
  if (ok(opened, 'training_open')) {
    flowCompleted.add(true);
  } else {
    refused(opened, 'class_lesson');
  }
  return opened;
}

// ---------------------------------------------------------------------------------------------------------------------
// (a) Training with mature cards
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A daily-review session over cards that are due and graduated (LoadScenarioSeeder): the exercise selector serves meaning / reverse / audio
 * (and, for a Pro learner, spelling / gap-fill) cards, whose options are built by the server from a pool of distractors - the path
 * the fresh-card scenario never takes. open -> `cards` x review -> finish.
 */
export function matureTraining(token, cards) {
  const opened = post('/api/twa/training/sessions', { mode: 'daily_review', source: 'self' }, token, 'training_open');
  if (!ok(opened, 'training_open')) {
    refused(opened, 'training_mature');
    trainingCoverage.add(0);
    think(5, 10);
    return;
  }
  const start = json(opened);
  if (!check(start, { 'training_open: session id': (body) => Number.isInteger(body?.session_id) })) {
    flowCompleted.add(false);
    think(5, 10);
    return;
  }
  const sessionId = start.session_id;

  let card = start.card;
  if (!('card' in start)) {
    const next = get(`/api/twa/training/sessions/${sessionId}/next`, token, 'training_next');
    card = ok(next, 'training_next') ? json(next)?.card : null;
  }

  let reviewed = 0;
  while (reviewed < cards && card) {
    cardsServed.add(1, { type: card.prompt?.type || card.card_kind || 'flash' });
    distractorCards.add(Array.isArray(card.prompt?.options) && card.prompt.options.length > 0);
    think(3, 6);

    // The kind of grade follows the exercise (docs/04 A.3): a typed card takes text, a four-option card (meaning / reverse / audio /
    // pretest) takes one of its options - the server grades both and refuses a `quality` for them - and a flash card the learner's own grade.
    const typed = card.prompt && (card.prompt.type === 'spelling' || card.prompt.type === 'gap_fill');
    const options = Array.isArray(card.prompt?.options) ? card.prompt.options : [];
    const body = { word_id: card.word_id, time_spent_ms: 3000 + Math.floor(Math.random() * 3000), serve_token: card.serve_token, exercise: card.prompt?.type || 'flash' };
    if (typed) {
      body.answer = 'test';
    } else if (options.length > 0) {
      body.answer = pick(options);
    } else {
      body.quality = 3 + Math.floor(Math.random() * 3);
    }
    const res = post(`/api/twa/training/sessions/${sessionId}/review`, body, token, 'training_review');
    if (!ok(res, 'training_review')) {
      break;
    }
    reviewed++;

    const answer = json(res);
    if (answer !== null && 'card' in answer) {
      card = answer.card;
    } else {
      const next = get(`/api/twa/training/sessions/${sessionId}/next`, token, 'training_next');
      card = ok(next, 'training_next') ? json(next)?.card : null;
    }
  }

  ok(post(`/api/twa/training/sessions/${sessionId}/finish`, {}, token, 'training_finish'), 'training_finish');
  trainingCoverage.add(reviewed / cards);
  // Fewer cards than asked for means the due cards ran out: re-arm the learners (LoadScenarioSeeder) or lower HOLD.
  flowCompleted.add(reviewed >= Math.min(cards, 1));
  think(2 + (cards - reviewed) * 3, 5 + (cards - reviewed) * 6);
}

// ---------------------------------------------------------------------------------------------------------------------
// (b) The game "Caravan": a run from its start to its end
// ---------------------------------------------------------------------------------------------------------------------

/** Stops (lesson ids) the learner has opened on the map of the course: a run needs a stop that is unlocked. */
export function unlockedStops(token, course) {
  const res = get(`/api/twa/game/map?course=${encodeURIComponent(course)}`, token, 'setup');
  const map = json(res);
  const lessons = [];
  for (const city of map?.cities || []) {
    for (const stop of city.stops || []) {
      if (stop.unlocked) {
        lessons.push(stop.lesson_id);
      }
    }
  }
  return { status: res.status, lessons };
}

/**
 * `GET /game/map` -> `POST /game/runs` (stop) -> answers until the run is over -> `GET /game/runs/{id}` (the summary). A guesser loses
 * flasks, so the run ends on the last element or when the flasks are out; both are "finished" and both count. The clip of a
 * `listen` task is fetched the way the client does.
 */
export function gameRun(token, course, lessons) {
  ok(get(`/api/twa/game/map?course=${encodeURIComponent(course)}`, token, 'game_map'), 'game_map');
  think(1, 3);

  const lessonId = lessons[(__VU + __ITER) % lessons.length];
  const started = post('/api/twa/game/runs', { kind: 'stop', course, lesson_id: lessonId, replace_active: true }, token, 'game_start');
  if (started.status !== 201) {
    refused(started, 'game_run');
    think(5, 10);
    return;
  }
  let run = json(started);
  if (!check(run, { 'game_start: run id': (body) => Number.isInteger(body?.id) })) {
    flowCompleted.add(false);
    return;
  }

  // The budget: a pairs round takes several answers per element; nothing here may spin forever.
  let budget = (run.total || 10) * 4 + 10;
  while (run.status === 'active' && run.current?.element && budget-- > 0) {
    const element = run.current.element;
    if (element.audio === true) {
      getAudio(`/api/twa/game/runs/${run.id}/audio/${element.id}`, token, 'game_audio');
    }
    think(2, 5);

    const res = post(`/api/twa/game/runs/${run.id}/answer`, {
      request_id: uuid(),
      element_id: element.id,
      time_spent_ms: 1500 + Math.floor(Math.random() * 3500),
      answer: answerFor(element),
    }, token, 'game_answer');
    if (!ok(res, 'game_answer')) {
      refused(res, 'game_answer');
      break;
    }
    gameAnswers.add(1);
    const next = json(res)?.run;
    if (!next) {
      break;
    }
    run = next;
  }

  if (run.status === 'active') {
    // Left on purpose or out of budget: close it, so the next iteration does not meet a live run.
    post(`/api/twa/game/runs/${run.id}/abandon`, {}, token, 'game_abandon');
    flowCompleted.add(false);
  } else {
    ok(get(`/api/twa/game/runs/${run.id}`, token, 'game_summary'), 'game_summary');
    flowCompleted.add(true);
  }
  think(3, 8);
}

// ---------------------------------------------------------------------------------------------------------------------
// (c) Practice: a session of one mode
// ---------------------------------------------------------------------------------------------------------------------

/**
 * `GET /practice/hub` -> `POST /practice/sessions` -> answers -> the summary rides on the last answer. `mode` is one of pairs /
 * collocation / sentence / dictation (PRACTICE_MODE); a lesson id is optional (the whole course when null).
 */
export function practiceSession(token, course, mode, lessonId) {
  ok(get(`/api/twa/practice/hub?course=${encodeURIComponent(course)}`, token, 'practice_hub'), 'practice_hub');
  think(1, 3);

  const body = { mode, course, source: 'hub', replace_active: true };
  if (lessonId) {
    body.lesson_id = lessonId;
  }
  const opened = post('/api/twa/practice/sessions', body, token, 'practice_open');
  if (opened.status !== 201) {
    refused(opened, 'practice_session');
    think(5, 10);
    return;
  }
  let session = json(opened);
  if (!check(session, { 'practice_open: session id': (s) => Number.isInteger(s?.id) })) {
    flowCompleted.add(false);
    return;
  }

  let budget = (session.progress?.total || 10) * 4 + 10;
  while (session.status === 'active' && session.current && budget-- > 0) {
    const element = session.current;
    if (typeof element.audio_url === 'string' && element.audio_url.startsWith('/')) {
      getAudio(element.audio_url, token, 'practice_audio');
    }
    think(2, 5);

    const res = post(`/api/twa/practice/sessions/${session.id}/answer`, {
      request_id: uuid(),
      element_id: element.id,
      time_spent_ms: 1500 + Math.floor(Math.random() * 3500),
      answer: answerFor(element),
    }, token, 'practice_answer');
    if (!ok(res, 'practice_answer')) {
      refused(res, 'practice_answer');
      break;
    }
    practiceAnswers.add(1);
    const next = json(res)?.session;
    if (!next) {
      break;
    }
    session = next;
  }

  flowCompleted.add(session.status !== 'active');
  think(3, 8);
}

// ---------------------------------------------------------------------------------------------------------------------
// (d) Battle: two learners, the polls of the new beat
// ---------------------------------------------------------------------------------------------------------------------

/**
 * One match of a pair of learners from the invitation to the result. `a` invites, `b` accepts; then both do what the client does:
 * poll on the beat of the status (5 s invited, 3 s room, 2 s from the countdown on), press "ready", fetch the round when the poll
 * shows a new one, answer it. One VU plays both sides, tick by tick - the server sees two learners with a poll each per beat.
 *
 * `a` and `b` are `{token, telegram_user_id}` of the token file; the friendship is read from `GET /friends` of `a` (the
 * learner whose first name is "Load<N of b>", the way load:tokens names them).
 */
export function battle(a, b, course, mode) {
  const friends = json(get('/api/twa/friends', a.token, 'friends'));
  const partner = `Load${b.telegram_user_id - 8000000000}`;
  const friendship = (friends?.friends || []).find((f) => f.first_name === partner);
  if (!friendship) {
    flowAborted.add(1, { step: 'battle', status: 'no_friendship' });
    flowCompleted.add(false);
    console.warn(`battle: ${partner} is not a friend of the first player: run \`db:seed --class=LoadScenarioSeeder\` (pairs 1-2, 3-4, ...)`);
    sleep(10);
    return;
  }

  ok(get(`/api/twa/practice/battles/options?friendship_id=${friendship.id}&course=${encodeURIComponent(course)}`, a.token, 'battle_options'), 'battle_options');
  think(1, 2);
  const invited = post('/api/twa/practice/battles', { friendship_id: friendship.id, mode, course }, a.token, 'battle_invite');
  if (invited.status !== 201) {
    refused(invited, 'battle');
    sleep(10);
    return;
  }
  const battleId = json(invited)?.id;
  if (!Number.isInteger(battleId)) {
    flowCompleted.add(false);
    return;
  }
  const base = `/api/twa/practice/battles/${battleId}`;
  const started = Date.now();

  ok(get('/api/twa/practice/battles', b.token, 'battle_list'), 'battle_list');

  const players = [
    { who: a, accepted: true, ready: false, round: 0, element: null, fetchedAt: 0, answered: 0 },
    { who: b, accepted: false, ready: false, round: 0, element: null, fetchedAt: 0, answered: 0 },
  ];
  let roomSince = 0;
  let status = 'invited';
  let mineFailed = false;

  // At most ~5 minutes: an invitation lives 300 s, a match of five rounds a lot less.
  while (elapsed(started) < 300 && status !== 'finished' && status !== 'closed') {
    const polls = players.filter((p) => p.accepted).map((p) => ({ p, poll: json(get(`${base}/poll`, p.who.token, 'battle_poll')) }));
    const mine = polls[0].poll;
    if (!mine) {
      mineFailed = true;
      break;
    }
    status = mine.status;

    if (status === 'invited' && !players[1].accepted && elapsed(started) >= 3) {
      if (ok(post(`${base}/accept`, {}, b.token, 'battle_accept'), 'battle_accept')) {
        players[1].accepted = true;
      } else {
        break;
      }
    } else if (status === 'room') {
      roomSince = roomSince || Date.now();
      for (const [i, p] of players.entries()) {
        if (!p.ready && elapsed(roomSince) >= 3 + i * 3) {
          ok(post(`${base}/ready`, {}, p.who.token, 'battle_ready'), 'battle_ready');
          p.ready = true;
        }
      }
    } else if (status === 'playing') {
      for (const { p, poll } of polls) {
        const round = poll?.round;
        if (!round || round.phase !== 'answering' || round.my_answered || p.answered === round.no) {
          continue;
        }
        if (p.round !== round.no) {
          // A new round on the poll: fetch it (the client does at once), answer on a later beat after "reading" it.
          const res = get(`${base}/round?no=${round.no}`, p.who.token, 'battle_round');
          p.element = ok(res, 'battle_round') ? json(res)?.element : null;
          p.round = round.no;
          p.fetchedAt = Date.now();
        } else if (p.element && elapsed(p.fetchedAt) >= 2) {
          const res = post(`${base}/answer`, { request_id: uuid(), round: round.no, answer: answerFor(p.element) }, p.who.token, 'battle_answer');
          if (ok(res, 'battle_answer')) {
            battleAnswers.add(1);
            p.answered = round.no;
          }
        }
      }
    }

    if (status !== 'finished' && status !== 'closed') {
      sleep(BATTLE_POLL_SECONDS[status] || 2);
    }
  }

  const done = status === 'finished' || status === 'closed';
  battleEndings.add(1, { reason: done ? status : (mineFailed ? 'poll_failed' : 'abandoned') });
  if (done) {
    // The result screen of each player.
    ok(get(base, a.token, 'battle_result'), 'battle_result');
    ok(get(base, b.token, 'battle_result'), 'battle_result');
    battleDuration.add(elapsed(started));
  } else if (status === 'invited' || status === 'room' || status === 'countdown' || status === 'playing') {
    // Stuck or out of time: cancel / surrender so that the next iteration starts clean.
    post(`${base}/${status === 'playing' ? 'surrender' : 'cancel'}`, status === 'playing' ? { confirm: true } : {}, a.token, 'battle_abort');
  }
  flowCompleted.add(done);
  think(3, 8);
}

// ---------------------------------------------------------------------------------------------------------------------
// Shared by the scenario files
// ---------------------------------------------------------------------------------------------------------------------

// rampStages() and tokenProblem() live in plan.js (no k6 import there, so Node can test them); re-exported for the scenario files.
export { rampStages, tokenProblem } from './plan.js';

/**
 * The pair `index` (0-based) of the battle pool: the learners `start + 2 * index` and `start + 2 * index + 1` of the token file -
 * LoadScenarioSeeder makes friends of (1, 2), (3, 4), ... in the order of the file. `start` is even.
 */
export function battlePair(learners, start, index) {
  return [learners[start + 2 * index], learners[start + 2 * index + 1]];
}
