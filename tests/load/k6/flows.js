// One iteration of each kind of learner activity of the Mini App, as functions: the scenario files (twa-training-mature.js,
// game-caravan.js, practice-session.js, battle.js) call one each, mixed-profile.js calls all of them side by side. The shapes of
// the calls follow resources/twa/src (stores/training.ts, game, practice, battle) and docs/04 A.x.
//
// Pacing follows the cost table of docs/AUDIT_2026-11-10_RU.md §2: training ~13-20 requests a minute per learner, game and
// practice 10-40, battle ~40 (two polls every 2 s for a pair = 30 a minute per player, plus the answers). All of the
// non-poll requests share the per-learner limiter `twa-api` (60 a minute): the think times keep a well-paced run under it, and the
// `rate_limited` threshold of the scenarios says when they do not.
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { get, post, ok, think, json, uuid, answerFor, getAudio } from './lib.js';

/** Flows that ended on a refusal or an unexpected answer of the API (limit reached, no material, 4xx): the run measured less than it should. */
export const flowAborted = new Counter('flow_aborted');
/** A flow that reached its natural end (a session finished, a run ended, a match closed): `rate>0.95` in the thresholds. */
export const flowCompleted = new Rate('flow_completed');
/** Mature training: how many of the served cards carry options (the reverse / audio path with the distractors). */
export const distractorCards = new Rate('training_distractor_cards');
export const cardsServed = new Counter('training_cards_served');
export const trainingCoverage = new Trend('training_review_coverage');
export const gameAnswers = new Counter('game_answers');
export const practiceAnswers = new Counter('practice_answers');
export const battleAnswers = new Counter('battle_answers');
export const battleDuration = new Trend('battle_duration_s');

const BATTLE_POLL_SECONDS = { invited: 5, room: 3, countdown: 2, playing: 2, finished: 2, closed: 2 };

function refused(res, what) {
  flowAborted.add(1, { flow: what, status: String(res.status) });
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
// (a) Training with mature cards
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A daily-review session over cards that are due and graduated (LoadScenarioSeeder): the exercise selector serves reverse / audio
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

    const typed = card.prompt && (card.prompt.type === 'spelling' || card.prompt.type === 'gap_fill');
    const body = { word_id: card.word_id, time_spent_ms: 3000 + Math.floor(Math.random() * 3000), serve_token: card.serve_token };
    if (typed) {
      body.answer = 'test';
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
    flowAborted.add(1, { flow: 'battle', status: 'no_friendship' });
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

  // At most ~5 minutes: an invitation lives 300 s, a match of five rounds a lot less.
  while (elapsed(started) < 300 && status !== 'finished' && status !== 'closed') {
    const polls = players.filter((p) => p.accepted).map((p) => ({ p, poll: json(get(`${base}/poll`, p.who.token, 'battle_poll')) }));
    const mine = polls[0].poll;
    if (!mine) {
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

/** The stages of a run: 20 % -> 50 % -> 100 % of `target`, held for `hold`, then down. */
export function rampStages(target, hold) {
  return [
    { duration: '2m', target: Math.max(1, Math.round(target * 0.2)) },
    { duration: '3m', target: Math.max(1, Math.round(target * 0.5)) },
    { duration: '3m', target },
    { duration: hold, target },
    { duration: '1m', target: 0 },
  ];
}

/** The refusals every scenario checks before it starts (fail() in setup() stops the run with the message). */
export function tokenProblem(learners, file, needed) {
  if (learners.length < needed) {
    return `The token file holds ${learners.length} learners, the scenario needs ${needed}: run \`make load-tokens TARGET_VUS=${needed}\`.`;
  }
  if (file.expires_at * 1000 < Date.now() + 30 * 60 * 1000) {
    return 'The tokens expire in less than 30 minutes: issue new ones with `make load-tokens`.';
  }
  if (!file.course) {
    return 'The token file has no course: publish a course, then run `make load-tokens` again.';
  }
  return null;
}

/**
 * The pair `index` (0-based) of the battle pool: the learners `start + 2 * index` and `start + 2 * index + 1` of the token file -
 * LoadScenarioSeeder makes friends of (1, 2), (3, 4), ... in the order of the file. `start` is even.
 */
export function battlePair(learners, start, index) {
  return [learners[start + 2 * index], learners[start + 2 * index + 1]];
}
