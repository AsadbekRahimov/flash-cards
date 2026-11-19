// Tests of plan.js (the arithmetic of the k6 scenarios) on Node's own runner - no dependency, no k6:
//   node --test tests/load/k6/plan.test.js      (or `make load-plan-test`)
// k6 never loads this file: no scenario imports it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  allocateMix,
  burstKindPool,
  burstLearner,
  burstPool,
  burstProblem,
  burstRosterProblem,
  burstStages,
  burstToken,
  burstVus,
  BURST_REOPEN_GAP_SECONDS,
  classLearnerCount,
  classRoster,
  isClassLearner,
  isClassOpen,
  MIX_FLOWS,
  mixSlot,
  plannedOpens,
  parseDuration,
  plannedRunSeconds,
  rampStages,
  requiredTokenSeconds,
  tokenProblem,
  TOKEN_FLOOR_SECONDS,
} from './plan.js';

const DEFAULT_SHARES = { training: 0.45, game: 0.2, practice: 0.2, battle: 0.15 };
/** The suggested mix with browsing (README): 15 % browse, the rest of the default scaled by 0.85 and rounded. */
const BROWSE_SHARES = { training: 0.38, game: 0.17, practice: 0.17, battle: 0.13, browse: 0.15 };
const RANGE_ORDER = ['battle', 'training', 'game', 'practice', 'browse'];

/** Every slot of an allocation, by VU number, with the invariants checked on the way. */
function checkAllocation(mix) {
  const sumOfFlows = mix.training + mix.game + mix.practice + 2 * mix.pairs + mix.browse;
  assert.equal(mix.participants, sumOfFlows, 'participants = the learners of the flows');
  assert.ok(mix.participants <= mix.targetVus, `${mix.participants} participants > pool ${mix.targetVus}`);
  assert.equal(mix.totalVus, mix.training + mix.game + mix.practice + mix.pairs + mix.browse);

  // The ranges tile [0, participants) without overlapping; the battle starts on an even index.
  const ranges = RANGE_ORDER.map((flow) => mix.ranges[flow]);
  let at = 0;
  for (const range of ranges) {
    assert.equal(range.start, at);
    assert.ok(range.end >= range.start);
    at = range.end;
  }
  assert.equal(at, mix.participants);
  assert.equal(mix.ranges.battle.start % 2, 0);
  assert.equal(mix.ranges.battle.end - mix.ranges.battle.start, 2 * mix.pairs);

  // Every VU number has one slot; no token is played twice; each token lies in the range of its flow; pairs are seeded friends.
  const used = new Set();
  const dealt = Object.fromEntries(MIX_FLOWS.map((flow) => [flow, 0]));
  for (let vu = 1; vu <= mix.totalVus; vu++) {
    const slot = mixSlot(mix, vu);
    assert.ok(slot !== null, `VU ${vu} has no slot`);
    dealt[slot.flow]++;
    assert.equal(slot.tokens.length, slot.flow === 'battle' ? 2 : 1);
    if (slot.flow === 'battle') {
      assert.equal(slot.tokens[0] % 2, 0, 'a pair starts on an even index (LoadScenarioSeeder: 1-2, 3-4, ...)');
      assert.equal(slot.tokens[1], slot.tokens[0] + 1);
    }
    for (const token of slot.tokens) {
      assert.ok(!used.has(token), `token ${token} played twice (VU ${vu})`);
      used.add(token);
      const range = mix.ranges[slot.flow];
      assert.ok(token >= range.start && token < range.end, `token ${token} of ${slot.flow} outside ${JSON.stringify(range)}`);
    }
  }
  assert.deepEqual(dealt, mix.vus);
  assert.equal(used.size, mix.participants);
  assert.equal(mixSlot(mix, 0), null);
  assert.equal(mixSlot(mix, mix.totalVus + 1), null);
}

test('the default mix of 1250 fits a token file of exactly 1250', () => {
  const mix = allocateMix(1250, DEFAULT_SHARES);
  assert.equal(mix.training, 562);
  assert.equal(mix.game, 250);
  assert.equal(mix.practice, 250);
  assert.equal(mix.pairs, 94);
  assert.equal(mix.participants, 1250);
  assert.equal(mix.browse, 0);
  assert.equal(mix.totalVus, 1156);
  assert.deepEqual(mix.ranges, {
    battle: { start: 0, end: 188 },
    training: { start: 188, end: 750 },
    game: { start: 750, end: 1000 },
    practice: { start: 1000, end: 1250 },
    browse: { start: 1250, end: 1250 },
  });
  checkAllocation(mix);
});

test('MIX_BROWSE=0 changes nothing: the same slots as without the key, for any pool', () => {
  for (const target of [1, 7, 100, 101, 1250]) {
    const without = allocateMix(target, DEFAULT_SHARES);
    const zero = allocateMix(target, { ...DEFAULT_SHARES, browse: 0 });
    assert.deepEqual(zero, without);
    for (let vu = 1; vu <= without.totalVus; vu++) {
      assert.notEqual(mixSlot(zero, vu).flow, 'browse');
    }
  }
});

test('the suggested mix with browsing: five flows of 1250', () => {
  const mix = allocateMix(1250, BROWSE_SHARES);
  // Quotas 475 / 212.5 / 212.5 / 162.5 (81 pairs) / 187.5: two learners left after the whole parts, game and practice take them.
  assert.deepEqual(
    [mix.training, mix.game, mix.practice, mix.pairs, mix.browse, mix.participants, mix.totalVus],
    [475, 213, 213, 81, 187, 1250, 1169],
  );
  assert.deepEqual(mix.ranges, {
    battle: { start: 0, end: 162 },
    training: { start: 162, end: 637 },
    game: { start: 637, end: 850 },
    practice: { start: 850, end: 1063 },
    browse: { start: 1063, end: 1250 },
  });
  checkAllocation(mix);
});

test('browse alone, and a last odd learner going to browse', () => {
  const only = allocateMix(10, { browse: 1 });
  assert.deepEqual([only.browse, only.participants, only.totalVus], [10, 10, 10]);
  assert.deepEqual(only.ranges.browse, { start: 0, end: 10 });
  checkAllocation(only);

  // Five learners: two pairs and one left that no pair can use; browse is the only solo flow with a quota.
  const odd = allocateMix(5, { battle: 0.9, browse: 0.1 });
  assert.deepEqual([odd.pairs, odd.browse, odd.participants], [2, 1, 5]);
  checkAllocation(odd);
});

test('the documented targets 100 / 300 / 600 fit their own pool', () => {
  // 100: 45 / 20 / 20 / 7 pairs leave one learner no pair can use - training takes it.
  assert.deepEqual((({ training, game, practice, pairs }) => [training, game, practice, pairs])(allocateMix(100, DEFAULT_SHARES)), [46, 20, 20, 7]);
  for (const target of [100, 300, 600]) {
    const mix = allocateMix(target, DEFAULT_SHARES);
    assert.equal(mix.participants, target, `target ${target}`);
    checkAllocation(mix);
  }
});

test('small odd pools', () => {
  assert.deepEqual(
    [7, 11, 101].map((target) => {
      const mix = allocateMix(target, DEFAULT_SHARES);
      checkAllocation(mix);
      return [mix.training, mix.game, mix.practice, mix.pairs, mix.participants];
    }),
    [
      [3, 1, 1, 1, 7], // quotas 3.15 / 1.4 / 1.4 / 1.05 learners: the pair has the biggest shortfall
      [5, 2, 2, 1, 11], // 4.95 / 2.2 / 2.2 / 1.65
      [45, 20, 20, 8, 101], // 45.45 / 20.2 / 20.2 / 15.15: two left after the whole parts, the pair is the most short
    ],
  );
});

test('a pool of 1 and of 0', () => {
  const one = allocateMix(1, DEFAULT_SHARES);
  assert.equal(one.participants, 1);
  assert.equal(one.pairs, 0);
  checkAllocation(one);

  const none = allocateMix(0, DEFAULT_SHARES);
  assert.equal(none.totalVus, 0);
  checkAllocation(none);
});

test('a battle share of 0 makes no pairs and no pool', () => {
  const mix = allocateMix(1250, { training: 0.5, game: 0.25, practice: 0.25, battle: 0 });
  assert.equal(mix.pairs, 0);
  assert.deepEqual(mix.ranges.battle, { start: 0, end: 0 });
  assert.equal(mix.ranges.training.start, 0);
  assert.equal(mix.participants, 1250);
  checkAllocation(mix);
  for (let vu = 1; vu <= mix.totalVus; vu++) {
    assert.notEqual(mixSlot(mix, vu).flow, 'battle');
  }
});

test('only battle', () => {
  const mix = allocateMix(11, { training: 0, game: 0, practice: 0, battle: 1 });
  assert.equal(mix.pairs, 5);
  assert.equal(mix.participants, 10, 'the odd learner cannot make a pair');
  checkAllocation(mix);
});

test('shares below 1 leave the rest idle', () => {
  const mix = allocateMix(1000, { training: 0.3, game: 0.1, practice: 0.1, battle: 0.1 });
  assert.deepEqual([mix.training, mix.game, mix.practice, mix.pairs, mix.participants], [300, 100, 100, 50, 600]);
  checkAllocation(mix);

  const missing = allocateMix(10, { training: 0.5 });
  assert.deepEqual([missing.training, missing.game, missing.practice, missing.pairs], [5, 0, 0, 0]);
});

test('shares above 1 and bad input are refused', () => {
  assert.throws(() => allocateMix(1250, { training: 0.5, game: 0.3, practice: 0.2, battle: 0.15 }), /may not add up to more than 1/);
  assert.throws(() => allocateMix(1250, { ...DEFAULT_SHARES, browse: 0.15 }), /MIX_BROWSE = 1\.1500: the shares/);
  assert.throws(() => allocateMix(1250, { ...BROWSE_SHARES, browse: -0.15 }), /share of browse must be a number >= 0/);
  assert.throws(() => allocateMix(1250, { ...DEFAULT_SHARES, game: -0.1 }), /must be a number >= 0/);
  assert.throws(() => allocateMix(1250, { ...DEFAULT_SHARES, game: NaN }), /must be a number >= 0/);
  assert.throws(() => allocateMix(-1, DEFAULT_SHARES), /TARGET_VUS/);
  assert.throws(() => allocateMix(12.5, DEFAULT_SHARES), /TARGET_VUS/);
});

test('property: any pool, several mixes - participants within the pool, ranges apart, pairs disjoint', () => {
  const mixes = [
    DEFAULT_SHARES,
    { training: 0.5, game: 0.25, practice: 0.25, battle: 0 },
    { training: 0.1, game: 0.1, practice: 0.1, battle: 0.7 },
    { training: 1 / 3, game: 1 / 3, practice: 0, battle: 1 / 3 },
    { training: 0.2, game: 0.2, practice: 0.2, battle: 0.2 },
    { training: 0, game: 0, practice: 0, battle: 1 },
    BROWSE_SHARES,
    { training: 0.2, game: 0.2, practice: 0.2, battle: 0.2, browse: 0.2 },
    { training: 0, game: 0, practice: 0, battle: 0.5, browse: 0.5 },
    { training: 0, game: 0, practice: 0, battle: 0, browse: 1 },
    { training: 0.1, game: 0, practice: 0.1, battle: 0.3, browse: 0.25 },
  ];
  for (const shares of mixes) {
    const sum = MIX_FLOWS.reduce((total, flow) => total + (shares[flow] ?? 0), 0);
    for (let target = 0; target <= 1400; target += target < 300 ? 1 : 37) {
      const mix = allocateMix(target, shares);
      checkAllocation(mix);
      // Nothing is left on the table: floor(target x sum) learners play, but for an odd one when the battle is the only flow.
      const onlyBattle = shares.training + shares.game + shares.practice + (shares.browse ?? 0) === 0;
      assert.ok(mix.participants >= Math.floor(target * sum + 1e-9) - (onlyBattle ? 1 : 0), `${JSON.stringify(shares)} at ${target}: ${mix.participants}`);
    }
  }
});

test('the interleaving keeps the shares on every prefix of the VU numbers (the ramp)', () => {
  for (const shares of [DEFAULT_SHARES, BROWSE_SHARES]) {
    const mix = allocateMix(1250, shares);
    const dealt = Object.fromEntries(MIX_FLOWS.map((flow) => [flow, 0]));
    for (let vu = 1; vu <= mix.totalVus; vu++) {
      dealt[mixSlot(mix, vu).flow]++;
      for (const flow of Object.keys(dealt)) {
        const ideal = (mix.vus[flow] * vu) / mix.totalVus;
        assert.ok(Math.abs(dealt[flow] - ideal) <= 1, `${flow} at VU ${vu}: ${dealt[flow]} vs ${ideal.toFixed(2)}`);
      }
    }
  }
});

test('parseDuration reads k6 durations', () => {
  assert.equal(parseDuration('10m'), 600);
  assert.equal(parseDuration('1h'), 3600);
  assert.equal(parseDuration('1h30m'), 5400);
  assert.equal(parseDuration('90s'), 90);
  assert.equal(parseDuration('1.5h'), 5400);
  assert.equal(parseDuration('500ms'), 0.5);
  assert.equal(parseDuration('0'), 0);
  assert.equal(parseDuration('1d'), 86400);
  for (const bad of ['', '10', 'ten minutes', '10x', 'm', '-5m', '10m30']) {
    assert.ok(Number.isNaN(parseDuration(bad)), `"${bad}" is not a duration`);
  }
});

test('the planned run and the token lifetime', () => {
  // 2 + 3 + 3 minutes of ramp, the hold, 1 minute down, the graceful ramp-down.
  assert.equal(plannedRunSeconds(rampStages(1250, '10m'), '120s'), (2 + 3 + 3 + 10 + 1) * 60 + 120);
  assert.equal(plannedRunSeconds(rampStages(1250, '1h'), '30s'), (2 + 3 + 3 + 60 + 1) * 60 + 30);
  assert.ok(Number.isNaN(plannedRunSeconds(rampStages(10, 'forever'), '30s')));

  // The default 10-minute hold stays on the old 30-minute floor; an hour asks for the whole run plus 5 minutes.
  assert.equal(requiredTokenSeconds(plannedRunSeconds(rampStages(1250, '10m'), '120s')), TOKEN_FLOOR_SECONDS);
  assert.equal(requiredTokenSeconds(plannedRunSeconds(rampStages(1250, '1h'), '120s')), (69 + 2 + 5) * 60);
});

test('tokenProblem refuses a file the run would outlive', () => {
  const now = Date.UTC(2026, 10, 19, 12, 0, 0);
  const learners = new Array(10).fill({ token: 't' });
  const file = (minutesLeft) => ({ course: 'english', expires_at: now / 1000 + minutesLeft * 60 });
  const hourHold = plannedRunSeconds(rampStages(10, '1h'), '120s'); // 71 minutes -> 76 needed

  assert.equal(tokenProblem(learners, file(120), 10, hourHold, now), null);
  assert.match(tokenProblem(learners, file(60), 10, hourHold, now), /expire in 60 minutes.*needs 76/);
  assert.equal(tokenProblem(learners, file(31), 10, plannedRunSeconds(rampStages(10, '10m'), '30s'), now), null);
  assert.match(tokenProblem(learners, file(29), 10, 0, now), /needs 30/);
  assert.match(tokenProblem(learners, file(120), 11, 0, now), /holds 10 learners, the scenario needs 11/);
  assert.match(tokenProblem(learners, { ...file(120), course: null }, 10, 0, now), /no course/);
  assert.match(tokenProblem(learners, file(120), 10, NaN, now), /HOLD is not a k6 duration/);
});

// ---------------------------------------------------------------------------------------------------------------------
// The burst of opens (arrival-burst.js)
// ---------------------------------------------------------------------------------------------------------------------

test('the burst plan: stages, opens, VUs', () => {
  assert.deepEqual(burstStages(20, '30s', '2m'), [{ duration: '30s', target: 20 }, { duration: '2m', target: 20 }]);
  // 20 a second: 300 opens on the ramp, 2400 on the hold.
  assert.equal(plannedOpens(20, '30s', '2m'), 2700);
  assert.equal(plannedOpens(7, '45s', '0'), 158);
  assert.ok(Number.isNaN(plannedOpens(20, '30s', 'soon')));
  // 30 s of ramp + 2 m of hold + 30 s of graceful stop (the default of the executor).
  assert.equal(plannedRunSeconds(burstStages(20, '30s', '2m'), '30s'), 180);

  assert.deepEqual(burstVus(20, NaN, NaN), { preAllocatedVUs: 40, maxVUs: 200 });
  assert.deepEqual(burstVus(2, NaN, NaN), { preAllocatedVUs: 10, maxVUs: 50 });
  assert.deepEqual(burstVus(20, 100, 500), { preAllocatedVUs: 100, maxVUs: 500 });
  assert.deepEqual(burstVus(20, 300, 100), { preAllocatedVUs: 300, maxVUs: 300 }, 'maxVUs never below preAllocatedVUs');
  assert.deepEqual(burstVus(20, 0, -1), { preAllocatedVUs: 40, maxVUs: 200 }, 'nonsense falls back to the defaults');
});

test('the burst pool: every open its own learner for a short run, a gap of a minute for a long one', () => {
  assert.equal(burstPool(20, 2700), 20 * BURST_REOPEN_GAP_SECONDS);
  assert.equal(burstPool(5, 200), 200);
  assert.equal(burstPool(1, 0), 1);
});

test('burstToken: no learner opens twice within the reopen gap, whatever the rate', () => {
  for (const [rate, ramp, hold] of [[20, '30s', '2m'], [3, '30s', '10m'], [50, '10s', '30s'], [7, '1m', '0']]) {
    const opens = plannedOpens(rate, ramp, hold);
    const pool = burstPool(rate, opens);
    const lastOpen = new Map();
    for (let i = 0; i < opens; i++) {
      const token = burstToken(i, pool);
      assert.ok(token >= 0 && token < pool);
      if (lastOpen.has(token)) {
        // The rate never exceeds `rate`, so `i - last` opens take at least (i - last) / rate seconds.
        assert.ok((i - lastOpen.get(token)) / rate >= BURST_REOPEN_GAP_SECONDS, `token ${token} again after ${i - lastOpen.get(token)} opens at ${rate}/s`);
      }
      lastOpen.set(token, i);
    }
    if (opens <= pool) {
      assert.equal(lastOpen.size, opens, 'a short burst: every open its own learner');
    }
  }
  // A bigger token file is used whole: the gap only grows.
  assert.equal(burstToken(1300, 1250), 50);
});

test('isClassOpen: the share on every prefix, evenly', () => {
  for (const share of [0, 0.1, 0.25, 1 / 3, 0.5, 0.9, 1]) {
    let classOpens = 0;
    for (let i = 0; i < 2000; i++) {
      if (isClassOpen(i, share)) {
        classOpens++;
      }
      assert.equal(classOpens, Math.floor((i + 1) * share + 1e-9), `share ${share} at open ${i}`);
    }
  }
  assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => isClassOpen(i, 0.2)), [false, false, false, false, true, false, false, false, false, true]);
});

test('burstProblem refuses a bad rate, durations or share', () => {
  assert.equal(burstProblem(20, '30s', '2m', 0.2), null);
  assert.equal(burstProblem(1, '1s', '0', 0), null);
  assert.match(burstProblem(0, '30s', '2m', 0.2), /BURST_RATE/);
  assert.match(burstProblem(2.5, '30s', '2m', 0.2), /BURST_RATE/);
  assert.match(burstProblem(NaN, '30s', '2m', 0.2), /BURST_RATE/);
  assert.match(burstProblem(20, '30', '2m', 0.2), /BURST_RAMP/);
  assert.match(burstProblem(20, '0', '2m', 0.2), /BURST_RAMP/);
  assert.match(burstProblem(20, '30s', 'long', 0.2), /HOLD/);
  assert.match(burstProblem(20, '30s', '2m', 1.5), /CLASS_SHARE/);
  assert.match(burstProblem(20, '30s', '2m', -0.1), /CLASS_SHARE/);
  assert.match(burstProblem(20, '30s', '2m', NaN), /CLASS_SHARE/);
  assert.equal(burstProblem(20, '30s', '2m', 0.2, 0.2), null);
  assert.equal(burstProblem(20, '30s', '2m', 0.2, 0), null);
  assert.match(burstProblem(20, '30s', '2m', 0.2, 1.2), /LOAD_SCENARIO_CLASS_SHARE/);
  assert.match(burstProblem(20, '30s', '2m', 0.2, NaN), /LOAD_SCENARIO_CLASS_SHARE/);
});

// ---------------------------------------------------------------------------------------------------------------------
// The class learners of the seed (LoadTestClassroom on the PHP side) and the burst
// ---------------------------------------------------------------------------------------------------------------------

test('isClassLearner: the rule of LoadTestClassroom::isMember (Load5, Load10, ... at 0.2), the same count on every prefix', () => {
  // The same list LoadScenarioSeederTest checks on the PHP side.
  assert.deepEqual([...Array(30).keys()].filter((i) => isClassLearner(i, 0.2)), [4, 9, 14, 19, 24, 29]);
  for (const share of [0, 0.1, 0.2, 0.25, 1 / 3, 0.5, 1]) {
    let members = 0;
    for (let n = 1; n <= 2000; n++) {
      if (isClassLearner(n - 1, share)) {
        members++;
      }
      assert.equal(members, classLearnerCount(n, share), `share ${share}, ${n} learners`);
    }
  }
  assert.equal(classLearnerCount(1250, 0.2), 250);
  const roster = classRoster(1250, 0.2);
  assert.equal(roster.members.length, 250);
  assert.equal(roster.solo.length, 1000);
  assert.deepEqual(classRoster(10, 0), { members: [], solo: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9] });
  // A smaller token file holds exactly the members of its range: the seed of 1250 serves a file of 200.
  assert.deepEqual(classRoster(200, 0.2).members, roster.members.slice(0, 40));
});

test('class learners and the battle pairs of the mix do not conflict: a pair is two friends whoever of them is in a class', () => {
  // The pairs are (2k, 2k + 1) from index 0; at 0.2 a member sits in every fifth index, so a pair holds at most one member.
  const allocation = allocateMix(1250, { training: 0.45, game: 0.2, practice: 0.2, battle: 0.15 });
  const { start, end } = allocation.ranges.battle;
  for (let i = start; i < end; i += 2) {
    assert.ok(!(isClassLearner(i, 0.2) && isClassLearner(i + 1, 0.2)), `pair ${i}`);
  }
  // Every range of the mix holds about its share of class learners (the browse flow then asks `/assignments` for them).
  const browse = allocateMix(1250, { training: 0.38, game: 0.17, practice: 0.17, battle: 0.13, browse: 0.15 }).ranges.browse;
  const inBrowse = classLearnerCount(browse.end, 0.2) - classLearnerCount(browse.start, 0.2);
  assert.ok(Math.abs(inBrowse - (browse.end - browse.start) * 0.2) <= 1, `${inBrowse} class learners in browse`);
});

test('burstLearner: class opens by class learners only, solo opens by the others, no reopen within the gap', () => {
  for (const [rate, ramp, hold, classShare, size] of [[20, '30s', '2m', 0.2, 1250], [20, '30s', '10m', 0.2, 1205], [7, '1m', '5m', 0.5, 2000], [50, '10s', '1m', 0.1, 3400], [5, '30s', '0', 0.2, 100]]) {
    const opens = plannedOpens(rate, ramp, hold);
    const roster = classRoster(size, 0.2);
    assert.equal(burstRosterProblem(size, 0.2, rate, opens, classShare), null, `${rate}/s, CLASS_SHARE ${classShare}, ${size} learners`);
    const members = new Set(roster.members);
    const lastOpen = new Map();
    let classOpens = 0;
    for (let i = 0; i < opens; i++) {
      const { index, member } = burstLearner(i, classShare, roster);
      assert.ok(index >= 0 && index < size);
      assert.equal(member, isClassOpen(i, classShare));
      assert.equal(members.has(index), member, `open ${i}: a class open is played by a class learner and only it`);
      classOpens += member ? 1 : 0;
      if (lastOpen.has(index)) {
        assert.ok((i - lastOpen.get(index)) / rate >= BURST_REOPEN_GAP_SECONDS, `token ${index} again after ${i - lastOpen.get(index)} opens at ${rate}/s`);
      }
      lastOpen.set(index, i);
    }
    assert.equal(classOpens, Math.floor(opens * classShare + 1e-9));
  }
  // The defaults: 2700 opens, 540 of them class opens by the 250 members, each member at most three times.
  const roster = classRoster(1250, 0.2);
  const uses = new Map();
  for (let i = 0; i < 2700; i++) {
    const { index, member } = burstLearner(i, 0.2, roster);
    if (member) {
      uses.set(index, (uses.get(index) || 0) + 1);
    }
  }
  assert.equal([...uses.values()].reduce((a, b) => a + b, 0), 540);
  assert.equal(uses.size, 250);
});

test('burstLearner without class learners in the seed: the whole file in turn, as before', () => {
  const roster = classRoster(1250, 0);
  for (const i of [0, 4, 9, 1249, 1300]) {
    assert.deepEqual(burstLearner(i, 0.2, roster), { index: burstToken(i, 1250), member: isClassOpen(i, 0.2) });
  }
  assert.equal(burstRosterProblem(1250, 0, 20, 2700, 0.2), null, 'no class learners: burstPool covers the file');
  // CLASS_SHARE 0 with a seed of classes: every open solo, by the others only.
  const seeded = classRoster(1250, 0.2);
  assert.ok([...Array(500).keys()].every((i) => !isClassLearner(burstLearner(i, 0, seeded).index, 0.2)));
});

test('burstRosterProblem: a file too small for either kind is refused with the size that fits', () => {
  assert.equal(burstKindPool(4, 540), 241);
  assert.equal(burstKindPool(16, 2160), 961);
  assert.equal(burstKindPool(4, 100), 100);
  assert.equal(burstKindPool(0, 0), 0);
  // 1200 learners: 240 members, one short of the 241 the class opens need at 4 a second.
  assert.match(burstRosterProblem(1200, 0.2, 20, 2700, 0.2), /240 class learners .* needs 241 and 961 .*TARGET_VUS=1205/);
  assert.equal(burstRosterProblem(1205, 0.2, 20, 2700, 0.2), null);
  // More class opens than the seed has class learners for: the advice names both shares.
  assert.match(burstRosterProblem(1250, 0.2, 20, 2700, 0.5), /CLASS_SHARE at most LOAD_SCENARIO_CLASS_SHARE=0.2/);
  // A short burst: every open its own learner, the file is enough when each kind has as many as its opens.
  assert.equal(burstRosterProblem(100, 0.2, 5, plannedOpens(5, '30s', '0'), 0.2), null);
  assert.match(burstRosterProblem(100, 0.2, 5, plannedOpens(5, '30s', '1m'), 0.2), /needs 61 and 241 .*TARGET_VUS=305/);
});
