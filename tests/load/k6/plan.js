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

/** The flows of the mix, in the order that breaks ties (of the remainders, of the interleaving). */
export const MIX_FLOWS = ['training', 'game', 'practice', 'battle'];

const EPSILON = 1e-9;

/**
 * Splits `targetVus` active learners over the flows of the mix by `shares` ({training, game, practice, battle}, each >= 0; their sum
 * may be below 1 - the rest stays idle - but not above it). A battle VU plays a PAIR of learners, so the battle gets whole pairs.
 *
 * Largest remainder: every flow first takes the whole part of its quota (in learners; the battle the whole pairs of it), then the
 * learners left up to floor(targetVus x sum of shares) go one by one to the flow with the biggest shortfall (a pair costs two and
 * only fits when two are left; ties in the order of MIX_FLOWS; a last single learner a pair cannot use goes to a solo flow). So the participants never outnumber targetVus - 45/20/20/15 of 1250
 * is 562 + 250 + 250 + 94 pairs = 1250, not the 1251 of rounding each share on its own.
 *
 * The token file is cut into ranges that do not overlap, [start, end) by index: the battle FIRST, from index 0 - the pairs of
 * LoadScenarioSeeder are the learners 1-2, 3-4, ... i.e. the indexes (0, 1), (2, 3), ..., so the pool must start on an even index,
 * and only index 0 is even whatever the other counts are - then training, game, practice.
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
    throw new Error(`MIX_TRAINING + MIX_GAME + MIX_PRACTICE + MIX_BATTLE = ${shareSum.toFixed(4)}: the shares of the learners may not add up to more than 1.`);
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
      for (const flow of ['training', 'game', 'practice']) {
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
  for (const flow of ['battle', 'training', 'game', 'practice']) {
    ranges[flow] = { start: next, end: next + learners[flow] };
    next += learners[flow];
  }

  return {
    targetVus,
    training: learners.training,
    game: learners.game,
    practice: learners.practice,
    pairs,
    // VUs of each flow: one per solo learner, one per pair.
    vus: { training: learners.training, game: learners.game, practice: learners.practice, battle: pairs },
    totalVus: learners.training + learners.game + learners.practice + pairs,
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
  const dealt = { training: 0, game: 0, practice: 0, battle: 0 };
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
