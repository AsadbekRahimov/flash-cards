<?php

declare(strict_types=1);

use App\Domain\Operations\DTO\PerfSeedOptions;
use App\Domain\Operations\Services\PerfSeeder;
use App\Domain\Operations\Support\PerfBatchWriter;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\QueryLog;

/*
 * `perf:seed` (docs/20 F0, docs/19 C0): volume data for measuring. These tests run it on a small volume - on SQLite by
 * default and on PostgreSQL in the CI/PG run - and check that what it leaves behind is one consistent data set.
 */

uses(RefreshDatabase::class);

/** @return array<string, int|string> the options of a small run */
function perfSmall(array $override = []): array
{
    return [
        '--learners' => 60,
        '--words' => 150,
        '--courses' => 3,
        '--repetitions' => 8,
        '--reviews' => 40,
        '--payers' => 6,
        '--exams' => 7,
        '--organizations' => 2,
        '--members' => 10,
        '--groups' => 3,
        '--events' => 100,
        '--seed' => 5,
        ...$override,
    ];
}

/** Rows of $child whose $column points at no row of $parent. */
function perfOrphans(string $child, string $column, string $parent): int
{
    return DB::table($child)
        ->leftJoin("{$parent} as parent_row", 'parent_row.id', '=', "{$child}.{$column}")
        ->whereNotNull("{$child}.{$column}")
        ->whereNull('parent_row.id')
        ->count();
}

/** A few aggregates over the seeded rows, enough to tell two runs apart. */
function perfDigest(): array
{
    return [
        'learners' => [DB::table('learners')->count(), (int) DB::table('learners')->sum('streak_best'), DB::table('learners')->min('first_name'), DB::table('learners')->max('username')],
        'words' => [DB::table('words')->count(), DB::table('words')->min('word'), DB::table('words')->max('translation')],
        'repetitions' => [DB::table('word_repetitions')->count(), (int) DB::table('word_repetitions')->sum('repetitions'), (int) DB::table('word_repetitions')->sum('lapses'), DB::table('word_repetitions')->where('is_hard', true)->count(), DB::table('word_repetitions')->max('next_review_at')],
        'reviews' => [DB::table('training_reviews')->count(), (int) DB::table('training_reviews')->sum('quality')],
        'payments' => [DB::table('payments')->count(), (int) DB::table('payments')->sum('amount')],
        'answers' => [DB::table('exam_answers')->count(), (int) DB::table('exam_answers')->sum('score')],
        'events' => DB::table('product_events')->select('name', DB::raw('count(*) as total'))->groupBy('name')->orderBy('name')->pluck('total', 'name')->all(),
    ];
}

function perfClear(): void
{
    foreach (array_reverse(PerfSeeder::TABLES) as $table) {
        DB::table($table)->delete();
    }
}

it('refuses to run outside the local and testing environments and writes nothing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('perf:seed', perfSmall())
        ->expectsOutputToContain('local and testing')
        ->assertFailed();

    expect(Learner::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);

    expect(fn () => app(PerfSeeder::class)->run(PerfSeedOptions::make(learners: 5, words: 10, courses: 1)))
        ->toThrow(RuntimeException::class, 'local and testing');

    // The staging "demo seed" switch that opens the demo seeders does not open this command.
    config(['app.demo_seed' => true]);
    $this->artisan('perf:seed', perfSmall())->assertFailed();
    expect(Learner::query()->count())->toBe(0);
});

it('rejects options that cannot make a data set', function (array $options, string $message): void {
    $this->artisan('perf:seed', perfSmall($options))
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(Learner::query()->count())->toBe(0);
})->with([
    'no learners' => [['--learners' => 0], '--learners must be at least 1'],
    'fewer words than courses' => [['--words' => 2], '--words must be at least --courses'],
    'more payers than learners' => [['--payers' => 61], '--payers cannot exceed --learners'],
    'negative events' => [['--events' => -1], '--events cannot be negative'],
]);

it('leaves one consistent data set at a small volume', function (): void {
    $this->artisan('perf:seed', perfSmall())->assertSuccessful();

    // Sizes asked for.
    expect(DB::table('learners')->count())->toBe(60)
        ->and(DB::table('words')->count())->toBe(150)
        ->and(DB::table('word_translations')->count())->toBe(300)
        ->and(DB::table('courses')->where('status', 'published')->count())->toBe(3)
        ->and(DB::table('lessons')->count())->toBe(6)
        ->and(DB::table('training_reviews')->count())->toBe(40)
        ->and(DB::table('product_events')->count())->toBe(100)
        ->and(DB::table('organizations')->count())->toBe(2)
        ->and(DB::table('organization_members')->count())->toBe(20)
        ->and(DB::table('payment_orders')->where('status', 'paid')->count())->toBe(8) // 6 payers + 2 centers
        ->and(DB::table('subscriptions')->count())->toBe(8)
        ->and(DB::table('exam_sessions')->count())->toBe(7)
        ->and(DB::table('exam_sessions')->where('mode', 'personal_mock')->count())->toBe(1)
        // 2 centers x 4 groups + 3 standalone.
        ->and(DB::table('telegram_groups')->count())->toBe(11);

    // The courses' counters are the active words (the way CoursePublisher::recount keeps them).
    $active = (int) DB::table('words')->where('is_active', true)->count();
    expect((int) DB::table('courses')->sum('words_count'))->toBe($active)->and($active)->toBeLessThan(150);

    // Every foreign key points at a row (on SQLite too, where Laravel enforces them as well: this is the second check).
    foreach ([
        ['word_repetitions', 'learner_id', 'learners'], ['word_repetitions', 'word_id', 'words'],
        ['training_reviews', 'training_session_id', 'training_sessions'], ['training_reviews', 'word_id', 'words'],
        ['words', 'lesson_id', 'lessons'], ['lessons', 'stage_id', 'stages'], ['stages', 'course_id', 'courses'],
        ['word_translations', 'word_id', 'words'], ['learners', 'active_course_id', 'courses'],
        ['payments', 'payment_order_id', 'payment_orders'], ['payments', 'provider_transaction_id', 'provider_transactions'],
        ['payments', 'subscription_id', 'subscriptions'], ['payments', 'refunded_payment_id', 'payments'],
        ['provider_transactions', 'payment_order_id', 'payment_orders'], ['payment_orders', 'subscription_id', 'subscriptions'],
        ['exam_answers', 'exam_session_id', 'exam_sessions'], ['exam_answers', 'word_id', 'words'], ['exam_results', 'exam_session_id', 'exam_sessions'],
        ['exam_sessions', 'lesson_id', 'lessons'], ['exam_sessions', 'telegram_group_id', 'telegram_groups'],
        ['organization_members', 'organization_id', 'organizations'], ['organization_members', 'learner_id', 'learners'],
        ['organization_users', 'user_id', 'users'], ['group_members', 'telegram_group_id', 'telegram_groups'],
        ['assignments', 'lesson_id', 'lessons'], ['assignment_results', 'assignment_id', 'assignments'],
        ['league_members', 'league_group_id', 'league_groups'], ['friendships', 'friend_id', 'learners'],
        ['product_events', 'learner_id', 'learners'],
    ] as [$child, $column, $parent]) {
        expect(perfOrphans($child, $column, $parent))->toBe(0, "{$child}.{$column} -> {$parent}");
    }

    // Repetitions carry what the hard-words screens read; reviews fall into the last 30 days.
    expect(DB::table('word_repetitions')->where('is_hard', true)->where('lapses', '>', 0)->count())->toBeGreaterThan(0)
        ->and(DB::table('word_repetitions')->where('next_review_at', '<=', now())->count())->toBeGreaterThan(0)
        ->and(DB::table('training_reviews')->where('created_at', '<', now()->subDays(31))->count())->toBe(0)
        ->and(DB::table('training_sessions')->where('status', 'open')->count())->toBe(0);

    // Money: a payer has exactly one subscription, Payme/Click orders have a transaction, Stars do not; a refund points at its charge.
    expect(DB::table('subscriptions')->select('subscriber_type', 'subscriber_id')->groupBy('subscriber_type', 'subscriber_id')->havingRaw('count(*) > 1')->get())->toHaveCount(0)
        ->and(DB::table('provider_transactions')->where('provider', 'telegram_stars')->count())->toBe(0)
        ->and(DB::table('payments')->where('kind', 'refund')->whereNull('refunded_payment_id')->count())->toBe(0)
        ->and(DB::table('payments')->where('kind', 'renewal')->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('subscriber_type', 'organization')->where('tier', 'tutor')->count())->toBe(2);

    // Exams: ranks run 1..n inside a session, one answer per question and participant.
    $ranks = DB::table('exam_results')->orderBy('exam_session_id')->orderBy('rank')->get()->groupBy('exam_session_id');
    foreach ($ranks as $results) {
        expect($results->pluck('rank')->map(fn ($rank): int => (int) $rank)->all())->toBe(range(1, $results->count()));
    }
    foreach (DB::table('exam_results')->get() as $result) {
        expect(DB::table('exam_answers')->where('exam_session_id', $result->exam_session_id)->where('learner_id', $result->learner_id)->count())->toBe((int) $result->total_count);
    }

    // The admin and the owner of the first center can sign in to the panels.
    $admin = User::query()->where('email', PerfSeeder::ADMIN_EMAIL)->firstOrFail();
    expect($admin->hasRole('admin'))->toBeTrue()->and($admin->hasTwoFactorEnabled())->toBeTrue();

    $owner = User::query()->where('email', sprintf(PerfSeeder::OWNER_EMAIL, 1))->firstOrFail();
    expect($owner->hasTwoFactorEnabled())->toBeTrue()
        ->and(DB::table('organization_users')->where('user_id', $owner->id)->where('role', 'owner')->count())->toBe(1);

    // Learner 1 is a power user: many repetitions, a friend, a league seat, a center, a Pro subscription.
    $first = Learner::query()->where('telegram_user_id', PerfSeeder::TELEGRAM_USER_BASE + 1)->firstOrFail();
    expect(DB::table('word_repetitions')->where('learner_id', $first->id)->count())->toBeGreaterThan(30)
        ->and(DB::table('friendships')->where('learner_id', $first->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('league_members')->where('learner_id', $first->id)->count())->toBe(1)
        ->and(DB::table('organization_members')->where('learner_id', $first->id)->count())->toBe(1)
        ->and(DB::table('subscriptions')->where('subscriber_type', 'learner')->where('subscriber_id', $first->id)->count())->toBe(1);
});

it('seeds the smallest data set that still has every kind of row', function (): void {
    // The edges: one learner, one course, one word - no division by zero, no empty range, no clash of the windows.
    $this->artisan('perf:seed', [
        '--learners' => 1, '--words' => 1, '--courses' => 1, '--repetitions' => 1, '--reviews' => 1, '--payers' => 1,
        '--exams' => 2, '--organizations' => 1, '--members' => 1, '--groups' => 1, '--events' => 1,
    ])->assertSuccessful();

    expect(DB::table('learners')->count())->toBe(1)
        ->and(DB::table('words')->count())->toBe(1)
        ->and(DB::table('word_repetitions')->count())->toBe(1)
        ->and(DB::table('training_reviews')->count())->toBe(10) // one session of ten reviews
        ->and(DB::table('organization_members')->count())->toBe(1)
        ->and(DB::table('exam_sessions')->count())->toBe(2)
        ->and(DB::table('exam_results')->count())->toBe(2)
        ->and(DB::table('friendships')->count())->toBe(0)
        ->and(DB::table('league_members')->count())->toBe(1);

    // A moderate volume with the proportions of the full one: 50 learners, derived defaults, a small catalog.
    perfClear();
    $this->artisan('perf:seed', ['--learners' => 50, '--words' => 200, '--courses' => 2])->assertSuccessful();

    expect(DB::table('learners')->count())->toBe(50)
        ->and(DB::table('product_events')->count())->toBe(150)
        ->and(DB::table('training_reviews')->count())->toBe(150)
        ->and(DB::table('payment_orders')->where('status', 'paid')->count())->toBe(3 + 1) // 6% of 50 payers + 1 center
        ->and(DB::table('exam_sessions')->count())->toBe(2);
});

it('moves the id sequences past the seeded rows, so ordinary writes still work afterwards', function (): void {
    $this->artisan('perf:seed', perfSmall())->assertSuccessful();

    // On PostgreSQL a sequence left behind would answer "duplicate key" here.
    expect(Learner::factory()->create()->exists)->toBeTrue()
        ->and(Course::factory()->create()->exists)->toBeTrue()
        ->and(User::factory()->create()->exists)->toBeTrue()
        ->and(Organization::factory()->create()->exists)->toBeTrue()
        ->and(TelegramGroup::factory()->create()->exists)->toBeTrue()
        ->and(Subscription::factory()->create()->exists)->toBeTrue()
        ->and(PaymentOrder::factory()->payme()->create()->exists)->toBeTrue()
        ->and(ProviderTransaction::factory()->create()->exists)->toBeTrue()
        ->and(Payment::factory()->create()->exists)->toBeTrue();
});

it('gives the same rows for the same seed and other rows for another one', function (): void {
    $this->travelTo('2026-11-02 12:00:00');

    $this->artisan('perf:seed', perfSmall())->assertSuccessful();
    $first = perfDigest();

    perfClear();
    expect(DB::table('learners')->count())->toBe(0);

    $this->artisan('perf:seed', perfSmall())->assertSuccessful();
    expect(perfDigest())->toBe($first);

    perfClear();
    $this->artisan('perf:seed', perfSmall(['--seed' => 6]))->assertSuccessful();
    expect(perfDigest())->not->toBe($first);
});

it('refuses a second run on a database that already holds the data set', function (): void {
    $this->artisan('perf:seed', perfSmall())->assertSuccessful();
    $before = DB::table('learners')->count();

    $this->artisan('perf:seed', perfSmall())
        ->expectsOutputToContain('already holds a perf:seed data set')
        ->assertFailed();

    expect(DB::table('learners')->count())->toBe($before);
});

it('leaves nothing behind when the run fails half way', function (): void {
    // A learner with a seeded telegram id: the learners batch collides on the unique index, after the catalog went in.
    Learner::factory()->create(['telegram_user_id' => PerfSeeder::TELEGRAM_USER_BASE + 3]);

    expect(fn () => app(PerfSeeder::class)->run(PerfSeedOptions::make(learners: 10, words: 20, courses: 2, payers: 1, exams: 1, organizations: 1, membersPerOrganization: 3, groups: 1, events: 5, reviews: 5)))
        ->toThrow(QueryException::class, 'telegram_user_id');

    expect(DB::table('courses')->count())->toBe(0)
        ->and(DB::table('words')->count())->toBe(0)
        ->and(DB::table('learners')->count())->toBe(1)
        ->and(User::query()->count())->toBe(0);
});

it('sizes a statement by its bound parameters, not by its rows', function (): void {
    Schema::create('perf_probe', function ($table): void {
        for ($i = 1; $i <= 40; $i++) {
            $table->integer("c{$i}")->nullable();
        }
    });

    $statements = QueryLog::during(function (): void {
        $writer = new PerfBatchWriter('perf_probe');
        for ($n = 0; $n < 3000; $n++) {
            $writer->add(array_combine(array_map(fn (int $i): string => "c{$i}", range(1, 40)), array_fill(0, 40, $n)));
        }
        expect($writer->close())->toBe(3000);
    });

    // 30 000 / 40 columns = 750 rows per statement.
    expect($statements)->toHaveCount(4)
        ->and(substr_count($statements[0], '?'))->toBe(30_000)
        ->and(DB::table('perf_probe')->count())->toBe(3000);
});
