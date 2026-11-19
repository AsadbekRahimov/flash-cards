// Tests of plan.js (the arithmetic of the k6 scenarios) on Node's own runner - no dependency, no k6:
//   node --test tests/load/k6/plan.test.js      (or `make load-plan-test`)
// k6 never loads this file: no scenario imports it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  allocateMix,
  mixSlot,
  parseDuration,
  plannedRunSeconds,
  rampStages,
  requiredTokenSeconds,
  tokenProblem,
  TOKEN_FLOOR_SECONDS,
} from './plan.js';

const DEFAULT_SHARES = { training: 0.45, game: 0.2, practice: 0.2, battle: 0.15 };

/** Every slot of an allocation, by VU number, with the invariants checked on the way. */
function checkAllocation(mix) {
  const sumOfFlows = mix.training + mix.game + mix.practice + 2 * mix.pairs;
  assert.equal(mix.participants, sumOfFlows, 'participants = the learners of the flows');
  assert.ok(mix.participants <= mix.targetVus, `${mix.participants} participants > pool ${mix.targetVus}`);
  assert.equal(mix.totalVus, mix.training + mix.game + mix.practice + mix.pairs);

  // The ranges tile [0, participants) without overlapping; the battle starts on an even index.
  const ranges = ['battle', 'training', 'game', 'practice'].map((flow) => mix.ranges[flow]);
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
  const dealt = { training: 0, game: 0, practice: 0, battle: 0 };
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
  assert.equal(mix.totalVus, 1156);
  assert.deepEqual(mix.ranges, {
    battle: { start: 0, end: 188 },
    training: { start: 188, end: 750 },
    game: { start: 750, end: 1000 },
    practice: { start: 1000, end: 1250 },
  });
  checkAllocation(mix);
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
  ];
  for (const shares of mixes) {
    const sum = shares.training + shares.game + shares.practice + shares.battle;
    for (let target = 0; target <= 1400; target += target < 300 ? 1 : 37) {
      const mix = allocateMix(target, shares);
      checkAllocation(mix);
      // Nothing is left on the table: floor(target x sum) learners play, but for an odd one when the battle is the only flow.
      const onlyBattle = shares.training + shares.game + shares.practice === 0;
      assert.ok(mix.participants >= Math.floor(target * sum + 1e-9) - (onlyBattle ? 1 : 0), `${JSON.stringify(shares)} at ${target}: ${mix.participants}`);
    }
  }
});

test('the interleaving keeps the shares on every prefix of the VU numbers (the ramp)', () => {
  const mix = allocateMix(1250, DEFAULT_SHARES);
  const dealt = { training: 0, game: 0, practice: 0, battle: 0 };
  for (let vu = 1; vu <= mix.totalVus; vu++) {
    dealt[mixSlot(mix, vu).flow]++;
    for (const flow of Object.keys(dealt)) {
      const ideal = (mix.vus[flow] * vu) / mix.totalVus;
      assert.ok(Math.abs(dealt[flow] - ideal) <= 1, `${flow} at VU ${vu}: ${dealt[flow]} vs ${ideal.toFixed(2)}`);
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
