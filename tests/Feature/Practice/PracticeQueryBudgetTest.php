<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\BattlePollView;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Models\Battle;
use App\Models\BattleParticipant;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §13: the budgets of SQL statements of the hot paths of the solo practice, and the sizes of the answers. `TwaAuth` reads
 * the learner (1 statement), it is included. The battle (poll, answer, invite, sweep) adds its budgets with 18.6.
 *
 * The test handlers read the material the way a real handler does (FakeEchoMode::$readsMaterial): the introduced words and
 * their translations, the published tasks and their versions - so the budget of the hub is the budget of the real thing.
 */

uses(RefreshDatabase::class);

const BUDGET_HUB = 9;
const BUDGET_OPEN = 12;
const BUDGET_SHOW = 3;
const BUDGET_ANSWER = 6;
/** The answer that ENDS a session carries the summary, and the summary asks whether the mistakes can be repeated: one statement more (docs/16 §13). */
const BUDGET_ANSWER_LAST = 7;
const BUDGET_AUDIO = 2;

/** A response of a session must not exceed 8 KB (docs/16 §13); the snapshot is held to `Snapshot::MAX_BYTES` (UTF-8, SnapshotSizeTest). */
const BUDGET_SESSION_BYTES = 8 * 1024;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake(FakeEchoMode::AUDIO_DISK);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A lesson with six words the learner has been introduced to and four published tasks of each of the three types, aimed at
 * them. The pairs are the REAL handler (it reads the words like any learner's session does: a clash between two translations
 * would only change the rounds, so the six translations are fixed); the other three modes are test handlers that read this material.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson}
 */
function budgetWorld(string $locale = 'ru'): array
{
    [$course, $lesson] = PracticeKit::course("english-{$locale}");
    $learner = Learner::factory()->create(['interface_locale' => $locale]);
    $words = PracticeKit::words($lesson, 6, $learner, state: 'review');
    foreach ($words as $i => $word) {
        $word->update(['translation' => 'перевод '.($i + 1)]);
    }

    foreach ($words as $i => $word) {
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($word)->create();
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting([$word, $words[($i + 1) % 6]])->create();
        PracticeItem::factory()->dictation()->published()->audioReady()->forLesson($lesson)->targeting($word)->create();
    }

    foreach (PracticeMode::cases() as $mode) {
        if ($mode !== PracticeMode::Pairs) {
            PracticeKit::fake($mode, candidates: 9, spares: 2)->readsMaterial = true;
        }
    }

    return [$learner, $course, $lesson];
}

function budgetHub(Learner $learner, Course $course, ?Lesson $lesson = null): TestResponse
{
    $query = $lesson === null ? '' : "&lesson_id={$lesson->id}";

    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}{$query}");
}

/** @param array<string, mixed> $body */
function budgetOpen(Learner $learner, Course $course, string $mode, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => $mode, 'course' => $course->slug, ...$body]);
}

/** @param array<string, mixed> $answer */
function budgetAnswer(Learner $learner, int $sessionId, string $elementId, array $answer): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer,
    ]);
}

/** @param list<string> $queries */
function budgetReads(array $queries, string $table): int
{
    return count(QueryLog::matching($queries, '/^select .* from ["`]'.$table.'["`]/i'));
}

it('reads the hub in at most 9 statements, whatever the language, and asks for the words and the tasks once for all four modes', function (): void {
    foreach (['ru', 'uz'] as $locale) {
        [$learner, $course, $lesson] = budgetWorld($locale);

        $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('modes.0.available', 6)->assertJsonPath('modes.0.state', 'available'));
        // The same for a whole course.
        $courseQueries = QueryLog::during(fn () => budgetHub($learner, $course)->assertOk());

        expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, "hub of a lesson ({$locale}): ".implode("\n", $queries))
            ->and(count($courseQueries))->toBeLessThanOrEqual(BUDGET_HUB, "hub of a course ({$locale}): ".implode("\n", $courseQueries))
            ->and(budgetReads($queries, 'words'))->toBe(1)
            ->and(budgetReads($queries, 'word_translations'))->toBe(1)
            ->and(budgetReads($queries, 'practice_items'))->toBe(1)
            ->and(budgetReads($queries, 'practice_item_versions'))->toBe(1)
            ->and(budgetReads($queries, 'practice_sessions'))->toBe(1)
            ->and(budgetReads($queries, 'battles'))->toBe(1);
    }
});

it('reads the hub within the budget with an unfinished session, and for a Pro learner at PRACTICE_PLAN=pro (the entitlement is cached)', function (): void {
    [$learner, $course, $lesson] = budgetWorld();
    budgetOpen($learner, $course, 'collocation', ['lesson_id' => $lesson->id])->assertCreated();

    $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('active_session.mode', 'collocation'));
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, implode("\n", $queries));

    config(['practice.plan' => 'pro']);
    PracticeKit::pro($learner);
    budgetHub($learner, $course, $lesson)->assertOk(); // warms the cache of the entitlement, the way the previous request would
    $queries = QueryLog::during(fn () => budgetHub($learner, $course, $lesson)->assertOk()->assertJsonPath('modes.1.state', 'available'));

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_HUB, implode("\n", $queries));
});

it('opens a session in at most 12 statements: a plain one, and one that replaces the unfinished one', function (): void {
    [$learner, $course, $lesson] = budgetWorld();

    $plain = QueryLog::during(fn () => budgetOpen($learner, $course, 'sentence', ['lesson_id' => $lesson->id])->assertCreated());
    $replacing = QueryLog::during(fn () => budgetOpen($learner, $course, 'dictation', ['lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated());

    expect(count($plain))->toBeLessThanOrEqual(BUDGET_OPEN, implode("\n", $plain))
        ->and(count($replacing))->toBeLessThanOrEqual(BUDGET_OPEN, implode("\n", $replacing))
        ->and(budgetReads($plain, 'words'))->toBe(0) // a sentence reads tasks, not words
        ->and(budgetReads($plain, 'practice_items'))->toBe(1)
        ->and(budgetReads($replacing, 'words'))->toBe(1)
        ->and(budgetReads($replacing, 'practice_items'))->toBe(1)
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'practice.session_started')->count())->toBe(2);
});

it('opens a session of the real pairs in at most 12 statements, and reads the words once for the availability and the build together', function (): void {
    foreach (['ru', 'uz'] as $locale) {
        [$learner, $course, $lesson] = budgetWorld($locale);

        $queries = QueryLog::during(fn () => budgetOpen($learner, $course, 'pairs', ['lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated()->assertJsonPath('progress.total', 2));

        expect(count($queries))->toBeLessThanOrEqual(BUDGET_OPEN, "pairs ({$locale}): ".implode("\n", $queries))
            ->and(budgetReads($queries, 'words'))->toBe(1)
            ->and(budgetReads($queries, 'word_translations'))->toBe(1)
            ->and(budgetReads($queries, 'practice_items'))->toBe(0); // pairs take words, not tasks
    }
});

it('shows a session in at most 3 statements, a short one and a long one alike', function (): void {
    [$learner, $course, $lesson] = budgetWorld();
    config(['practice.solo.items.size' => 12]);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 20);

    $short = budgetOpen($learner, $course, 'sentence', ['lesson_id' => $lesson->id])->assertCreated()->json();
    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$short['id']}")->assertOk());
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_SHOW, implode("\n", $queries));

    $long = budgetOpen($learner, $course, 'collocation', ['replace_active' => true])->assertCreated();
    expect($long->json('progress.total'))->toBe(12);
    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$long->json('id')}")->assertOk());
    expect(count($queries))->toBeLessThanOrEqual(BUDGET_SHOW, implode("\n", $queries));
});

it('takes an answer in at most 6 statements, the last one that ends the session in at most 7 (the summary checks the retry)', function (): void {
    [$learner, $course] = budgetWorld();
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    $session = budgetOpen($learner, $course, 'collocation')->assertCreated()->json();

    $middle = QueryLog::during(function () use ($learner, &$session): void {
        $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['text' => 'nonsense'])->assertOk()->json('session');
    });
    $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['skip' => true])->assertOk()->json('session');
    $last = QueryLog::during(function () use ($learner, &$session): void {
        $session = budgetAnswer($learner, $session['id'], $session['current']['id'], ['skip' => true])->assertOk()->json('session');
    });

    expect($session['status'])->toBe('finished')
        ->and(count($middle))->toBeLessThanOrEqual(BUDGET_ANSWER, implode("\n", $middle))
        ->and(count($last))->toBeLessThanOrEqual(BUDGET_ANSWER_LAST, implode("\n", $last))
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('takes a match of pairs in at most 6 statements as well', function (): void {
    [$learner, $course] = budgetWorld();
    $session = budgetOpen($learner, $course, 'pairs')->assertCreated()->json();
    $round = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'][0];
    $left = $round['left'][0]['id'];

    $queries = QueryLog::during(fn () => budgetAnswer($learner, $session['id'], $round['id'], ['left_id' => $left, 'right_id' => $round['pairs'][$left]])->assertOk());

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_ANSWER, implode("\n", $queries));
});

it('hands out a clip in at most 2 statements before the file itself', function (): void {
    [$learner, $course] = budgetWorld();
    $session = budgetOpen($learner, $course, 'dictation')->assertCreated()->json();
    $element = collect(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'])->firstWhere('id', $session['current']['id']);
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put($element['audio_path'], 'mp3');

    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->get($session['current']['audio_url'])->assertOk()->streamedContent());

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_AUDIO, implode("\n", $queries));
});

it('keeps the response of a session under 8 KB and refuses to store a snapshot over the limit', function (): void {
    [$learner, $course] = budgetWorld();
    $fake = PracticeKit::fake(PracticeMode::Collocation, candidates: 9);

    $response = budgetOpen($learner, $course, 'collocation')->assertCreated();
    expect(strlen((string) $response->getContent()))->toBeLessThan(BUDGET_SESSION_BYTES);

    // 5 elements that weigh most of the limit between them: under it, the padding stays in the snapshot.
    PracticeSession::query()->delete();
    $fake->padding = intdiv(Snapshot::MAX_BYTES, 5) - 1_500;
    $stored = budgetOpen($learner, $course, 'collocation')->assertCreated();
    $bytes = strlen(json_encode(PracticeSession::query()->findOrFail($stored->json('id'))->snapshot, JSON_THROW_ON_ERROR));
    expect($bytes)->toBeGreaterThan((int) (Snapshot::MAX_BYTES * 0.7))->toBeLessThan(Snapshot::MAX_BYTES)
        ->and(strlen((string) $stored->getContent()))->toBeLessThan(BUDGET_SESSION_BYTES);

    // 5 elements that weigh more than the limit: a LogicException, no row, no event, and the learner is free to start another session.
    PracticeSession::query()->delete();
    ProductEvent::query()->delete();
    $fake->padding = intdiv(Snapshot::MAX_BYTES, 5) + 1_500;
    $this->withoutExceptionHandling();
    expect(fn () => budgetOpen($learner, $course, 'collocation'))->toThrow(LogicException::class, 'exceeds the limit');
    expect(PracticeSession::query()->count())->toBe(0)
        ->and(ProductEvent::query()->count())->toBe(0);

    $fake->padding = 0;
    budgetOpen($learner, $course, 'collocation')->assertCreated();
});

// ------------------------------------------------------------------------------------------------------------ the battle (18.6)

/*
 * docs/16 §13, the battle. The HTTP budgets count the 1 statement of TwaAuth (the learner); the services are measured here
 * without it (the same budgets on the routes are at the end of the file, 18.6b), so every service-level bound below is the HTTP one minus one.
 */
const BUDGET_BATTLE_POLL = 4;
const BUDGET_BATTLE_POLL_TRANSITION = 10;
const BUDGET_BATTLE_ANSWER = 8;
const BUDGET_BATTLE_INVITE = 14;
/** The sweeper: the expired invitations (one read, one release of their participants, one read of their challengers; a claim and an event each) and the live matches (one read with their participants - two statements; a match that is due: a lock, the participants again and the steps of the clock). */
const BUDGET_BATTLE_SWEEP_BASE = 5;
const BUDGET_BATTLE_SWEEP_PER_EXPIRED = 2;
const BUDGET_BATTLE_SWEEP_PER_LIVE = 7;
const BUDGET_BATTLE_POLL_BYTES = 600;
/** Reads the document gives no number for, pinned a little above where they are (the learner of TwaAuth included): a round with its review 4, the battle 4, the list 7. */
const BUDGET_BATTLE_ROUND = 5;
const BUDGET_BATTLE_VIEW = 5;
const BUDGET_BATTLE_LIST = 8;
/** A clip: the learner, the battle and its participants (`BattleReader::find`) - one more than a clip of a solo session (§13: 2): the players of the battle are the price of "only a player gets it". */
const BUDGET_BATTLE_AUDIO = 3;

/** A battle that is on, with both players present. @return array{0: Learner, 1: Learner, 2: Battle} */
function budgetBattle(PracticeMode $mode = PracticeMode::Collocation): array
{
    Storage::fake('practice_audio');
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world(slug: 'battle-'.$mode->value);
    $battle = BattleKit::playing($me, $friend, $friendship, $mode, $course, $lesson);

    return [$me, $friend, $battle];
}

function budgetPoll(Learner $viewer, int $battleId): BattlePollView
{
    $reader = app(BattleReader::class);

    return $reader->poll($reader->find($viewer, $battleId), $viewer, CarbonImmutable::now());
}

/** Two friends (ru / uz) besides the world's, with their accepted friendship. @return array{0: Learner, 1: Learner, 2: Friendship} */
function budgetPair(): array
{
    $a = Learner::factory()->create(['interface_locale' => 'ru']);
    $b = Learner::factory()->create(['interface_locale' => 'uz']);

    return [$a, $b, Friendship::factory()->between($a, $b)->create()];
}

it('polls a live battle in at most 3 statements without a transition (the presence written), and in at most 9 with one - a time-out, the next round, the end', function (): void {
    [$me, $friend, $battle] = budgetBattle();

    // No transition, the presence stale (written): find = battle + participants, the heartbeat.
    CarbonImmutable::setTestNow('2026-10-30 10:00:10');
    $quiet = QueryLog::during(fn () => budgetPoll($me, $battle->id));
    expect(count($quiet))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL - 1, implode("\n", $quiet))
        ->and(budgetReads($quiet, 'battles'))->toBe(1)
        ->and(budgetReads($quiet, 'battle_participants'))->toBe(1)
        ->and(budgetReads($quiet, 'battle_answers'))->toBe(0);

    // The deadline and the grace passed with one answer in (both players polling all along): a time-out for the other, the round resolved.
    BattleKit::answer($battle, $me, 1, BattleKit::correct(BattleKit::element($battle, 1)));
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:20');
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:32');
    CarbonImmutable::setTestNow('2026-10-30 10:00:34.5');
    $timeout = QueryLog::during(fn () => budgetPoll($friend, $battle->id));
    expect(count($timeout))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION - 1, implode("\n", $timeout))
        ->and(BattleKit::fresh($battle)->phase)->toBe(BattlePhase::Reveal);

    // The reveal is over: the next round.
    CarbonImmutable::setTestNow('2026-10-30 10:00:38.5');
    $next = QueryLog::during(fn () => budgetPoll($me, $battle->id));
    expect(count($next))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION - 1, implode("\n", $next))
        ->and(BattleKit::fresh($battle)->current_round)->toBe(2);

    // Rounds 2-5 played; the end of the last reveal: the finalisation with its event.
    for ($round = 2; $round <= 5; $round++) {
        $element = BattleKit::element($battle, $round);
        BattleKit::answer($battle, $me, $round, BattleKit::correct($element));
        BattleKit::answer($battle, $friend, $round, BattleKit::wrong($element));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
        if ($round < 5) {
            budgetPoll($me, $battle->id);
        }
    }
    $final = QueryLog::during(fn () => budgetPoll($friend, $battle->id));
    expect(count($final))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION - 1, implode("\n", $final))
        ->and(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Finished)
        ->and(ProductEvent::query()->where('name', 'battle.finished')->count())->toBe(1);

    // The poll of a match that is over: no presence, the rematch looked up.
    $over = QueryLog::during(fn () => budgetPoll($me, $battle->id));
    expect(count($over))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL - 1, implode("\n", $over));
});

it('takes the answer to a round in at most 7 statements, the second one that resolves the round included', function (): void {
    [$me, $friend, $battle] = budgetBattle(PracticeMode::Pairs);
    $element = BattleKit::element($battle, 1);

    CarbonImmutable::setTestNow('2026-10-30 10:00:10'); // the presence of the one who answers is stale: written
    $first = QueryLog::during(fn () => BattleKit::answer($battle, $me, 1, BattleKit::correct($element)));
    $second = QueryLog::during(fn () => BattleKit::answer($battle, $friend, 1, BattleKit::wrong($element)));
    $row = BattleKit::fresh($battle);

    expect(count($first))->toBeLessThanOrEqual(BUDGET_BATTLE_ANSWER - 1, implode("\n", $first))
        ->and(count($second))->toBeLessThanOrEqual(BUDGET_BATTLE_ANSWER - 1, implode("\n", $second))
        ->and(budgetReads($second, 'battle_answers'))->toBe(1)
        ->and($row->phase)->toBe(BattlePhase::Reveal)
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([100, 50]);
});

it('sends an invitation in at most 13 statements - pairs (words and two languages of translations), a task mode, and a rematch', function (): void {
    Storage::fake('practice_audio');
    Queue::fake(); // the DM of the invitation is a job of the queue worker, not of the request (sync in tests would count it)
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $service = app(BattleService::class);

    $pairs = QueryLog::during(fn () => $service->invite($me, $friendship->id, PracticeMode::Pairs, $course->slug, $lesson->id, null));
    $service->cancel($me, Battle::query()->latest('id')->firstOrFail()->id);
    $tasks = QueryLog::during(fn () => $service->invite($me, $friendship->id, PracticeMode::Collocation, $course->slug, null, null));
    $source = Battle::query()->latest('id')->firstOrFail();
    $service->accept($friend, $source->id);
    $service->ready($me, $source->id);
    $service->ready($friend, $source->id);
    $service->surrender($friend, $source->id);
    $rematch = QueryLog::during(fn () => $service->invite($me, null, PracticeMode::Pairs, 'ignored', null, $source->id));

    expect(count($pairs))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE - 1, implode("\n", $pairs))
        ->and(budgetReads($pairs, 'words'))->toBe(1)
        ->and(budgetReads($pairs, 'word_translations'))->toBe(2) // ru and uz
        ->and(count($tasks))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE - 1, implode("\n", $tasks))
        ->and(budgetReads($tasks, 'practice_items'))->toBe(1)
        ->and(budgetReads($tasks, 'practice_item_versions'))->toBe(1)
        ->and(count($rematch))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE - 1, implode("\n", $rematch))
        ->and(Battle::query()->count())->toBe(3);
});

it('sweeps the battles within 5 + 2 per expired invitation + 7 per live match that is due', function (): void {
    Storage::fake('practice_audio');
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    [$a1, $b1, $f1] = budgetPair();
    [$a2, $b2, $f2] = budgetPair();
    [$a3, $b3, $f3] = budgetPair();
    [$a4, $b4, $f4] = budgetPair();

    // Two expired invitations, a countdown past its start, a game past the deadline (two time-outs), a game at the end of its last reveal.
    $expired = [BattleKit::invite($a1, $f1, PracticeMode::Pairs, $course), BattleKit::invite($a2, $f2, PracticeMode::Pairs, $course)];
    $countdown = BattleKit::countdown($a3, $b3, $f3, PracticeMode::Collocation, $course);
    $game = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $ending = BattleKit::playing($a4, $b4, $f4, PracticeMode::Sentence, $course);
    for ($round = 1; $round <= 5; $round++) {
        $element = BattleKit::element($ending, $round);
        BattleKit::answer($ending, $a4, $round, BattleKit::correct($element));
        BattleKit::answer($ending, $b4, $round, BattleKit::wrong($element));
        if ($round < 5) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
            budgetPoll($a4, $ending->id);
        }
    }
    CarbonImmutable::setTestNow('2026-10-30 10:05:01');
    Battle::query()->whereIn('id', [$expired[0]->id, $expired[1]->id])->update(['invite_expires_at' => '2026-10-30 10:05:00']);
    Battle::query()->whereKey($game->id)->update(['round_deadline_at' => '2026-10-30 10:04:59']);
    Battle::query()->whereKey($ending->id)->update(['phase_ends_at' => '2026-10-30 10:05:00']);
    BattleParticipant::query()->whereIn('battle_id', [$game->id, $ending->id, $countdown->id])->update(['last_seen_at' => '2026-10-30 10:05:00']); // everybody is here

    $queries = QueryLog::during(fn () => expect(app(BattleJanitor::class)->sweep())->toBe(5));

    expect(count($queries))->toBeLessThanOrEqual(BUDGET_BATTLE_SWEEP_BASE + 2 * BUDGET_BATTLE_SWEEP_PER_EXPIRED + 3 * BUDGET_BATTLE_SWEEP_PER_LIVE, implode("\n", $queries))
        ->and(BattleKit::fresh($countdown)->status)->toBe(BattleStatus::Playing)
        ->and(BattleKit::fresh($game)->phase)->toBe(BattlePhase::Reveal)
        ->and(BattleKit::fresh($ending)->status)->toBe(BattleStatus::Finished)
        ->and(Battle::query()->where('end_reason', 'invite_expired')->count())->toBe(2)
        ->and(app(BattleJanitor::class)->sweep())->toBe(0);
});

it('keeps the poll of a battle under 600 bytes on the wire: a game with its round, and a match that is over with a rematch', function (): void {
    [$me, $friend, $battle] = budgetBattle();
    BattleKit::answer($battle, $me, 1, BattleKit::correct(BattleKit::element($battle, 1)));
    CarbonImmutable::setTestNow('2026-10-30 10:00:20');

    $playing = budgetPoll($me, $battle->id)->toArray();
    expect(strlen(json_encode($playing, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_BYTES)
        ->and(array_keys($playing))->toBe(['id', 'status', 'version', 'server_now', 'ready', 'opponent_present', 'opponent_absent_since', 'round', 'scores', 'end_reason', 'rematch'])
        ->and($playing['round'])->toMatchArray(['no' => 1, 'phase' => 'answering', 'my_answered' => true, 'opponent_answered' => false]);

    // The friend has been silent since the acceptance: absent (the poll says since when), and that ends the match in my favour.
    CarbonImmutable::setTestNow('2026-10-30 10:00:31');
    $absent = budgetPoll($me, $battle->id)->toArray();
    expect(strlen(json_encode($absent, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_BYTES)
        ->and([$absent['status'], $absent['end_reason']])->toBe(['finished', 'absent']);

    app(BattleService::class)->invite($friend, null, PracticeMode::Pairs, 'ignored', null, $battle->id);
    $over = budgetPoll($me, $battle->id)->toArray();
    expect(strlen(json_encode($over, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_BYTES)
        ->and($over['rematch'])->toMatchArray(['status' => 'invited', 'by_me' => false]);
});

// ------------------------------------------------------------------------------------------------------------ the battle over HTTP (18.6b)

/*
 * The same budgets, on the routes: `TwaAuth` reads the learner (1 statement), so the bounds above (`BUDGET_BATTLE_*`, which are the
 * HTTP ones: the service tests above subtract that 1) hold for the whole request - the controller, the request, the resource
 * included. The DM of an invitation is a job of the queue worker, not of the request: the queue is faked.
 */

/** The statements of one request; the answer must be a success. @return list<string> */
function budgetHttp(Closure $request): array
{
    return QueryLog::during(function () use ($request): void {
        $response = $request();
        $response->assertSuccessful();
    });
}

it('polls a battle over HTTP in at most 4 statements without a transition and in at most 10 with one - a time-out, the next round, the end, a poll of a match that is over', function (): void {
    [$me, $friend, $battle] = budgetBattle();
    $poll = fn (Learner $who) => fn (): TestResponse => BattleHttp::poll($who, $battle->id);

    // No transition, the presence stale (written): the learner, the battle, its participants, the heartbeat.
    CarbonImmutable::setTestNow('2026-10-30 10:00:10');
    $quiet = budgetHttp($poll($me));
    expect(count($quiet))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL, implode("\n", $quiet))
        ->and(budgetReads($quiet, 'battles'))->toBe(1)
        ->and(budgetReads($quiet, 'battle_participants'))->toBe(1)
        ->and(budgetReads($quiet, 'battle_answers'))->toBe(0);

    // The presence is fresh: no heartbeat to write, no answers to read - fewer still.
    $fresh = budgetHttp($poll($me));
    expect(count($fresh))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL - 1, implode("\n", $fresh));

    // The deadline and the grace passed with one answer in: a time-out for the other, the round resolved.
    BattleHttp::answer($me, $battle->id, 1, BattleKit::correct(BattleKit::element($battle, 1)))->assertOk();
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:20');
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:32');
    CarbonImmutable::setTestNow('2026-10-30 10:00:34.5');
    $timeout = budgetHttp($poll($friend));
    expect(count($timeout))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION, implode("\n", $timeout))
        ->and(BattleKit::fresh($battle)->phase)->toBe(BattlePhase::Reveal);

    // The reveal is over: the next round.
    CarbonImmutable::setTestNow('2026-10-30 10:00:38.5');
    $next = budgetHttp($poll($me));
    expect(count($next))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION, implode("\n", $next))
        ->and(BattleKit::fresh($battle)->current_round)->toBe(2);

    // Rounds 2-5 played; the end of the last reveal: the finalisation with its event.
    for ($round = 2; $round <= 5; $round++) {
        $element = BattleKit::element($battle, $round);
        BattleKit::answer($battle, $me, $round, BattleKit::correct($element));
        BattleKit::answer($battle, $friend, $round, BattleKit::wrong($element));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
        if ($round < 5) {
            budgetPoll($me, $battle->id);
        }
    }
    $final = budgetHttp($poll($friend));
    expect(count($final))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL_TRANSITION, implode("\n", $final))
        ->and(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Finished)
        ->and(ProductEvent::query()->where('name', 'battle.finished')->count())->toBe(1);

    // The poll of a match that is over: no presence, the rematch looked up.
    $over = budgetHttp($poll($me));
    expect(count($over))->toBeLessThanOrEqual(BUDGET_BATTLE_POLL, implode("\n", $over));
});

it('takes an answer over HTTP in at most 8 statements, the second one that resolves the round included, and reads the round and the battle within a handful', function (): void {
    [$me, $friend, $battle] = budgetBattle(PracticeMode::Pairs);
    $element = BattleKit::element($battle, 1);

    CarbonImmutable::setTestNow('2026-10-30 10:00:10'); // the presence of the one who answers is stale: written
    $first = budgetHttp(fn (): TestResponse => BattleHttp::answer($me, $battle->id, 1, BattleKit::correct($element)));
    $second = budgetHttp(fn (): TestResponse => BattleHttp::answer($friend, $battle->id, 1, BattleKit::wrong($element)));
    $row = BattleKit::fresh($battle);

    expect(count($first))->toBeLessThanOrEqual(BUDGET_BATTLE_ANSWER, implode("\n", $first))
        ->and(count($second))->toBeLessThanOrEqual(BUDGET_BATTLE_ANSWER, implode("\n", $second))
        ->and(budgetReads($second, 'battle_answers'))->toBe(1)
        ->and($row->phase)->toBe(BattlePhase::Reveal)
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([100, 50]);

    // A repeat of the request, the round read with its review, the battle, the list: the reads have no budget in the document; pinned here.
    $repeat = budgetHttp(fn (): TestResponse => BattleHttp::answer($friend, $battle->id, 1, BattleKit::wrong($element), BattleKit::fresh($battle)->answers()->where('learner_id', $friend->id)->value('request_id')));
    $round = budgetHttp(fn (): TestResponse => BattleHttp::round($me, $battle->id, 1));
    $view = budgetHttp(fn (): TestResponse => BattleHttp::view($me, $battle->id));
    $list = budgetHttp(fn (): TestResponse => BattleHttp::get($me, ''));
    expect(count($repeat))->toBeLessThanOrEqual(BUDGET_BATTLE_ANSWER, implode("\n", $repeat))
        ->and(count($round))->toBeLessThanOrEqual(BUDGET_BATTLE_ROUND, implode("\n", $round))
        ->and(count($view))->toBeLessThanOrEqual(BUDGET_BATTLE_VIEW, implode("\n", $view))
        ->and(count($list))->toBeLessThanOrEqual(BUDGET_BATTLE_LIST, implode("\n", $list));
});

it('sends an invitation over HTTP in at most 14 statements - pairs (words and two languages of translations), a task mode, and a rematch - and hands out a clip in at most 3', function (): void {
    Storage::fake('practice_audio');
    Queue::fake(); // the DM of the invitation is a job of the queue worker, not of the request
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $pairs = budgetHttp(fn (): TestResponse => BattleHttp::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson)->assertCreated());
    BattleHttp::cancel($me, (int) Battle::query()->latest('id')->firstOrFail()->id)->assertOk();
    $tasks = budgetHttp(fn (): TestResponse => BattleHttp::invite($me, $friendship, PracticeMode::Dictation, $course, null)->assertCreated());
    $source = Battle::query()->latest('id')->firstOrFail();
    BattleHttp::accept($friend, $source->id)->assertOk();
    $url = (string) BattleHttp::view($friend, $source->id)->json('audio_preload.0');
    $clip = budgetHttp(fn (): TestResponse => BattleHttp::audio($friend, $url));
    BattleHttp::ready($me, $source->id)->assertOk();
    BattleHttp::ready($friend, $source->id)->assertOk();
    BattleHttp::surrender($friend, $source->id)->assertOk();
    $rematch = budgetHttp(fn (): TestResponse => BattleHttp::rematch($me, $source->id, ['mode' => 'pairs', 'course' => 'ignored'])->assertCreated());

    expect(count($pairs))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE, implode("\n", $pairs))
        ->and(budgetReads($pairs, 'words'))->toBe(1)
        ->and(budgetReads($pairs, 'word_translations'))->toBe(2) // ru and uz
        ->and(count($tasks))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE, implode("\n", $tasks))
        ->and(budgetReads($tasks, 'practice_items'))->toBe(1)
        ->and(budgetReads($tasks, 'practice_item_versions'))->toBe(1)
        ->and(count($rematch))->toBeLessThanOrEqual(BUDGET_BATTLE_INVITE, implode("\n", $rematch))
        ->and(count($clip))->toBeLessThanOrEqual(BUDGET_BATTLE_AUDIO, implode("\n", $clip));
});
