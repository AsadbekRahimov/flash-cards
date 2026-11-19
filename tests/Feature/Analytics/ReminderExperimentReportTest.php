<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ReminderExperimentReport;
use App\Domain\Analytics\Support\WilsonInterval;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TrainingReview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\QueryLog;

/*
 * The report of the question vs the plain text in the reminder (audit 2026-11-18; docs/ADMIN_GUIDE.md «Эксперимент с
 * напоминанием-вопросом»): the first exposure of a learner only, every share over all assigned, the windows of 24 h / 7 d.
 * The numbers are worked out by hand; the same file runs on sqlite and on PostgreSQL.
 */

uses(RefreshDatabase::class);

const RX_NOW = '2026-11-30 12:00:00';

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse(RX_NOW, 'UTC')));

/** @param array<string, mixed> $props */
function rx_event(?Learner $learner, ProductEventName $name, string $atUtc, array $props = []): void
{
    ProductEvent::query()->create(['name' => $name->value, 'learner_id' => $learner?->id, 'props' => $props, 'occurred_at' => $atUtc]);
}

function rx_sent(Learner $learner, string $atUtc, string $variant, ?bool $built = null): void
{
    rx_event($learner, ProductEventName::ReminderSent, $atUtc, [
        'kind' => 'review',
        'due_count' => 3,
        'variant' => $variant,
        ...($built === true ? ['quiz' => true] : []),
        ...($built === null ? [] : ['quiz_built' => $built]),
    ]);
}

function rx_review(Learner $learner, string $atUtc): void
{
    TrainingReview::factory()->create(['learner_id' => $learner->id, 'created_at' => $atUtc]);
}

/**
 * Quiz arm: Q1 the whole chain; Q2 no question built, reviews only outside the 24 h, reminders off on day 5; Q3 a blocked
 * first send; Q4 first reminded before the window. Plain arm: P1 session from the reminder, a 403 on day 4; P2 a review
 * 23:59:59 after, a session that is not from a reminder, the bot blocked on day 4 (seen by another message); P3 nothing;
 * P4 first reminded after the window.
 *
 * @return array<string, Learner>
 */
function rx_seed(): array
{
    $l = [];
    foreach (['q1', 'q2', 'q3', 'q4', 'p1', 'p2', 'p3', 'p4'] as $key) {
        $l[$key] = Learner::factory()->create();
    }

    rx_sent($l['q1'], '2026-11-20 10:00:00', 'quiz', true);
    rx_event($l['q1'], ProductEventName::ReminderQuizAnswered, '2026-11-20 10:05:00', ['correct' => true]);
    rx_event($l['q1'], ProductEventName::TrainingSessionOpened, '2026-11-20 10:06:00', ['source' => 'reminder_quiz']);
    rx_review($l['q1'], '2026-11-20 10:07:00');
    rx_sent($l['q1'], '2026-11-21 10:00:00', 'quiz', true); // a later send: does not count again

    rx_sent($l['q2'], '2026-11-20 11:00:00', 'quiz', false);
    rx_review($l['q2'], '2026-11-20 10:59:59');
    rx_review($l['q2'], '2026-11-21 11:00:00'); // exactly 24 h: out
    rx_event($l['q2'], ProductEventName::RemindersDisabled, '2026-11-25 09:00:00', ['via' => 'settings']);

    $l['q3']->update(['dm_blocked_at' => '2026-11-21 08:59:59']); // set by the failed send itself: not "blocked after"
    rx_event($l['q3'], ProductEventName::ReminderFailed, '2026-11-21 09:00:00', ['reason' => 'bot_blocked', 'kind' => 'review', 'variant' => 'quiz']);

    rx_sent($l['q4'], '2026-11-19 23:59:59', 'quiz', true);
    rx_sent($l['q4'], '2026-11-21 10:00:00', 'quiz', true);
    rx_review($l['q4'], '2026-11-21 10:30:00');

    rx_event($l['p1'], ProductEventName::ReminderSent, '2026-11-01 10:00:00', ['kind' => 'review', 'due_count' => 3]); // before the experiment
    rx_sent($l['p1'], '2026-11-20 12:00:00', 'plain');
    rx_event($l['p1'], ProductEventName::TrainingSessionOpened, '2026-11-20 12:30:00', ['source' => 'reminder']);
    rx_review($l['p1'], '2026-11-20 13:00:00');
    rx_event($l['p1'], ProductEventName::ReminderFailed, '2026-11-24 12:00:00', ['reason' => 'bot_blocked', 'kind' => 'review', 'variant' => 'plain']);

    rx_sent($l['p2'], '2026-11-22 08:00:00', 'plain');
    rx_event($l['p2'], ProductEventName::TrainingSessionOpened, '2026-11-22 09:00:00', ['source' => 'self']);
    rx_review($l['p2'], '2026-11-23 07:59:59');
    $l['p2']->update(['dm_blocked_at' => '2026-11-26 10:00:00']);

    rx_sent($l['p3'], '2026-11-22 09:00:00', 'plain');
    rx_event($l['p3'], ProductEventName::RemindersDisabled, '2026-11-29 09:00:01', ['via' => 'settings']); // 7 d + 1 s: out

    rx_sent($l['p4'], '2026-11-23 00:00:00', 'plain');
    rx_review($l['p4'], '2026-11-23 01:00:00');

    // Events nobody is assigned by: no learner, no variant.
    rx_event(null, ProductEventName::ReminderSent, '2026-11-21 10:00:00', ['variant' => 'quiz']);

    return $l;
}

/** @return array<string, mixed> */
function rx_report(string $from = '2026-11-20 00:00:00', string $to = '2026-11-23 00:00:00', string $now = RX_NOW): array
{
    return app(ReminderExperimentReport::class)->build(
        CarbonImmutable::parse($from, 'UTC'),
        CarbonImmutable::parse($to, 'UTC'),
        CarbonImmutable::parse($now, 'UTC'),
    );
}

/** @return array{count: int, of: int, percent: float|null, low: float|null, high: float|null} */
function rx_share(int $count, int $of): array
{
    $interval = WilsonInterval::of($count, $of);

    return ['count' => $count, 'of' => $of, 'percent' => round($count / $of * 100, 1), 'low' => $interval['low'] ?? null, 'high' => $interval['high'] ?? null];
}

it('counts the first exposure of each learner per arm, over all assigned', function (): void {
    rx_seed();

    $report = rx_report();

    expect($report['from'])->toBe('2026-11-20 00:00:00')
        ->and($report['to'])->toBe('2026-11-23 00:00:00')
        ->and($report['immature_24h'])->toBe(0)
        ->and($report['immature_7d'])->toBe(0)
        ->and(array_keys($report['arms']))->toBe(['quiz', 'plain'])
        ->and($report['arms']['quiz'])->toBe(['assigned' => 3, 'metrics' => [
            'delivered' => rx_share(2, 3),
            'quiz_built' => rx_share(1, 3),
            'answered_in_chat' => rx_share(1, 3),
            'session_from_reminder_24h' => rx_share(1, 3),
            'reviewed_24h' => rx_share(1, 3),
            'reminders_disabled_7d' => rx_share(1, 3),
            'bot_blocked_7d' => rx_share(0, 3),
            'disabled_or_blocked_7d' => rx_share(1, 3),
        ]])
        ->and($report['arms']['plain'])->toBe(['assigned' => 3, 'metrics' => [
            'delivered' => rx_share(3, 3),
            'quiz_built' => null,
            'answered_in_chat' => null,
            'session_from_reminder_24h' => rx_share(1, 3),
            'reviewed_24h' => rx_share(2, 3),
            'reminders_disabled_7d' => rx_share(0, 3),
            'bot_blocked_7d' => rx_share(2, 3),
            'disabled_or_blocked_7d' => rx_share(2, 3),
        ]]);
});

it('gives empty arms for a window without exposures', function (): void {
    rx_seed();

    $report = rx_report('2026-10-01 00:00:00', '2026-10-02 00:00:00');

    expect($report['arms']['quiz']['assigned'])->toBe(0)
        ->and($report['arms']['quiz']['metrics']['reviewed_24h'])->toBe(['count' => 0, 'of' => 0, 'percent' => null, 'low' => null, 'high' => null])
        ->and($report['arms']['plain']['metrics']['answered_in_chat'])->toBeNull();
});

it('counts the learners whose windows have not ended yet', function (): void {
    rx_seed();

    // 2026-11-22 20:00: P2 (08:00) and P3 (09:00) still inside 24 h; everybody inside 7 d.
    $report = rx_report(now: '2026-11-22 20:00:00');

    expect($report['immature_24h'])->toBe(2)->and($report['immature_7d'])->toBe(6);
});

it('runs a constant number of queries for a chunk of learners', function (): void {
    rx_seed();

    $queries = QueryLog::during(fn () => rx_report());

    // The cohort, then per chunk: answered, sessions, reviews, reminders off, 403s, dm_blocked_at.
    expect(count($queries))->toBe(7);
});

it('prints the shares with the interval and warns about windows not over yet', function (): void {
    rx_seed();
    config(['learning.reminders.quiz.experiment.starts_at' => '2026-11-20']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'UTC'));

    $exit = Artisan::call('analytics:reminder-experiment', ['--to' => '2026-11-22']);
    $output = Artisan::output();

    $one = WilsonInterval::of(1, 3);
    expect($exit)->toBe(0)
        ->and($output)->toContain('[2026-11-20 00:00:00, 2026-11-23 00:00:00)')
        ->and($output)->toContain('PRIMARY: reviewed within 24 h')
        ->and($output)->toContain(sprintf('1/3  33.3 %%  [%.1f–%.1f]', $one['low'] ?? 0, $one['high'] ?? 0))
        ->and($output)->toContain('—')
        ->and($output)->toContain('Windows not over yet: 0 learner(s) for 24 h, 6 for 7 d');
});

it('refuses a window without a start, an inverted one and an unreadable date', function (): void {
    expect(Artisan::call('analytics:reminder-experiment'))->toBe(2)
        ->and(Artisan::output())->toContain('No --from')
        ->and(Artisan::call('analytics:reminder-experiment', ['--from' => '2026-11-22', '--to' => '2026-11-20']))->toBe(2)
        ->and(Artisan::call('analytics:reminder-experiment', ['--from' => 'yesterday-ish']))->toBe(2);
});
