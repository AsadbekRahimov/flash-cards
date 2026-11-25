<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Cup\Services\CupStandings;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupBotKit;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §3.2, §6.1 (K6): `cups:post-summaries` - one summary a day at `summary_minute` of the cup, only when somebody played that
 * day and the table changed since the last summary; never with the summaries switched off; once, whatever the number of runs.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function summaryScored(Cup $cup, Learner $learner, int $day, int $points, int $total): void
{
    $participant = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->sole();
    DB::table('cup_day_scores')->updateOrInsert(['participant_id' => $participant->id, 'day' => $day], ['cup_id' => $cup->id, 'round_points' => $points, 'updated_at' => now()]);
    $participant->forceFill(['best_total' => $total, 'best_round_points' => $total])->saveQuietly();
    app(CupStandings::class)->forget($cup->id);
}

function postSummaries(CarbonImmutable $at): void
{
    CarbonImmutable::setTestNow($at);
    Artisan::call('cups:post-summaries');
}

it('posts the summary of the day at 20:00 of the cup once, with the table and the one button «Таблица»', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $start] = CupKit::running(2); // 00:01 Tashkent of day 1
    summaryScored($cup, $a, 1, 14, 14);
    summaryScored($cup, $b, 1, 6, 6);

    postSummaries($start->addHours(19)->addMinutes(58)); // 19:59
    expect(CupMessage::query()->where('kind', 'summary')->count())->toBe(0);

    postSummaries($start->addHours(19)->addMinutes(59)); // 20:00
    postSummaries($start->addHours(20)); // a repeated run

    $summary = CupKit::telegram()->groupMessages;
    expect($summary)->toHaveCount(1)
        ->and($summary[0]['chat_id'])->toBe($cup->telegramGroup->chat_id)
        ->and($summary[0]['text'])->toContain('итоги дня 1 из 7')->toContain('1. Player 0 — 14')->toContain('2. Player 1 — 6')
        ->and(array_keys(CupBotKit::buttons($summary[0]['markup'])))->toBe(['📊 Таблица'])
        ->and(CupMessage::query()->where('dedupe_key', 'summary:1')->value('status'))->not->toBeNull()
        ->and(DB::table('cup_days')->where('cup_id', $cup->id)->where('day', 1)->value('summary_posted_at'))->not->toBeNull()
        ->and(ProductEvent::query()->where('name', ProductEventName::CupSummaryPosted->value)->count())->toBe(1);
});

it('posts nothing on a day nobody played, and nothing when the table did not change since the last summary', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    postSummaries($start->addHours(21)); // day 1: nobody played
    expect(CupKit::telegram()->groupMessages)->toBe([]);

    CarbonImmutable::setTestNow($start->addDay()->addHour());
    summaryScored($cup, $a, 2, 10, 10);
    postSummaries($start->addDay()->addHours(21)); // day 2: a summary
    expect(CupKit::telegram()->groupMessages)->toHaveCount(1);

    CarbonImmutable::setTestNow($start->addDays(2)->addHour());
    summaryScored($cup, $a, 3, 0, 10); // played day 3, all wrong: the table is the same
    postSummaries($start->addDays(2)->addHours(21));

    expect(CupKit::telegram()->groupMessages)->toHaveCount(1)
        ->and(DB::table('cup_days')->where('cup_id', $cup->id)->whereNotNull('summary_posted_at')->orderBy('day')->pluck('day')->all())->toBe([1, 2, 3]);
});

it('posts no summary while the summaries are switched off, and none for a paused cup', function (Closure $off): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    $off($cup);
    summaryScored($cup, $a, 1, 8, 8);

    postSummaries($start->addHours(21));

    expect(CupKit::telegram()->groupMessages)->toBe([])
        ->and(CupMessage::query()->where('kind', 'summary')->count())->toBe(0);
})->with([
    'switched off' => fn (Cup $cup) => Cup::query()->whereKey($cup->id)->update(['summaries_enabled' => false]),
    'the bot removed' => fn (Cup $cup) => $cup->telegramGroup->update(['bot_status' => 'left']),
]);

it('takes the minute of the summary of the cup (an administrator moved it to 09:30)', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    Cup::query()->whereKey($cup->id)->update(['summary_minute' => 570]);
    summaryScored($cup, $a, 1, 8, 8);

    postSummaries($start->addHours(9)->addMinutes(28));
    expect(CupKit::telegram()->groupMessages)->toBe([]);

    postSummaries($start->addHours(9)->addMinutes(29));
    expect(CupKit::telegram()->groupMessages)->toHaveCount(1);
});
