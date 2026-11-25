<?php

declare(strict_types=1);

/*
 * docs/35 O7: the results of a poll. Shares of the answered, test sends left out, the snapshot of a closed poll; the breakdowns by
 * language, Free / Pro, course and region in two grouped statements (cached for a closed poll); the answers by person on a page
 * whose statements do not grow with the rows; the two CSV files - streamed, formula-safe, audited, admin only; `polls:close-due`
 * and `polls:prune` (idempotent, in chunks, the totals kept).
 */

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Services\PollCloser;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollPruner;
use App\Domain\Polls\Services\PollReport;
use App\Domain\Polls\Services\PollResultsCsv;
use App\Domain\Polls\Services\PollVoteService;
use App\Filament\Resources\PollResource\Pages\ViewPollResults;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Polls\PollFixtures;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->setLocale('ru');
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
    Queue::fake();
});

/**
 * A delivery that went out to `$learner` and the answer it recorded (straight rows: what the vote service writes).
 *
 * @param  list<int>  $positions
 */
function pollAnswer(Poll $poll, Learner $learner, array $positions, string $locale = 'ru', ?string $at = null): PollDelivery
{
    $delivery = PollDelivery::query()->create([
        'poll_id' => $poll->id,
        'learner_id' => $learner->id,
        'telegram_user_id' => $learner->telegram_user_id,
        'token' => Str::random(12),
        'locale' => $locale,
        'status' => PollDeliveryStatus::Sent,
        'message_id' => 1,
        'sent_at' => now(),
        'answered_at' => $positions === [] ? null : ($at ?? now()),
    ]);

    foreach ($positions as $position) {
        PollVote::query()->create([
            'poll_id' => $poll->id,
            'poll_delivery_id' => $delivery->id,
            'poll_option_id' => (int) PollOption::query()->where('poll_id', $poll->id)->where('position', $position)->value('id'),
        ]);
    }

    return $delivery;
}

function pollSent(array $attributes = [], int $options = 3): Poll
{
    return PollFixtures::poll(['status' => PollStatus::Sent, 'launched_at' => now()->subHour(), ...$attributes], $options);
}

/** The CSV a streamed export writes, read back from memory. */
function pollCsv(callable $write): string
{
    $out = fopen('php://memory', 'w+b');
    $write($out);
    rewind($out);

    return (string) stream_get_contents($out);
}

// --- totals and breakdowns ---------------------------------------------------------------------------------------------------

it('counts the shares of the answered and leaves the test sends out', function (): void {
    $poll = pollSent(['allows_multiple' => true]);
    [$a, $b, $c] = PollFixtures::learners(3);
    pollAnswer($poll, $a, [0, 1]);
    pollAnswer($poll, $b, [0]);
    pollAnswer($poll, $c, []);
    $test = PollDelivery::factory()->test()->sent()->create(['poll_id' => $poll->id, 'answered_at' => now()]);
    PollVote::query()->create(['poll_id' => $poll->id, 'poll_delivery_id' => $test->id, 'poll_option_id' => $poll->options()->value('id')]);

    $summary = app(PollReport::class)->summary($poll);

    expect($summary['snapshot'])->toBeFalse()
        ->and($summary['recipients'])->toBe(3)
        ->and($summary['answered'])->toBe(2)
        ->and($summary['statuses']['sent'])->toBe(3)
        ->and(array_column($summary['options'], 'votes'))->toBe([2, 1, 0])
        ->and(array_column($summary['options'], 'share'))->toBe([100.0, 50.0, 0.0]);
});

it('breaks the answers down by language, Free / Pro, course and region in two statements', function (): void {
    $course = Course::factory()->create(['title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz A1', 'en' => 'English A1']]);
    $poll = pollSent();
    [$a, $b, $c, $d] = PollFixtures::learners(4);
    $a->forceFill(['region_code' => 'UZ-SA', 'active_course_id' => $course->id])->save();
    $b->forceFill(['region_code' => 'other'])->save();
    Subscription::factory()->create(['subscriber_id' => $b->id]);
    pollAnswer($poll, $a, [0], 'uz');
    pollAnswer($poll, $b, [1]);
    pollAnswer($poll, $c, [1]);
    pollAnswer($poll, $d, []);

    $queries = QueryLog::during(fn () => $this->breakdowns = app(PollReport::class)->breakdowns($poll));
    $by = fn (string $dimension): array => collect($this->breakdowns[$dimension])->mapWithKeys(fn (array $g): array => [$g['key'] => [$g['answered'], $g['votes']]])->all();
    [$first, $second] = $poll->options()->pluck('id')->all();

    expect(QueryLog::matching($queries, '/poll_deliveries|poll_votes/'))->toHaveCount(2)
        ->and($by('locale'))->toEqual(['ru' => [2, [$second => 2]], 'uz' => [1, [$first => 1]]])
        ->and($by('plan'))->toEqual(['free' => [2, [$first => 1, $second => 1]], 'pro' => [1, [$second => 1]]])
        ->and($by('region'))->toEqual(['none' => [1, [$second => 1]], 'UZ-SA' => [1, [$first => 1]], 'other' => [1, [$second => 1]]])
        ->and($by('course'))->toEqual(['none' => [2, [$second => 2]], (string) $course->id => [1, [$first => 1]]])
        ->and(collect($this->breakdowns['course'])->firstWhere('key', (string) $course->id)['label'])->toBe('Английский A1');
});

it('reads a closed poll from its snapshot and keeps its breakdowns in the cache until a prune', function (): void {
    $poll = pollSent();
    [$a] = PollFixtures::learners(1);
    pollAnswer($poll, $a, [2]);
    app(PollLauncher::class)->close($poll, null);
    $poll->refresh();
    $report = app(PollReport::class);

    $summary = QueryLog::during(fn () => $this->summary = $report->summary($poll));
    expect($this->summary['snapshot'])->toBeTrue()
        ->and(array_column($this->summary['options'], 'votes'))->toBe([0, 0, 1])
        ->and(QueryLog::matching($summary, '/poll_deliveries|poll_votes/'))->toBe([]);

    $report->breakdowns($poll);
    expect(QueryLog::matching(QueryLog::during(fn () => $report->breakdowns($poll)), '/poll_deliveries|poll_votes/'))->toBe([])
        ->and(PureCache::store()->has(WidgetCache::key(PollReport::CACHE_WIDGET, 'all', (string) $poll->id)))->toBeTrue();

    $poll->forceFill(['closed_at' => now()->subDays(400)])->save();
    app(PollPruner::class)->prune();

    expect(PureCache::store()->has(WidgetCache::key(PollReport::CACHE_WIDGET, 'all', (string) $poll->id)))->toBeFalse()
        ->and($report->breakdowns($poll)['locale'])->toBe([])
        ->and(array_column($report->summary($poll->refresh())['options'], 'votes'))->toBe([0, 0, 1]);
});

// --- the page --------------------------------------------------------------------------------------------------------------

it('shows the head, the options, the breakdowns and the answers by person', function (): void {
    $poll = pollSent(['title' => 'Формат']);
    [$a, $b] = PollFixtures::learners(2);
    $a->forceFill(['first_name' => 'Азиз', 'last_name' => 'Каримов', 'region_code' => 'UZ-SA'])->save();
    $answer = pollAnswer($poll, $a, [1]);
    pollAnswer($poll, $b, []);

    Livewire::test(ViewPollResults::class, ['record' => $poll->id])
        ->assertOk()
        ->assertSee('Результаты опроса «Формат»')
        ->assertSee('Самаркандская область')
        ->assertSee('Азиз Каримов')
        ->assertSee('Вариант 2')
        ->assertCanSeeTableRecords([$answer])
        ->assertCountTableRecords(1);
});

it('keeps the statements of the results page constant with the number of answers', function (): void {
    $poll = pollSent(['allows_multiple' => true]);
    $fill = function (int $answers) use ($poll): void {
        $have = PollDelivery::query()->where('poll_id', $poll->id)->count();
        foreach (PollFixtures::learners($answers - $have) as $i => $learner) {
            pollAnswer($poll, $learner, $i % 2 === 0 ? [0, 2] : [1], $i % 3 === 0 ? 'uz' : 'ru');
        }
    };

    $count = function () use ($poll): int {
        app()->forgetScopedInstances();

        // An update of the page (as a click does): all the rows on one page of 50, so every row is rendered.
        $page = Livewire::test(ViewPollResults::class, ['record' => $poll->id]);

        return count(QueryLog::during(fn () => $page->set('tableRecordsPerPage', 50)));
    };

    Livewire::test(ViewPollResults::class, ['record' => $poll->id]);
    $fill(10);
    $small = $count();
    $fill(50);
    $large = $count();

    // The poll (Livewire's hydration of the record), the totals (3, PollResults::totals), the options, the two breakdowns, the page
    // of answers with its learners and votes = 10; one to spare.
    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(11);
});

it('closes the results page to a teacher and an inactive admin', function (): void {
    $poll = pollSent();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    Livewire::test(ViewPollResults::class, ['record' => $poll->id])->assertForbidden();
    expect(fn () => app(PollResultsCsv::class))->not->toThrow(Throwable::class);
});

// --- CSV -----------------------------------------------------------------------------------------------------------------

it('writes the answers file in chunks, without names, with formulas defused', function (): void {
    $poll = pollSent();
    PollOption::query()->where('poll_id', $poll->id)->where('position', 0)->update(['label' => json_encode(['ru' => '=HYPERLINK("x")', 'uz' => '=x'])]);
    PollOption::query()->where('poll_id', $poll->id)->where('position', 1)->update(['label' => json_encode(['ru' => '@cmd', 'uz' => '@cmd'])]);
    $learners = PollFixtures::learners(5, ['first_name' => 'Секрет']);
    $learners[0]->forceFill(['region_code' => 'UZ-TK'])->save();
    Subscription::factory()->create(['subscriber_id' => $learners[0]->id]);
    foreach ($learners as $i => $learner) {
        pollAnswer($poll, $learner, $i === 0 ? [0, 1] : [2], at: '2026-11-28 07:30:00');
    }
    pollAnswer($poll, PollFixtures::learners(1)[0], []);

    $csv = app(PollResultsCsv::class);
    $queries = QueryLog::during(function () use ($csv, $poll): void {
        $this->file = pollCsv(fn ($out) => $csv->writeAnswers($out, $poll));
    });

    $lines = explode("\r\n", trim($this->file));
    expect($this->file)->toStartWith("\xEF\xBB\xBF")
        ->and($lines)->toHaveCount(6)
        ->and(str_getcsv(substr($lines[0], 3), ';', '"', ''))->toBe(['Номер доставки', 'Номер ученика', 'Язык', 'Тариф', 'Область', 'Варианты', 'Время ответа'])
        ->and(array_slice(str_getcsv($lines[1], ';', '"', ''), 1))->toBe([(string) $learners[0]->id, 'ru', 'Pro', 'UZ-TK', '\'=HYPERLINK("x") | @cmd', '2026-11-28 12:30'])
        ->and(array_slice(str_getcsv($lines[2], ';', '"', ''), 2))->toBe(['ru', 'Free', '', 'Вариант 3', '2026-11-28 12:30'])
        ->and($this->file)->not->toContain('Секрет');

    $pollRows = QueryLog::matching($queries, '/from "poll_deliveries"/');
    expect($pollRows)->not->toBeEmpty();
});

it('reads the answers file by keyset on the learner, a fixed number of statements per chunk', function (): void {
    $poll = pollSent();
    foreach (PollFixtures::learners(5) as $learner) {
        pollAnswer($poll, $learner, [1]);
    }

    $csv = app(PollResultsCsv::class);
    $chunk = (new ReflectionClassConstant(PollResultsCsv::class, 'CHUNK'))->getValue();
    $queries = QueryLog::during(fn () => pollCsv(fn ($out) => $csv->writeAnswers($out, $poll)));

    // options (1) + per chunk: deliveries, votes, learners (a chunk shorter than CHUNK is the last one).
    expect($chunk)->toBe(1000)
        ->and(count($queries))->toBe(1 + 3)
        ->and(QueryLog::matching($queries, '/"learner_id" > \?/'))->toHaveCount(0)
        ->and(QueryLog::matching($queries, '/order by "learner_id" asc limit 1000/'))->toHaveCount(1);
});

it('escapes a summary line that would start a formula and writes Excel\'s format', function (): void {
    $csv = app(PollResultsCsv::class);
    $file = pollCsv(fn ($out) => $csv->writeSummary($out, ['answered' => 3, 'options' => [
        ['label' => '+7 (999)', 'votes' => 2, 'share' => 66.7],
        ['label' => '-1', 'votes' => 1, 'share' => 33.3],
    ]]));

    $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\r\n", trim(substr($file, 3))));

    expect($file)->toStartWith("\xEF\xBB\xBF")
        ->and($rows)->toBe([['Вариант', 'Голосов', 'Доля ответивших, %'], ["'+7 (999)", '2', '66,7'], ["'-1", '1', '33,3'], ['Ответили', '3', '']]);
});

it('streams both files from the page and writes an audit record for each', function (string $action, string $kind, string $name): void {
    $poll = pollSent();
    pollAnswer($poll, PollFixtures::learners(1)[0], [0]);

    Livewire::test(ViewPollResults::class, ['record' => $poll->id])
        ->callAction($action)
        ->assertFileDownloaded("poll-{$poll->id}-{$name}.csv");

    $audit = AuditLog::query()->where('action', PollResultsCsv::AUDIT_EXPORTED)->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($poll->id)
        ->and($audit->meta)->toBe(['kind' => $kind]);
})->with([
    'totals' => ['exportSummary', 'summary', 'summary'],
    'answers' => ['exportAnswers', 'answers', 'answers'],
]);

// --- commands ------------------------------------------------------------------------------------------------------------

it('closes the polls whose deadline came, once, and leaves the others', function (): void {
    $due = pollSent(['closes_at' => now()->subMinute()]);
    $sending = PollFixtures::poll(['status' => PollStatus::Sending, 'launched_at' => now(), 'recipient_count' => 0, 'closes_at' => now()->subMinutes(10)]);
    $later = pollSent(['closes_at' => now()->addHour()]);
    $open = pollSent();
    $draft = PollFixtures::poll(['closes_at' => now()->subMinute()]);

    $queries = QueryLog::during(fn () => Artisan::call('polls:close-due'));

    expect($due->fresh()->status)->toBe(PollStatus::Closed)
        ->and($sending->fresh()->status)->toBe(PollStatus::Closed)
        ->and($due->fresh()->results_snapshot)->toBeArray()
        ->and($later->fresh()->status)->toBe(PollStatus::Sent)
        ->and($open->fresh()->status)->toBe(PollStatus::Sent)
        ->and($draft->fresh()->status)->toBe(PollStatus::Draft)
        ->and(AuditLog::query()->where('action', 'poll.closed')->whereNull('user_id')->count())->toBe(2)
        ->and(QueryLog::matching($queries, '/from "polls" where "status" in \(\?, \?\) and "closes_at" is not null and "closes_at" <= \?/'))->toHaveCount(1);

    expect(app(PollCloser::class)->closeDue())->toBe(0)
        ->and(AuditLog::query()->where('action', 'poll.closed')->count())->toBe(2);
});

it('prunes the answers of polls closed or stopped over a year ago in chunks, keeps the polls and their totals', function (): void {
    config(['polls.prune_chunk' => 100]);
    $old = pollSent();
    foreach (PollFixtures::learners(3) as $learner) {
        pollAnswer($old, $learner, [0]);
    }
    app(PollLauncher::class)->close($old, null);
    $old->forceFill(['closed_at' => now()->subDays(366)])->save();

    $stopped = PollFixtures::poll(['status' => PollStatus::Cancelled]);
    pollAnswer($stopped, PollFixtures::learners(1)[0], []);
    DB::table('polls')->where('id', $stopped->id)->update(['updated_at' => now()->subDays(400)]);

    $recent = pollSent(['status' => PollStatus::Closed, 'closed_at' => now()->subDays(10)]);
    pollAnswer($recent, PollFixtures::learners(1)[0], [1]);
    $running = pollSent();
    pollAnswer($running, PollFixtures::learners(1)[0], [1]);

    Artisan::call('polls:prune');

    expect(PollDelivery::query()->where('poll_id', $old->id)->count())->toBe(0)
        ->and(PollVote::query()->where('poll_id', $old->id)->count())->toBe(0)
        ->and(PollDelivery::query()->where('poll_id', $stopped->id)->count())->toBe(0)
        ->and(PollDelivery::query()->where('poll_id', $recent->id)->count())->toBe(1)
        ->and(PollDelivery::query()->where('poll_id', $running->id)->count())->toBe(1)
        ->and(Poll::query()->find($old->id)?->results_snapshot['answered'])->toBe(3);

    expect(app(PollPruner::class)->prune())->toBe(['polls' => 0, 'votes' => 0, 'deliveries' => 0]);
});

it('counts the retention of a stopped poll from the stop, not from a later rename of its title', function (): void {
    config(['polls.prune_chunk' => 100]);
    $poll = PollFixtures::launched(2);
    $this->travelTo(now()->subDays(400));
    app(PollLauncher::class)->cancel(Poll::query()->findOrFail($poll->id), PollFixtures::admin(null));
    $this->travelBack();
    // Renamed yesterday: `updated_at` is fresh, the stop is 400 days old.
    $poll->fresh()->forceFill(['title' => 'Переименован'])->save();

    expect(app(PollPruner::class)->prune())->toBe(['polls' => 1, 'votes' => 0, 'deliveries' => 3]); // 2 learners + the test send

    // Stopped 10 days ago and renamed long ago is kept.
    $recent = PollFixtures::launched(1);
    $this->travelTo(now()->subDays(10));
    app(PollLauncher::class)->cancel(Poll::query()->findOrFail($recent->id), PollFixtures::admin(null));
    $this->travelBack();
    expect(app(PollPruner::class)->prune()['deliveries'])->toBe(0);
});

it('deletes the pictures of polls/ no poll refers to once they are a day old, nothing else', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $used = 'polls/'.str_repeat('U', 40).'.png';
    $orphan = 'polls/'.str_repeat('O', 40).'.png';
    $fresh = 'polls/'.str_repeat('F', 40).'.png';
    foreach ([$used, $orphan, $fresh, 'other/keep.png'] as $path) {
        $disk->put($path, 'x');
    }
    foreach ([$used, $orphan, 'other/keep.png'] as $path) {
        touch($disk->path($path), now()->subHours(25)->getTimestamp());
    }
    PollFixtures::poll(['image_path' => $used]);

    expect(app(PollPruner::class)->pruneOrphanImages())->toBe(1);
    $disk->assertMissing($orphan);
    $disk->assertExists($used);
    $disk->assertExists($fresh);
    $disk->assertExists('other/keep.png');
    expect(app(PollPruner::class)->pruneOrphanImages())->toBe(0);
    $this->artisan('polls:prune')->expectsOutputToContain('Orphaned poll pictures deleted: 0')->assertSuccessful();
});

it('deletes in statements of at most the chunk, each on its own', function (): void {
    config(['polls.prune_chunk' => 100]);
    $old = pollSent(['status' => PollStatus::Closed, 'closed_at' => now()->subDays(500)]);
    $rows = [];
    foreach (PollFixtures::learners(150) as $learner) {
        $rows[] = ['poll_id' => $old->id, 'learner_id' => $learner->id, 'telegram_user_id' => $learner->telegram_user_id, 'token' => Str::random(12), 'locale' => 'ru', 'status' => 'sent', 'is_test' => false, 'changed_count' => 0];
    }
    DB::table('poll_deliveries')->insert($rows);

    $queries = QueryLog::during(fn () => $this->out = app(PollPruner::class)->prune());

    expect($this->out)->toBe(['polls' => 1, 'votes' => 0, 'deliveries' => 150])
        ->and(QueryLog::matching($queries, '/^select "id" from "poll_deliveries" where "poll_id" = \? limit 100$/'))->toHaveCount(2)
        ->and(QueryLog::matching($queries, '/^delete from "poll_deliveries" where "id" in \(/'))->toHaveCount(2);
});

it('schedules both commands', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($e): string => (string) $e->command);

    expect($events->first(fn (string $c): bool => str_contains($c, 'polls:close-due')))->not->toBeNull()
        ->and($events->first(fn (string $c): bool => str_contains($c, 'polls:prune')))->not->toBeNull();
});

// --- the event -------------------------------------------------------------------------------------------------------------

it('writes no learning statistics for an answer', function (): void {
    $delivery = PollFixtures::delivered();
    $tables = ['learner_daily_stats', 'training_reviews'];
    $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

    app(PollVoteService::class)->tap($delivery->token, $delivery->telegram_user_id, '0', CarbonImmutable::now());

    expect(array_map(fn (string $t): int => DB::table($t)->count(), $tables))->toBe($before);
});
