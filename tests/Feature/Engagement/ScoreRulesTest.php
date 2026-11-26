<?php

declare(strict_types=1);

/*
 * The word-points rules (docs/32 §6.1, S3): the mode, the immutable versions of the configuration, the version of a period by its
 * first day (live only), the rating day of the league zone.
 */

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Enums\ScoringMode;
use App\Domain\Engagement\Services\Scoring\ScoreRules;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

it('reads version 2 as published: weights, interval, best days and the limits of both categories', function (): void {
    $v2 = app(ScoreRules::class)->version(2);

    expect($v2)->not->toBeNull()
        ->and($v2->startsOn)->toBe('2027-02-01')
        ->and($v2->pointsFor(ScoreKind::New))->toBe(2)
        ->and($v2->pointsFor(ScoreKind::Review))->toBe(3)
        ->and($v2->pointsFor(ScoreKind::Long))->toBe(5)
        ->and($v2->longIntervalDays)->toBe(7)
        ->and($v2->bestDays)->toBe(5)
        ->and((array) $v2->limitsFor(ScoreCategory::Free))->toBe(['new' => 20, 'review' => 20, 'dayCap' => 100])
        ->and((array) $v2->limitsFor(ScoreCategory::Pro))->toBe(['new' => 30, 'review' => 30, 'dayCap' => 150])
        ->and(app(ScoreRules::class)->version(1))->toBeNull();
});

it('gives a period the greatest version started by its first day, in live mode only', function (): void {
    $rules = app(ScoreRules::class);

    expect($rules->versionFor('2027-01-25'))->toBe(1)
        ->and($rules->versionFor('2027-02-01'))->toBe(2)
        ->and($rules->versionFor('2027-03-01'))->toBe(2)
        ->and($rules->versionFor(CarbonImmutable::parse('2027-02-01 00:00', 'Asia/Tashkent')))->toBe(2)
        // 31 January 19:30 UTC is already 1 February in Tashkent.
        ->and($rules->versionFor(CarbonImmutable::parse('2027-01-31 19:30', 'UTC')))->toBe(2);

    config()->set('leagues.scoring.versions.3', [
        'starts_on' => '2027-06-01',
        'points' => ['new' => 1, 'review' => 2, 'long' => 4],
        'long_interval_days' => 7,
        'best_days' => 5,
        'limits' => ['free' => ['new' => 10, 'review' => 10, 'day_cap' => 50], 'pro' => ['new' => 20, 'review' => 20, 'day_cap' => 90]],
    ]);
    expect($rules->versionFor('2027-05-31'))->toBe(2)
        ->and($rules->versionFor('2027-06-01'))->toBe(3)
        ->and(array_keys($rules->versions()))->toBe([2, 3]);

    foreach (['off', 'shadow'] as $mode) {
        config()->set('leagues.scoring.mode', $mode);
        expect($rules->versionFor('2027-06-01'))->toBe(1);
    }
});

it('never applies a version without a start date or with a malformed entry', function (array $override): void {
    config()->set('leagues.scoring.versions.2', array_replace_recursive((array) config('leagues.scoring.versions.2'), $override));

    expect(app(ScoreRules::class)->versionFor('2030-01-01'))->toBe(1);
})->with([
    'no date' => [['starts_on' => null]],
    'empty date' => [['starts_on' => '']],
    'not a date' => [['starts_on' => 'soon']],
    'impossible date' => [['starts_on' => '2027-02-30']],
    'missing weight' => [['points' => ['long' => 'x']]],
    'negative limit' => [['limits' => ['pro' => ['day_cap' => -1]]]],
]);

it('reads the mode, falling back to off for an unknown value', function (mixed $value, ScoringMode $mode): void {
    config()->set('leagues.scoring.mode', $value);

    expect(app(ScoreRules::class)->mode())->toBe($mode)
        ->and(app(ScoreRules::class)->records())->toBe($mode !== ScoringMode::Off);
})->with([
    ['off', ScoringMode::Off],
    ['shadow', ScoringMode::Shadow],
    [' LIVE ', ScoringMode::Live],
    ['on', ScoringMode::Off],
    [null, ScoringMode::Off],
]);

it('takes the rating day and the periods in the league zone', function (): void {
    $rules = app(ScoreRules::class);
    // Sunday 19:01 UTC = Monday 00:01 in Tashkent.
    $now = CarbonImmutable::parse('2027-02-07 19:01', 'UTC');

    expect($rules->ratingDay($now)->toDateString())->toBe('2027-02-08')
        ->and($rules->periodStart(ScorePeriod::Week, $now)->toDateString())->toBe('2027-02-08')
        ->and($rules->periodStart(ScorePeriod::Month, $now)->toDateString())->toBe('2027-02-01')
        ->and($rules->periodStart(ScorePeriod::Week, CarbonImmutable::parse('2027-02-07 18:59', 'UTC'))->toDateString())->toBe('2027-02-01');
});

it('ships with the crediting off and the game switch and retention from the configuration', function (): void {
    config()->set('leagues.scoring.mode', 'off');
    config()->set('leagues.scoring.game_enabled', false);
    config()->set('leagues.scoring.credits_retention_days', 0);

    $rules = app(ScoreRules::class);
    expect($rules->records())->toBeFalse()
        ->and($rules->gameEnabled())->toBeFalse()
        ->and($rules->creditsRetentionDays())->toBe(1);
});

it('parses the configured versions once and again only when the configuration changes', function (): void {
    $rules = app(ScoreRules::class);

    $first = $rules->versions();
    // The same objects, not equal copies: nothing was parsed again - also for another instance of the class (the memory is the
    // class's: a request builds several).
    expect($rules->versions()[2])->toBe($first[2])
        ->and(app(ScoreRules::class)->version(2))->toBe($first[2])
        ->and($rules->versionFor('2027-02-01'))->toBe(2);

    // A changed configuration (a test, a new deploy under Octane) is read at once.
    config()->set('leagues.scoring.versions.2.starts_on', '2027-03-01');
    expect($rules->version(2)?->startsOn)->toBe('2027-03-01')
        ->and($rules->version(2))->not->toBe($first[2])
        ->and($rules->versionFor('2027-02-01'))->toBe(1)
        ->and($rules->versionFor('2027-03-01'))->toBe(2);

    config()->set('leagues.scoring.versions.2.limits.free.day_cap', 80);
    expect($rules->version(2)?->limitsFor(ScoreCategory::Free)->dayCap)->toBe(80);

    // A malformed version leaves the list, a well-formed one comes back.
    config()->set('leagues.scoring.versions.2.best_days', 'many');
    expect($rules->versions())->toBe([]);
    config()->set('leagues.scoring.versions.2.best_days', 4);
    expect($rules->version(2)?->bestDays)->toBe(4);
});
