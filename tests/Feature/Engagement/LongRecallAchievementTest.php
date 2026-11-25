<?php

declare(strict_types=1);

/*
 * The achievement `long_recall_25` (docs/32 §8.4, task S14) and the follow-ups of an answer's word points after its commit
 * (WordScoreHooks, the binding of the contract AnswerScoreRecorder): the 25th lexeme with a long repetition unlocks the key, not the
 * 24th; once; looked at only when the answer gave a lexeme its first long repetition, by a read that stops at 25 index entries. The
 * event `score.day_capped` is recorded by the one answer that takes a count of the day to its cap.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Jobs\SendAchievementJob;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\Lexeme;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.versions.2.starts_on' => '2027-02-01',
    ]);
});

/** `$count` lexemes of the learner that already had a long repetition. */
function lraLongLexemes(Learner $learner, int $count): void
{
    foreach (Lexeme::factory()->count($count)->create() as $lexeme) {
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id, 'confirmed_on' => '2027-01-01', 'first_long_on' => '2027-02-02']);
    }
}

/** A long repetition of a confirmed lexeme due an hour ago (interval 7 days) - the answer that gives it its first long repetition. */
function lraLongAnswer(Learner $learner, CarbonImmutable $now): WordScoreOutcome
{
    $lexeme = Lexeme::factory()->create();
    LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme->id, 'confirmed_on' => '2027-01-01']);

    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(new ScoredAnswer(
        $learner->id, $lexeme->id, 1, ScoreSource::Training, 7, AnswerVerdict::Passed,
        new CardStateBefore(RepetitionState::Review, 7, $now->subDays(7)->subHour()), Plan::Free, $now,
    )));
}

function lraNewAnswer(Learner $learner, CarbonImmutable $now): WordScoreOutcome
{
    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(new ScoredAnswer(
        $learner->id, Lexeme::factory()->create()->id, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Free, $now,
    )));
}

function lraHeld(Learner $learner): bool
{
    return Achievement::query()->where('learner_id', $learner->id)->where('key', 'long_recall_25')->exists();
}

it('is a counting key of its own family with the threshold 25', function (): void {
    expect(AchievementKey::LongRecall25->value)->toBe('long_recall_25')
        ->and(AchievementKey::LongRecall25->family())->toBe('recall')
        ->and(AchievementKey::LongRecall25->threshold())->toBe(25)
        ->and(AchievementKey::LongRecall25->badge())->toBe('25')
        ->and(AchievementKey::LongRecall25->title('ru'))->toBe('Долгая память')
        ->and(AchievementKey::LongRecall25->title('uz'))->toBe('Uzoq xotira');
});

it('unlocks on the 25th long lexeme, not on the 24th, and only once', function (): void {
    $learner = Learner::factory()->create();
    $service = app(AchievementService::class);
    lraLongLexemes($learner, 24);

    expect($service->onLongRecall($learner->id, CarbonImmutable::now()))->toBeNull()
        ->and(lraHeld($learner))->toBeFalse();

    lraLongLexemes($learner, 1);
    $row = $service->onLongRecall($learner->id, CarbonImmutable::now());
    expect($row)->not->toBeNull()
        ->and($row->meta)->toBe(['value' => 25])
        ->and($service->onLongRecall($learner->id, CarbonImmutable::now()))->toBeNull()
        ->and(Achievement::query()->where('key', 'long_recall_25')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::AchievementUnlocked->value)->count())->toBe(1);
    Queue::assertPushed(SendAchievementJob::class, 1);
});

it('reads at most 25 index entries, and nothing but the existence check once held', function (): void {
    $learner = Learner::factory()->create();
    lraLongLexemes($learner, 30);
    $service = app(AchievementService::class);

    $first = QueryLog::during(fn () => $service->onLongRecall($learner->id, CarbonImmutable::now()));
    $count = collect($first)->first(fn (string $q): bool => str_contains($q, 'learner_lexemes'));
    expect($count)->not->toBeNull()
        ->and(strtolower((string) $count))->toContain('limit 25')
        ->and(strtolower((string) $count))->toContain('"first_long_on" is not null');

    $again = QueryLog::during(fn () => $service->onLongRecall($learner->id, CarbonImmutable::now()));
    expect($again)->toHaveCount(1)
        ->and($again[0])->toContain('achievements');
});

it('gives nothing to a blocked learner or with the achievements off', function (): void {
    $blocked = Learner::factory()->create(['blocked_at' => now()]);
    lraLongLexemes($blocked, 25);
    $other = Learner::factory()->create();
    lraLongLexemes($other, 25);

    expect(app(AchievementService::class)->onLongRecall($blocked->id, CarbonImmutable::now()))->toBeNull();

    config(['achievements.enabled' => false]);
    expect(app(AchievementService::class)->onLongRecall($other->id, CarbonImmutable::now()))->toBeNull()
        ->and(Achievement::query()->count())->toBe(0);
});

it('is looked at after the commit of the answer that gave the 25th lexeme its first long repetition', function (): void {
    $learner = Learner::factory()->create();
    lraLongLexemes($learner, 24);
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();

    $outcome = lraLongAnswer($learner, $now);

    expect($outcome->kind)->toBe(ScoreKind::Long)
        ->and($outcome->newLongLexeme)->toBeTrue()
        ->and(lraHeld($learner))->toBeTrue();
});

it('does not look when the answer gave no new long repetition, nor inside a transaction that rolled back', function (): void {
    $learner = Learner::factory()->create();
    lraLongLexemes($learner, 25); // enough already, but nothing asks
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();

    $queries = QueryLog::during(fn () => lraNewAnswer($learner, $now));
    expect(QueryLog::matching($queries, '/achievements/'))->toBeEmpty()
        ->and(lraHeld($learner))->toBeFalse();

    try {
        DB::transaction(function () use ($learner, $now): void {
            lraLongAnswer($learner, $now->addMinute());
            throw new RuntimeException('the answer failed');
        });
    } catch (RuntimeException) {
    }
    expect(lraHeld($learner))->toBeFalse();
});

it('costs 2 statements after the commit of a first long repetition, 1 once the key is held, none otherwise', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();
    lraLongAnswer($learner, $now); // the day row and the periods exist from here on

    $long = QueryLog::during(fn () => lraLongAnswer($learner, $now->addMinutes(2)));
    expect(QueryLog::matching($long, '/["`]achievements["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($long, '/limit 25/i'))->toHaveCount(1);

    Achievement::query()->create(['learner_id' => $learner->id, 'key' => 'long_recall_25', 'unlocked_at' => now()]);
    $held = QueryLog::during(fn () => lraLongAnswer($learner, $now->addMinutes(3)));
    expect(QueryLog::matching($held, '/["`]achievements["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($held, '/limit 25/i'))->toBe([])
        ->and(QueryLog::matching(QueryLog::during(fn () => lraNewAnswer($learner, $now->addMinutes(4))), '/achievements|limit 25/i'))->toBe([]);
});

it('shows the progress towards 25 in the list, the full target once held', function (): void {
    $learner = Learner::factory()->create();
    lraLongLexemes($learner, 7);
    $entry = fn () => collect(app(AchievementService::class)->overview($learner)->entries)->first(fn ($e) => $e->key === AchievementKey::LongRecall25);

    expect($entry()->progressCurrent)->toBe(7)
        ->and($entry()->progressTarget)->toBe(25)
        ->and($entry()->unlocked())->toBeFalse();

    lraLongLexemes($learner, 30);
    expect($entry()->progressCurrent)->toBe(25);

    app(AchievementService::class)->onLongRecall($learner->id, CarbonImmutable::now());
    expect($entry()->unlocked())->toBeTrue()
        ->and($entry()->progressCurrent)->toBe(25);
});

it('records score.day_capped once, by the answer that takes a count of the day to its cap', function (): void {
    config(['leagues.scoring.versions.2.limits.free.day_cap' => 5]);
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();

    lraNewAnswer($learner, $now);                 // 2
    lraNewAnswer($learner, $now->addMinute());    // 4
    expect(ProductEvent::query()->where('name', 'score.day_capped')->count())->toBe(0);

    $capping = lraNewAnswer($learner, $now->addMinutes(2)); // 5 (+1, the rest of the cap)
    lraNewAnswer($learner, $now->addMinutes(3));            // 0 more, still capped

    $events = ProductEvent::query()->where('name', 'score.day_capped')->orderBy('id')->get();
    expect($capping->weekAdded)->toBe(1)
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('props.count')->all())->toBe(['week', 'month'])
        ->and($events[0]->props)->toMatchArray(['rating_day' => '2027-02-10', 'category' => 'free', 'cap' => 5])
        ->and($events[0]->learner_id)->toBe($learner->id);
});

it('records no day_capped event on the old rules (shadow)', function (): void {
    config(['leagues.scoring.mode' => 'shadow', 'leagues.scoring.versions.2.limits.free.day_cap' => 1]);
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-10 12:00', 'Asia/Tashkent')->utc();

    lraNewAnswer($learner, $now);
    lraNewAnswer($learner, $now->addMinute());

    expect(ProductEvent::query()->where('name', 'score.day_capped')->count())->toBe(0);
});
