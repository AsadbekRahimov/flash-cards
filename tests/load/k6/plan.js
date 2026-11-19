// The arithmetic of the k6 scenarios, kept apart from them: no k6 import, no __ENV, no I/O - so Node can run its tests
// (`node --test tests/load/k6/plan.test.js`, `make load-plan-test`) while k6 itself is not installed. The scenario files pass the
// environment in; flows.js re-exports rampStages() and tokenProblem() for them.

/** The tokens must outlive the run by this much (the stages and the graceful ramp-down are planned, the clock of k6 is not exact). */
export const TOKEN_MARGIN_SECONDS = 5 * 60;
/** And never less than this: the old fixed check, kept as the floor for short runs. */
export const TOKEN_FLOOR_SECONDS = 30 * 60;

const UNIT_SECONDS = { ns: 1e-9, us: 1e-6, 'µs': 1e-6, ms: 1e-3, s: 1, m: 60, h: 3600, d: 86400 };

/**
 * Seconds of a k6 duration string ("10m", "1h30m", "90s", "1.5h", "0"); NaN when it is not one. k6 reads a bare number as
 * milliseconds, but the scripts only ever get a string from HOLD, where a bare number is almost surely a mistake: refused.
 */
export function parseDuration(value) {
  const text = String(value).trim();
  if (text === '0') {
    return 0;
  }
  // The whole string first, then its parts (no sticky regexps: the JS engine of k6 is not V8).
  if (!/^(?:(?:\d+(?:\.\d+)?|\.\d+)(?:ns|us|µs|ms|s|m|h|d))+$/.test(text)) {
    return NaN;
  }
  const part = /(\d+(?:\.\d+)?|\.\d+)(ns|us|µs|ms|s|m|h|d)/g;
  let seconds = 0;
  let match;
  while ((match = part.exec(text)) !== null) {
    seconds += parseFloat(match[1]) * UNIT_SECONDS[match[2]];
  }
  return seconds;
}

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

/** How long a run of `stages` may last: all the stages plus the graceful ramp-down of its last iterations. NaN on a bad duration. */
export function plannedRunSeconds(stages, gracefulRampDown = '0s') {
  return stages.reduce((sum, stage) => sum + parseDuration(stage.duration), 0) + parseDuration(gracefulRampDown);
}

/** The lifetime the tokens must still have at the start: the planned run plus a margin, at least TOKEN_FLOOR_SECONDS. */
export function requiredTokenSeconds(runSeconds) {
  return Math.max(TOKEN_FLOOR_SECONDS, Math.ceil(runSeconds + TOKEN_MARGIN_SECONDS));
}

/**
 * The refusals every scenario checks before it starts (fail() in setup() stops the run with the message): the size of the token
 * file, the lifetime of its tokens over the whole planned run (`runSeconds`, plannedRunSeconds()), the course.
 */
export function tokenProblem(learners, file, needed, runSeconds = 0, nowMs = Date.now()) {
  if (learners.length < needed) {
    return `The token file holds ${learners.length} learners, the scenario needs ${needed}: run \`make load-tokens TARGET_VUS=${needed}\`.`;
  }
  if (!Number.isFinite(runSeconds)) {
    return 'HOLD is not a k6 duration (e.g. 10m, 1h, 1h30m).';
  }
  const required = requiredTokenSeconds(runSeconds);
  const left = file.expires_at - nowMs / 1000;
  if (left < required) {
    const minutes = Math.ceil(required / 60);
    return `The tokens expire in ${Math.max(0, Math.floor(left / 60))} minutes, the run (ramp, hold, ramp-down and ${TOKEN_MARGIN_SECONDS / 60} minutes of margin) `
      + `needs ${minutes}: issue new ones with \`make load-tokens\` (TOKENS_TTL=${Math.max(7200, Math.ceil(required / 600) * 600)} for a long hold).`;
  }
  if (!file.course) {
    return 'The token file has no course: publish a course, then run `make load-tokens` again.';
  }
  return null;
}

// ---------------------------------------------------------------------------------------------------------------------
// The mixed profile: who plays what, and with which tokens
// ---------------------------------------------------------------------------------------------------------------------

/**
 * The flows of the mix, in the order that breaks ties (of the remainders, of the interleaving). `browse` (Today, profile, courses, practice
 * hub, catalog - the light path of twa-hot-paths.js) is LAST: with its share 0 every other flow gets what it got before it existed.
 */
export const MIX_FLOWS = ['training', 'game', 'practice', 'battle', 'browse'];

/** The flows of one learner per VU, the ones a last odd learner can go to. */
const SOLO_FLOWS = MIX_FLOWS.filter((flow) => flow !== 'battle');

/** The token file is cut in this order: the battle first (its pairs must start on an even index), the new flows at the end. */
const RANGE_ORDER = ['battle', 'training', 'game', 'practice', 'browse'];

const EPSILON = 1e-9;

/**
 * Splits `targetVus` active learners over the flows of the mix by `shares` ({training, game, practice, battle, browse}, each >= 0, a missing one is 0; their sum
 * may be below 1 - the rest stays idle - but not above it). A battle VU plays a PAIR of learners, so the battle gets whole pairs.
 *
 * Largest remainder: every flow first takes the whole part of its quota (in learners; the battle the whole pairs of it), then the
 * learners left up to floor(targetVus x sum of shares) go one by one to the flow with the biggest shortfall (a pair costs two and
 * only fits when two are left; ties in the order of MIX_FLOWS; a last single learner a pair cannot use goes to a solo flow). So the participants never outnumber targetVus - 45/20/20/15 of 1250
 * is 562 + 250 + 250 + 94 pairs = 1250, not the 1251 of rounding each share on its own.
 *
 * The token file is cut into ranges that do not overlap, [start, end) by index: the battle FIRST, from index 0 - the pairs of
 * LoadScenarioSeeder are the learners 1-2, 3-4, ... i.e. the indexes (0, 1), (2, 3), ..., so the pool must start on an even index,
 * and only index 0 is even whatever the other counts are - then training, game, practice, browse.
 */
export function allocateMix(targetVus, shares) {
  if (!Number.isInteger(targetVus) || targetVus < 0) {
    throw new Error(`TARGET_VUS must be a whole number >= 0, got ${targetVus}.`);
  }
  const quota = {};
  let shareSum = 0;
  for (const flow of MIX_FLOWS) {
    const share = shares[flow] ?? 0;
    if (!Number.isFinite(share) || share < 0) {
      throw new Error(`The share of ${flow} must be a number >= 0, got ${share}.`);
    }
    shareSum += share;
    quota[flow] = targetVus * share;
  }
  if (shareSum > 1 + EPSILON) {
    throw new Error(`MIX_TRAINING + MIX_GAME + MIX_PRACTICE + MIX_BATTLE + MIX_BROWSE = ${shareSum.toFixed(4)}: the shares of the learners may not add up to more than 1.`);
  }

  // Learners per flow; the battle in steps of two.
  const learners = {};
  for (const flow of MIX_FLOWS) {
    learners[flow] = flow === 'battle' ? 2 * Math.floor(quota[flow] / 2 + EPSILON) : Math.floor(quota[flow] + EPSILON);
  }
  const budget = Math.min(targetVus, Math.floor(targetVus * shareSum + EPSILON));
  let left = budget - MIX_FLOWS.reduce((sum, flow) => sum + learners[flow], 0);
  while (left > 0) {
    let best = null;
    for (const flow of MIX_FLOWS) {
      const cost = flow === 'battle' ? 2 : 1;
      const shortfall = quota[flow] - learners[flow];
      if (cost <= left && shortfall > EPSILON && (best === null || shortfall > quota[best] - learners[best] + EPSILON)) {
        best = flow;
      }
    }
    if (best === null) {
      // Only a pair is short and one learner is left: it goes to the solo flow with the biggest shortfall rather than idle.
      for (const flow of SOLO_FLOWS) {
        if (quota[flow] > 0 && (best === null || quota[flow] - learners[flow] > quota[best] - learners[best] + EPSILON)) {
          best = flow;
        }
      }
    }
    if (best === null) {
      break;
    }
    const cost = best === 'battle' ? 2 : 1;
    learners[best] += cost;
    left -= cost;
  }

  const pairs = learners.battle / 2;
  const ranges = {};
  let next = 0;
  for (const flow of RANGE_ORDER) {
    ranges[flow] = { start: next, end: next + learners[flow] };
    next += learners[flow];
  }

  return {
    targetVus,
    training: learners.training,
    game: learners.game,
    practice: learners.practice,
    browse: learners.browse,
    pairs,
    // VUs of each flow: one per solo learner, one per pair.
    vus: { training: learners.training, game: learners.game, practice: learners.practice, battle: pairs, browse: learners.browse },
    totalVus: learners.training + learners.game + learners.practice + pairs + learners.browse,
    // Learners in the run = tokens it needs: the ranges cover exactly [0, participants).
    participants: next,
    ranges,
  };
}

/**
 * The slot of the virtual user `vuId` (1-based, k6's __VU) of the mixed profile: its flow, its number in the flow and the token
 * indexes it plays (two for a battle pair, one otherwise); null when vuId is outside 1..totalVus.
 *
 * k6 numbers the VUs over the whole test, and when several scenarios ramp up side by side their VUs take the numbers in the order
 * they start - interleaved and not repeatable - so no scenario can tell "my n-th VU" from __VU. That is why the mix runs ONE
 * scenario and the flow follows from the number itself: the numbers 1..totalVus are dealt to the flows in turn, each flow at its
 * share of every prefix (the flow with the biggest shortfall `vus x n / total - dealt` takes the next number), so a ramp that
 * starts the low numbers first keeps the proportions of the mix, and the n-th number of a flow takes the n-th learner (pair) of
 * its range. Each number has one slot, so no learner is played by two VUs, whatever order k6 starts them in.
 */
export function mixSlot(allocation, vuId) {
  const total = allocation.totalVus;
  if (!Number.isInteger(vuId) || vuId < 1 || vuId > total) {
    return null;
  }
  const dealt = Object.fromEntries(MIX_FLOWS.map((name) => [name, 0]));
  let flow = null;
  for (let n = 1; n <= vuId; n++) {
    flow = null;
    let bestShortfall = 0;
    for (const candidate of MIX_FLOWS) {
      const vus = allocation.vus[candidate];
      if (dealt[candidate] >= vus) {
        continue;
      }
      // vus x n / total - dealt, scaled by total to stay in integers.
      const shortfall = vus * n - dealt[candidate] * total;
      if (flow === null || shortfall > bestShortfall) {
        flow = candidate;
        bestShortfall = shortfall;
      }
    }
    dealt[flow]++;
  }

  const rank = dealt[flow] - 1;
  const start = allocation.ranges[flow].start;
  const tokens = flow === 'battle' ? [start + 2 * rank, start + 2 * rank + 1] : [start + rank];

  return { flow, rank, tokens };
}

// ---------------------------------------------------------------------------------------------------------------------
// The burst of Mini App opens (arrival-burst.js): how many opens, which learner each one plays, which ones take the class path
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A learner may open the app again in the same burst (a second tap on the broadcast, back from another chat), but not sooner than
 * this: an open takes a second or two, so with this gap a learner never runs two opens at once unless an open hangs for a minute -
 * which the thresholds fail anyway.
 */
export const BURST_REOPEN_GAP_SECONDS = 60;

/** The arrival-rate stages of a burst: from 0 up to `rate` opens a second over `ramp`, then `rate` held for `hold`. */
export function burstStages(rate, ramp, hold) {
  return [
    { duration: ramp, target: rate },
    { duration: hold, target: rate },
  ];
}

/** The opens a burst plans: half the rate over the linear ramp, the full rate over the hold. NaN on a bad duration. */
export function plannedOpens(rate, ramp, hold) {
  return Math.ceil((rate * parseDuration(ramp)) / 2 + rate * parseDuration(hold));
}

/**
 * The VUs of the burst: `preAllocatedVUs` and `maxVUs` of ramping-arrival-rate. An open is 2-4 requests with no think time (~1 s on a
 * healthy server), so twice the rate starts it without allocating mid-run, and ten times the rate covers a server ten times slower
 * before k6 has to drop opens (`dropped_iterations`). Explicit values win; maxVUs is never below preAllocatedVUs.
 */
export function burstVus(rate, preAllocated, max) {
  const pre = Number.isInteger(preAllocated) && preAllocated > 0 ? preAllocated : Math.max(10, rate * 2);
  const ceiling = Number.isInteger(max) && max > 0 ? max : Math.max(50, rate * 10);
  return { preAllocatedVUs: pre, maxVUs: Math.max(pre, ceiling) };
}

/**
 * The learners a burst needs so that nobody opens twice within BURST_REOPEN_GAP_SECONDS: every open its own learner when the run is
 * short (`opens`), otherwise the opens of a gap at the full rate (the token of iteration i comes back at iteration i + pool).
 */
export function burstPool(rate, opens) {
  return Math.max(1, Math.min(opens, Math.ceil(rate * BURST_REOPEN_GAP_SECONDS)));
}

/**
 * The refusals of a burst before it starts (null when it may run): the rate, the durations, the share of class opens, the share of
 * class learners of the seed (`seedShare`, LOAD_SCENARIO_CLASS_SHARE). The size of the token file and the lifetime of its tokens are
 * tokenProblem()'s, with burstPool() learners; the split of the file into class and solo learners is burstRosterProblem()'s.
 */
export function burstProblem(rate, ramp, hold, classShare, seedShare = 0) {
  if (!Number.isInteger(rate) || rate < 1) {
    return `BURST_RATE must be a whole number of opens a second >= 1, got ${rate}.`;
  }
  if (!Number.isFinite(parseDuration(ramp)) || parseDuration(ramp) <= 0) {
    return `BURST_RAMP is not a k6 duration above 0 (e.g. 30s, 1m), got "${ramp}".`;
  }
  if (!Number.isFinite(parseDuration(hold))) {
    return `HOLD is not a k6 duration (e.g. 2m, 10m), got "${hold}".`;
  }
  if (!Number.isFinite(classShare) || classShare < 0 || classShare > 1) {
    return `CLASS_SHARE must be a number from 0 to 1, got ${classShare}.`;
  }
  if (!Number.isFinite(seedShare) || seedShare < 0 || seedShare > 1) {
    return `LOAD_SCENARIO_CLASS_SHARE must be a number from 0 to 1, got ${seedShare}.`;
  }
  return null;
}

/**
 * The token index of the open number `iteration` (0-based, unique over the run: k6's exec.scenario.iterationInTest) in a pool of
 * `pool` learners: in turn, so a learner comes back only after all the others have opened once - `pool / rate` seconds later.
 */
export function burstToken(iteration, pool) {
  return iteration % pool;
}

/**
 * Whether the open number `iteration` takes the class path (an announcement of the group: assignments, then the assigned lesson):
 * `share` of the opens, spread evenly - on every prefix of the run the class opens are floor((i + 1) x share), so the ramp has its
 * share too and a run is repeatable.
 */
export function isClassOpen(iteration, share) {
  return Math.floor((iteration + 1) * share + EPSILON) > Math.floor(iteration * share + EPSILON);
}

// ---------------------------------------------------------------------------------------------------------------------
// The class learners of the seed (LoadScenarioSeeder, LoadTestClassroom): who they are, and which opens of a burst play them
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Whether the learner at token index `index` (0-based: learner N of load:tokens is index N - 1) is a member of a test class of the
 * seed with LOAD_SCENARIO_CLASS_SHARE = `share`. The SAME rule as LoadTestClassroom::isMember() on the PHP side (and as
 * isClassOpen() here): evenly spread, so every prefix of the token file holds floor(n x share) members - Load5, Load10, ... at 0.2.
 * Class learners sit all over the file: a battle pair (2k, 2k + 1) may hold one, a flow range of the mix holds its share of them.
 */
export function isClassLearner(index, share) {
  return Math.floor((index + 1) * share + EPSILON) > Math.floor(index * share + EPSILON);
}

/** Class learners of the seed among the first `size` tokens: floor(size x share), by the prefix property of isClassLearner(). */
export function classLearnerCount(size, share) {
  return share > 0 ? Math.floor(size * share + EPSILON) : 0;
}

/** The token file of `size` learners split into the class learners of the seed and the others (token indexes, ascending). */
export function classRoster(size, share) {
  const members = [];
  const solo = [];
  for (let i = 0; i < size; i++) {
    (share > 0 && isClassLearner(i, share) ? members : solo).push(i);
  }
  return { members, solo };
}

/**
 * The learners a kind of opens (class or solo) needs so that nobody opens twice within BURST_REOPEN_GAP_SECONDS: every open its own
 * learner when there are few (`opens`), otherwise the opens of a gap at that kind's rate, plus one - the opens of a kind are spread
 * evenly but not exactly periodically (floor), and the extra learner covers that.
 */
export function burstKindPool(kindRate, opens) {
  if (opens <= 0) {
    return 0;
  }
  return Math.min(opens, Math.ceil(kindRate * BURST_REOPEN_GAP_SECONDS - EPSILON) + 1);
}

/**
 * The learner of the open number `iteration` (exec.scenario.iterationInTest) when the seed has class learners (`roster` of
 * classRoster()): a class open (isClassOpen) takes the class learners in turn, a solo open the others in turn - so every class open is
 * played by a member of a class (`/assignments` lists its assignment) and no solo open by one. Without class learners in the file
 * (LOAD_SCENARIO_CLASS_SHARE=0, or a pool too small for any) it is burstToken() over the whole file, as before the seed had classes.
 * Returns `{ index, member }`: the token index, and whether the open takes the class path.
 */
export function burstLearner(iteration, classShare, roster) {
  const member = isClassOpen(iteration, classShare);
  const pool = member ? roster.members : roster.solo;
  if (roster.members.length === 0 || pool.length === 0) {
    return { index: burstToken(iteration, roster.members.length + roster.solo.length), member };
  }
  // Class opens before this one: floor(iteration x share) (isClassOpen's prefix count); the rest of the earlier opens were solo.
  const classBefore = Math.floor(iteration * classShare + EPSILON);
  const rank = member ? classBefore : iteration - classBefore;
  return { index: pool[rank % pool.length], member };
}

/**
 * The refusal of a burst whose token file of `size` learners cannot keep the reopen gap once the opens are split by kind (null when
 * it can, or when the seed has no class learners - burstPool() then covers it): the class opens (`classShare` of `opens`, at
 * `rate x classShare` a second) need burstKindPool() class learners of the seed (`seedShare`), the solo opens as many others.
 */
export function burstRosterProblem(size, seedShare, rate, opens, classShare) {
  if (classLearnerCount(size, seedShare) === 0) {
    return null;
  }
  const classOpens = Math.floor(opens * classShare + EPSILON);
  const needClass = burstKindPool(rate * classShare, classOpens);
  const needSolo = burstKindPool(rate * (1 - classShare), opens - classOpens);
  const fits = (n) => classLearnerCount(n, seedShare) >= needClass && n - classLearnerCount(n, seedShare) >= needSolo;
  if (fits(size)) {
    return null;
  }
  let enough = size + 1;
  while (enough < 100000 && !fits(enough)) {
    enough++;
  }
  const members = classLearnerCount(size, seedShare);
  const advice = needClass > members && classShare > seedShare
    ? ` (or keep CLASS_SHARE at most LOAD_SCENARIO_CLASS_SHARE=${seedShare}, or seed more class learners)`
    : '';
  return `The token file holds ${members} class learners of the seed (LOAD_SCENARIO_CLASS_SHARE=${seedShare}) and ${size - members} others; `
    + `at ${rate} opens/s with CLASS_SHARE=${classShare} the burst needs ${needClass} and ${needSolo} so that nobody opens twice within `
    + `${BURST_REOPEN_GAP_SECONDS} s: run \`make load-tokens TARGET_VUS=${enough}\` and \`make load-seed\`${advice}.`;
}
