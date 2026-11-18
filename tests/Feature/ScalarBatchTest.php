<?php

declare(strict_types=1);

use App\Domain\Operations\Support\ScalarBatch;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('counts the rows of a query that already has a select list and an order: both are replaced, the callers builder is untouched', function (): void {
    Learner::factory()->count(3)->create();
    $rows = DB::table('learners')->select('id', 'daily_goal')->orderBy('id');

    $scalars = (new ScalarBatch)->count('total', $rows)->run();

    expect(ScalarBatch::int($scalars, 'total'))->toBe(3)
        ->and($rows->columns)->toBe(['id', 'daily_goal'])
        ->and($rows->orders)->not->toBeEmpty();
});

it('flags existence with a select 1 limit 1, whatever select the rows had', function (): void {
    $learner = Learner::factory()->create();

    $scalars = (new ScalarBatch)
        ->exists('some', DB::table('learners')->select('id', 'daily_goal')->where('id', $learner->id))
        ->exists('none', DB::table('learners')->where('id', 0))
        ->run();

    expect(ScalarBatch::int($scalars, 'some'))->toBe(1)->and(ScalarBatch::int($scalars, 'none'))->toBe(0);
});
