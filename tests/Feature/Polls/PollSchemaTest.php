<?php

declare(strict_types=1);

/*
 * The tables of the polls in the bot (docs/35 §1.4, O2): the models and factories write and read them; the keys hold (one delivery
 * per learner and poll - but any number of test deliveries, one token, one vote per option, one option per position); the CHECKs
 * of PostgreSQL stop what the enums stop in PHP; the final purge of a learner takes their deliveries and votes, a poll's delete
 * takes everything of it; the indexes are exactly the planned ones; and the four migrations roll back and forth. A violating
 * insert runs in a savepoint, as PostgreSQL aborts the transaction otherwise.
 */

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const POLL_MIGRATIONS = [
    '2026_11_27_100001_create_polls_table',
    '2026_11_27_100002_create_poll_options_table',
    '2026_11_27_100003_create_poll_deliveries_table',
    '2026_11_27_100004_create_poll_votes_table',
];

/** @return list<string> */
function pollIndexNames(string $table): array
{
    $names = array_column(Schema::getIndexes($table), 'name');
    sort($names);

    return array_values(array_filter($names, fn (string $name): bool => ! str_starts_with($name, 'sqlite_autoindex') && $name !== 'primary' && ! str_ends_with($name, '_pkey')));
}

it('creates the four tables with their columns', function (): void {
    expect(Schema::hasColumns('polls', [
        'id', 'title', 'question', 'image_path', 'image_file_id', 'allows_multiple', 'max_choices', 'allow_change', 'audience', 'status',
        'closes_at', 'launched_at', 'closed_at', 'created_by_user_id', 'launched_by_user_id', 'results_snapshot', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('poll_options', ['id', 'poll_id', 'position', 'label']))->toBeTrue()
        ->and(Schema::hasColumns('poll_deliveries', [
            'id', 'poll_id', 'learner_id', 'telegram_user_id', 'is_test', 'token', 'locale', 'status', 'message_id', 'sent_at', 'error_code',
            'draft', 'answered_at', 'changed_count',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('poll_votes', ['id', 'poll_id', 'poll_delivery_id', 'poll_option_id', 'created_at']))->toBeTrue();
});

it('reads and writes the rows through the models and factories', function (): void {
    $admin = User::factory()->create();
    $poll = Poll::factory()->multiple(2)->withOptions(3)->create(['created_by_user_id' => $admin->id, 'closes_at' => now()->addDay()]);
    $learner = Learner::factory()->create(['telegram_user_id' => 700_000_001]);
    $delivery = PollDelivery::factory()->sent(55)->create(['poll_id' => $poll->id, 'learner_id' => $learner->id, 'draft' => [1, 2]]);
    [$first, , $third] = $poll->options->all();
    PollVote::factory()->create(['poll_delivery_id' => $delivery->id, 'poll_option_id' => $first->id]);
    PollVote::factory()->create(['poll_delivery_id' => $delivery->id, 'poll_option_id' => $third->id]);

    $poll = Poll::query()->sole();
    $delivery = PollDelivery::query()->sole();

    expect($poll->status)->toBe(PollStatus::Draft)
        ->and($poll->question)->toBe(['ru' => 'Какой формат занятий вам удобнее?', 'uz' => 'Qaysi dars formati sizga qulayroq?'])
        ->and(ksorted($poll->audience))->toBe(['course_ids' => [], 'locales' => [], 'onboarded_only' => true, 'plan' => 'all', 'region_codes' => []])
        ->and($poll->allows_multiple)->toBeTrue()
        ->and($poll->max_choices)->toBe(2)
        ->and($poll->allow_change)->toBeTrue()
        ->and($poll->closes_at?->isFuture())->toBeTrue()
        ->and($poll->createdBy?->id)->toBe($admin->id)
        ->and($poll->options->pluck('position')->all())->toBe([0, 1, 2])
        ->and($poll->options[0]->label)->toHaveKeys(['ru', 'uz'])
        ->and($delivery->status)->toBe(PollDeliveryStatus::Sent)
        ->and($delivery->message_id)->toBe(55)
        ->and($delivery->telegram_user_id)->toBe(700_000_001)
        ->and($delivery->is_test)->toBeFalse()
        ->and(strlen($delivery->token))->toBe(12)
        ->and($delivery->draft)->toBe([1, 2])
        ->and($delivery->changed_count)->toBe(0)
        ->and($delivery->learner?->id)->toBe($learner->id)
        ->and($delivery->votes()->orderBy('poll_option_id')->pluck('poll_option_id')->all())->toBe([$first->id, $third->id])
        ->and(PollVote::query()->pluck('poll_id')->unique()->all())->toBe([$poll->id])
        ->and($poll->votes()->count())->toBe(2);
});

it('defaults a new poll and delivery to the first status', function (): void {
    $poll = Poll::query()->create([
        'title' => 'T',
        'question' => ['ru' => 'В?', 'uz' => 'S?'],
        'audience' => ['plan' => 'all'],
    ]);
    $delivery = PollDelivery::query()->create(['poll_id' => $poll->id, 'telegram_user_id' => 5, 'is_test' => true, 'token' => 'abcdefABCDEF', 'locale' => 'uz']);

    expect($poll->fresh()?->status)->toBe(PollStatus::Draft)
        ->and($poll->fresh()?->allows_multiple)->toBeFalse()
        ->and($poll->fresh()?->allow_change)->toBeTrue()
        ->and($delivery->fresh()?->status)->toBe(PollDeliveryStatus::Pending)
        ->and($delivery->fresh()?->changed_count)->toBe(0);
});

it('allows one delivery per learner and poll, any number of test deliveries, and one token', function (): void {
    $delivery = PollDelivery::factory()->create();

    expect(fn () => DB::transaction(fn () => PollDelivery::factory()->create(['poll_id' => $delivery->poll_id, 'learner_id' => $delivery->learner_id])))
        ->toThrow(UniqueConstraintViolationException::class);

    PollDelivery::factory()->test(42)->count(2)->create(['poll_id' => $delivery->poll_id]);
    PollDelivery::factory()->create(['learner_id' => $delivery->learner_id]);

    expect(fn () => DB::transaction(fn () => PollDelivery::factory()->create(['token' => $delivery->token])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(PollDelivery::query()->count())->toBe(4);
});

it('lets the launch insert skip a learner who already has the delivery (ON CONFLICT on the unique key)', function (): void {
    $poll = Poll::factory()->create();
    [$a, $b] = Learner::factory()->count(2)->create();
    PollDelivery::factory()->create(['poll_id' => $poll->id, 'learner_id' => $a->id]);

    $rows = collect([$a, $b])->map(fn (Learner $learner): array => [
        'poll_id' => $poll->id,
        'learner_id' => $learner->id,
        'telegram_user_id' => $learner->telegram_user_id,
        'token' => str_pad((string) $learner->id, 12, 'x'),
        'locale' => 'ru',
    ])->all();
    $inserted = DB::table('poll_deliveries')->insertOrIgnore($rows);

    expect($inserted)->toBe(1)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->count())->toBe(2);
});

it('allows one vote per option of a delivery and one option per position', function (): void {
    $vote = PollVote::factory()->create();

    expect(fn () => DB::transaction(fn () => PollVote::factory()->create(['poll_delivery_id' => $vote->poll_delivery_id, 'poll_option_id' => $vote->poll_option_id])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => PollOption::factory()->create(['poll_id' => $vote->poll_id, 'position' => 0])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('takes the deliveries and votes of a learner with the final purge, and keeps the poll', function (): void {
    $vote = PollVote::factory()->create();
    $other = PollVote::factory()->create();
    $learner = Learner::withTrashed()->findOrFail(PollDelivery::query()->findOrFail($vote->poll_delivery_id)->learner_id);

    $learner->forceDelete();

    expect(PollDelivery::query()->pluck('id')->all())->toBe([$other->poll_delivery_id])
        ->and(PollVote::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Poll::query()->count())->toBe(2)
        ->and(PollOption::query()->count())->toBe(2);
});

it('takes everything of a poll with its delete, and keeps a poll whose author is deleted', function (): void {
    $author = User::factory()->create();
    $vote = PollVote::factory()->create();
    Poll::query()->whereKey($vote->poll_id)->update(['created_by_user_id' => $author->id, 'launched_by_user_id' => $author->id]);
    PollDelivery::factory()->test()->create(['poll_id' => $vote->poll_id]);
    $kept = PollVote::factory()->create();

    $author->delete();
    $poll = Poll::query()->findOrFail($vote->poll_id);
    expect($poll->created_by_user_id)->toBeNull()
        ->and($poll->launched_by_user_id)->toBeNull();

    $poll->delete();

    expect(PollOption::query()->pluck('poll_id')->unique()->all())->toBe([$kept->poll_id])
        ->and(PollDelivery::query()->pluck('poll_id')->unique()->all())->toBe([$kept->poll_id])
        ->and(PollVote::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('stops a status, a position, a learner of a test delivery and a choice limit outside the rules (PostgreSQL CHECKs)', function (Closure $write): void {
    $delivery = PollDelivery::factory()->create();
    $option = PollOption::factory()->create(['poll_id' => $delivery->poll_id]);

    expect(fn () => DB::transaction(fn () => $write($delivery, $option)))->toThrow(QueryException::class);
})->with([
    'poll status' => [fn (PollDelivery $d): int => DB::table('polls')->whereKey($d->poll_id)->update(['status' => 'archived'])],
    'max_choices 0' => [fn (PollDelivery $d): int => DB::table('polls')->whereKey($d->poll_id)->update(['max_choices' => 0])],
    'delivery status' => [fn (PollDelivery $d): int => DB::table('poll_deliveries')->whereKey($d->id)->update(['status' => 'queued'])],
    'a test delivery with a learner' => [fn (PollDelivery $d): int => DB::table('poll_deliveries')->whereKey($d->id)->update(['is_test' => true])],
    'a real delivery without a learner' => [fn (PollDelivery $d): int => DB::table('poll_deliveries')->whereKey($d->id)->update(['learner_id' => null])],
    'negative changed_count' => [fn (PollDelivery $d): int => DB::table('poll_deliveries')->whereKey($d->id)->update(['changed_count' => -1])],
    'position 10' => [fn (PollDelivery $d, PollOption $o): int => DB::table('poll_options')->whereKey($o->id)->update(['position' => 10])],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'the CHECKs are PostgreSQL only');

it('has exactly the planned indexes', function (): void {
    expect(pollIndexNames('polls'))->toBe(['polls_close_due_idx'])
        ->and(pollIndexNames('poll_options'))->toBe(['poll_options_poll_id_position_unique'])
        ->and(pollIndexNames('poll_deliveries'))->toBe([
            'poll_deliveries_learner_id_index',
            'poll_deliveries_poll_id_learner_id_unique',
            'poll_deliveries_token_unique',
            'poll_deliveries_unsent_idx',
        ])
        ->and(pollIndexNames('poll_votes'))->toBe([
            'poll_votes_poll_delivery_id_poll_option_id_unique',
            'poll_votes_poll_id_poll_option_id_index',
        ]);
});

it('keeps only the unsent deliveries in the partial index', function (): void {
    $poll = Poll::factory()->create();
    $pending = PollDelivery::factory()->create(['poll_id' => $poll->id]);
    $sending = PollDelivery::factory()->create(['poll_id' => $poll->id, 'status' => PollDeliveryStatus::Sending]);
    PollDelivery::factory()->sent()->create(['poll_id' => $poll->id]);

    // The query the batches and the "anything left" check run; on PostgreSQL the plan must use the partial index.
    $query = "SELECT id FROM poll_deliveries WHERE poll_id = ? AND status IN ('pending','sending') ORDER BY id";
    expect(array_column(DB::select($query, [$poll->id]), 'id'))->toBe([$pending->id, $sending->id]);

    if (DB::getDriverName() === 'pgsql') {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = implode("\n", array_column(array_map(fn (object $row): array => (array) $row, DB::select('EXPLAIN '.$query, [$poll->id])), 'QUERY PLAN'));
        expect($plan)->toContain('poll_deliveries_unsent_idx');
    }
});

it('rolls the four tables back and forth', function (): void {
    $migrations = array_map(fn (string $file): object => require database_path("migrations/{$file}.php"), POLL_MIGRATIONS);

    foreach (array_reverse($migrations) as $migration) {
        $migration->down();
    }

    expect(Schema::hasTable('polls'))->toBeFalse()
        ->and(Schema::hasTable('poll_options'))->toBeFalse()
        ->and(Schema::hasTable('poll_deliveries'))->toBeFalse()
        ->and(Schema::hasTable('poll_votes'))->toBeFalse();

    foreach ($migrations as $migration) {
        $migration->up();
    }

    expect(pollIndexNames('poll_deliveries'))->toContain('poll_deliveries_unsent_idx')
        ->and(pollIndexNames('polls'))->toContain('polls_close_due_idx');

    PollVote::factory()->create();
    expect(PollVote::query()->count())->toBe(1);
});
