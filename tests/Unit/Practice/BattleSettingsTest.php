<?php

declare(strict_types=1);

use App\Domain\Practice\Services\Battle\BattleSettings;

/*
 * docs/16 §8.4, §14.4 (18.12a): the grace of the presence has a floor. The heartbeat of a player is written at most once per
 * `heartbeat_write_seconds` and the poll beats every 2 s - up to 10 s after a failed one - so the mark of a player who is here is up
 * to ~15 s old; a grace below that (the 5 s an earlier docs/16 §14.4 proposed for e2e) cancels healthy matches in the countdown.
 */

it('keeps the grace of the presence at or above heartbeat_write_seconds + the longest pause of the poll, whatever the environment says', function (): void {
    $settings = new BattleSettings;
    expect($settings->presenceGraceFloorSeconds())->toBe(15)
        ->and($settings->presenceGraceSeconds())->toBe(30);

    foreach ([5 => 15, 12 => 15, 15 => 15, 16 => 16, 45 => 45] as $configured => $effective) {
        config(['practice.battle.presence_grace_seconds' => $configured]);
        expect($settings->presenceGraceSeconds())->toBe($effective, "grace {$configured}");
    }

    // The floor follows the heartbeat: a heartbeat written every 8 s needs 18 s of grace.
    config(['practice.battle.heartbeat_write_seconds' => 8, 'practice.battle.presence_grace_seconds' => 5]);
    expect($settings->presenceGraceFloorSeconds())->toBe(18)
        ->and($settings->presenceGraceSeconds())->toBe(18);
});

it('keeps the warning of the silence below the grace, the floored one included', function (): void {
    $settings = new BattleSettings;
    config(['practice.battle.presence_grace_seconds' => 5]);
    expect($settings->presenceWarnSeconds())->toBe(10);

    config(['practice.battle.presence_warn_seconds' => 99]);
    expect($settings->presenceWarnSeconds())->toBe(15);

    config(['practice.battle.presence_grace_seconds' => 30]);
    expect($settings->presenceWarnSeconds())->toBe(30);
});
