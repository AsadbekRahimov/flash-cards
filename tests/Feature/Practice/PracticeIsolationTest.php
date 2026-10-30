<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Models\Achievement;
use App\Models\AssignmentResult;
use App\Models\Battle;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §0 "Изоляция", §12, §14: the practice writes its OWN tables (§2) and `product_events` (§3.4) and nothing else - not the
 * schedule of the repetitions, not the statistics of the day, not the streak, the leagues, the assignments, the achievements or
 * the challenges.
 *
 * This is the skeleton of 18.1: the solo sessions of the four modes on the test handler. The battle (both players) adds its own
 * test here with 18.6. Three independent nets: the rows of the protected tables are the same after the run (a hash), the run
 * issued no INSERT / UPDATE / DELETE against any table but the three, and the code of the practice imports none of the
 * services that write the learning of a learner.
 */

uses(RefreshDatabase::class);

/** The tables the practice must not change (docs/16 §0); `learners` has its own columns, see isolationChecksums(). */
const ISOLATION_TABLES = ['word_repetitions', 'training_reviews', 'training_sessions', 'learner_daily_stats', 'league_members', 'assignment_results', 'achievements', 'challenges'];

/** What the solo practice writes (docs/16 §2, §3.4), and no more. */
const ISOLATION_WRITTEN = ['practice_attempts', 'practice_sessions', 'product_events'];

/** Every event of docs/16 §3.4: nothing else may land in `product_events`. */
const ISOLATION_EVENT_NAMES = [
    'practice.session_started', 'practice.session_finished', 'practice.not_enough_material', 'practice.audio_failed',
    'battle.invited', 'battle.accepted', 'battle.started', 'battle.finished', 'battle.closed',
];

/** The props of the events of the solo practice, exactly as §3.4 lists them (no PII, nothing else). */
const ISOLATION_SOLO_EVENT_PROPS = [
    'practice.session_started' => ['session_id', 'mode', 'course_id', 'lesson_id', 'source', 'total', 'retry'],
    'practice.session_finished' => ['session_id', 'mode', 'total', 'answered', 'correct_first', 'hints', 'mismatches', 'reason', 'duration_ms'],
    'practice.not_enough_material' => ['mode', 'course_id', 'lesson_id', 'available', 'required', 'reason', 'battle'],
    'practice.audio_failed' => ['session_id', 'source', 'reason', 'verified'],
];

/** What the battle writes (docs/16 §2.7, §3.4, §8.7), and no more: its tables, the events, the marker of the DM of an invitation. */
const ISOLATION_BATTLE_WRITTEN = ['battle_answers', 'battle_participants', 'battles', 'notifications_log', 'product_events'];

/** The props of the events of the battle, exactly as §3.4 lists them (the audio event names the battle, not a session). */
const ISOLATION_BATTLE_EVENT_PROPS = [
    'battle.invited' => ['battle_id', 'mode', 'course_id', 'lesson_id', 'rematch'],
    'battle.accepted' => ['battle_id'],
    'battle.started' => ['battle_id', 'mode'],
    'battle.finished' => ['battle_id', 'end_reason', 'result', 'rounds_played', 'duration_ms'],
    'battle.closed' => ['battle_id', 'end_reason'],
    'practice.audio_failed' => ['battle_id', 'source', 'reason', 'verified'],
    'practice.not_enough_material' => ['mode', 'course_id', 'lesson_id', 'available', 'required', 'reason', 'battle'],
];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake(FakeEchoMode::AUDIO_DISK);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A learner with a life: introduced words, a training with a review, a day of statistics, a streak and a goal, a league, an
 * assignment, an achievement and a challenge - so that "nothing changed" means something.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson}
 */
function isolationWorld(): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create(['streak_current' => 4, 'streak_best' => 9, 'streak_last_date' => '2026-10-29', 'streak_freezes' => 2, 'daily_goal' => 35]);
    $friend = Learner::factory()->create();

    $words = PracticeKit::words($lesson, 4, $learner, state: 'review');
    $training = TrainingSession::factory()->personal($learner)->closed()->create();
    TrainingReview::factory()->create(['training_session_id' => $training->id, 'learner_id' => $learner->id, 'word_id' => $words[0]->id, 'quality' => 4]);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'reviews_count' => 12, 'new_words_count' => 3, 'correct_count' => 9, 'time_spent_ms' => 120_000]);
    LeagueMember::factory()->create(['learner_id' => $learner->id]);
    AssignmentResult::factory()->create(['learner_id' => $learner->id, 'progress_pct' => 40]);
    Achievement::factory()->create(['learner_id' => $learner->id]);
    Challenge::factory()->active()->create(['challenger_id' => $learner->id, 'opponent_id' => $friend->id]);

    return [$learner, $course, $lesson];
}

/**
 * A hash of every protected table (all its rows, all its columns) and of what the learners keep of the streak and the goal.
 *
 * @return array<string, string>
 */
function isolationChecksums(): array
{
    $sums = [];
    foreach (ISOLATION_TABLES as $table) {
        $sums[$table] = md5(json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR));
    }

    $columns = array_values(array_filter(
        Schema::getColumnListing('learners'),
        static fn (string $column): bool => $column === 'id' || $column === 'daily_goal' || str_starts_with($column, 'streak_'),
    ));
    $sums['learners'] = md5(json_encode(DB::table('learners')->orderBy('id')->get($columns), JSON_THROW_ON_ERROR));

    return $sums;
}

/**
 * The tables an INSERT / UPDATE / DELETE of the log went to.
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function isolationWrittenTables(array $queries): array
{
    $tables = [];
    foreach ($queries as $sql) {
        if (preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update|delete\s+from)\s+["`]?([a-z_]+)["`]?/i', $sql, $match) === 1) {
            $tables[$match[1]] = true;
        }
    }

    $names = array_keys($tables);
    sort($names);

    return $names;
}

/** The run plays more than a minute's worth of requests: the limits are not what is looked at here. @param array<string, mixed> $body */
function isolationPost(Learner $learner, string $uri, array $body): TestResponse
{
    PracticeKit::resetLimiters($learner);

    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/{$uri}", $body);
}

function isolationGet(Learner $learner, string $uri): TestResponse
{
    PracticeKit::resetLimiters($learner);

    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/{$uri}");
}

/** @param array<string, mixed> $body */
function isolationOpen(Learner $learner, Course $course, string $mode, array $body = []): TestResponse
{
    return isolationPost($learner, 'sessions', ['mode' => $mode, 'course' => $course->slug, ...$body]);
}

/** @return array<string, mixed> an element of the snapshot (a test may read it) */
function isolationElement(int $sessionId, string $elementId): array
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element;
        }
    }

    throw new RuntimeException('No such element.');
}

/**
 * Plays a session to its end, whatever the mode: a round of pairs gets one wrong match before the right ones, the other modes
 * get a right answer, a wrong one, a typo and a skip, over and over.
 *
 * @param  array<string, mixed>  $session
 * @return array<string, mixed> the finished session
 */
function isolationPlay(Learner $learner, array $session): array
{
    for ($i = 0; $session['status'] === 'active' && $i < 40; $i++) {
        $elementId = $session['current']['id'];
        $element = isolationElement($session['id'], $elementId);
        $answers = [];

        if ($element['kind'] === 'pairs_round') {
            $lefts = array_column($element['left'], 'id');
            $wrong = array_values(array_diff(array_column($element['right'], 'id'), [$element['pairs'][$lefts[0]]]))[0];
            $answers[] = ['left_id' => $lefts[0], 'right_id' => $wrong];
            foreach ($lefts as $leftId) {
                $answers[] = ['left_id' => $leftId, 'right_id' => $element['pairs'][$leftId]];
            }
        } else {
            $answers[] = match ($i % 4) {
                0 => ['text' => $element['expected']],
                1 => ['text' => 'nonsense'],
                2 => ['text' => 'almost'],
                default => ['skip' => true],
            };
        }

        foreach ($answers as $answer) {
            $session = isolationPost($learner, "sessions/{$session['id']}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer])
                ->assertOk()->json('session');
        }
    }

    return $session;
}

/**
 * Everything a learner does with the practice: every mode to its end and the repeat of its mistakes, a clip that does not play
 * and the swap for the next one, the hub, a session that is not opened for a lack of material, a session that a newer one
 * replaces, one that the scheduler closes and one that a request closes.
 */
function isolationPlayEverything(Learner $learner, Course $course, Lesson $lesson): void
{
    foreach (PracticeMode::cases() as $mode) {
        isolationGet($learner, "hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk();
        $session = isolationOpen($learner, $course, $mode->value, ['lesson_id' => $lesson->id, 'source' => 'after_training'])->assertCreated()->json();

        if ($mode === PracticeMode::Dictation) {
            // The first clip is not on the disk: 503, the event, the swap for a spare; the next clip is there and plays.
            isolationGet($learner, Str::after($session['current']['audio_url'], '/api/twa/practice/'))->assertStatus(503);
            $session = isolationPost($learner, "sessions/{$session['id']}/replace", ['request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'reason' => 'audio_unavailable'])
                ->assertOk()->json();
            Storage::disk(FakeEchoMode::AUDIO_DISK)->put(isolationElement($session['id'], $session['current']['id'])['audio_path'], 'mp3');
            isolationGet($learner, Str::after($session['current']['audio_url'], '/api/twa/practice/'))->assertOk()->streamedContent();
        }

        $finished = isolationPlay($learner, $session);
        expect($finished['status'])->toBe('finished')
            ->and($finished['summary']['retry_available'])->toBeTrue();

        $retry = isolationOpen($learner, $course, $mode->value, ['retry_of' => $finished['id']])->assertCreated()->json();
        expect($retry['retry_of'])->toBe($finished['id'])
            ->and(isolationPlay($learner, $retry)['status'])->toBe('finished');
    }

    // Too little material: 422, nothing but the event.
    PracticeKit::fake(PracticeMode::Collocation, candidates: 1);
    isolationOpen($learner, $course, 'collocation')->assertStatus(422)->assertJsonPath('error.code', 'practice_not_enough_material');
    PracticeKit::fake(PracticeMode::Collocation, candidates: 8);

    // A session that a newer one replaces ...
    isolationOpen($learner, $course, 'collocation')->assertCreated();
    isolationOpen($learner, $course, 'sentence', ['replace_active' => true])->assertCreated();

    // ... one that the scheduler closes a day later ...
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));
    expect(Artisan::call('practice:close-expired'))->toBe(0);

    // ... and one that a request closes.
    $last = isolationOpen($learner, $course, 'collocation')->assertCreated()->json();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));
    isolationGet($learner, "sessions/{$last['id']}")->assertOk()->assertJsonPath('summary.finish_reason', 'expired');
}

it('leaves the learning of the learner exactly as it was after all four modes, their retries, a replaced and an expired session', function (): void {
    [$learner, $course, $lesson] = isolationWorld();
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 8);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 8);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 8, spares: 2);

    foreach ([...ISOLATION_TABLES, 'learners'] as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, "The baseline of {$table} is empty: the test would prove nothing.");
    }
    $before = isolationChecksums();

    $queries = QueryLog::during(fn () => isolationPlayEverything($learner, $course, $lesson));

    // The rows are the same ...
    expect(isolationChecksums())->toBe($before);

    // ... and nothing was written anywhere but the tables of the practice and the events.
    expect(isolationWrittenTables($queries))->toBe(ISOLATION_WRITTEN)
        ->and(PracticeSession::query()->count())->toBeGreaterThan(8);
});

it('writes only the events of docs/16 §3.4, with the props the document lists and no personal data', function (): void {
    [$learner, $course, $lesson] = isolationWorld();
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 8);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 8);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 8, spares: 2);
    expect(ProductEvent::query()->count())->toBe(0);

    isolationPlayEverything($learner, $course, $lesson);

    $names = ProductEvent::query()->distinct()->pluck('name')->all();
    sort($names);
    expect(array_diff($names, ISOLATION_EVENT_NAMES))->toBe([])
        ->and($names)->toBe(['practice.audio_failed', 'practice.not_enough_material', 'practice.session_finished', 'practice.session_started']);

    foreach (ProductEvent::query()->get() as $event) {
        $keys = array_keys($event->props ?? []);
        $expected = ISOLATION_SOLO_EVENT_PROPS[$event->name];
        sort($keys);
        sort($expected);

        expect($keys)->toBe($expected, "props of {$event->name}")
            ->and($event->learner_id)->toBe($learner->id);
    }

    // One start and one finish per session; a session that was not opened (too little material) has neither.
    $sessions = PracticeSession::query()->count();
    expect(ProductEvent::query()->where('name', 'practice.session_started')->count())->toBe($sessions)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe($sessions)
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(0);
});

/**
 * Everything two friends do with the battle, through the services (the routes come with 18.6b): a full match of every mode -
 * right, wrong, skipped and timed-out answers, the reviews of the rounds in both languages -, an invitation declined, one
 * withdrawn, one that expires, a room left, a surrender, an absent opponent, a dictation with a clip reported and one voided
 * at "ready", a rematch, an invitation refused for lack of material, a match voided by the switch, the lists and the options.
 */
function isolationBattleEverything(Learner $me, Learner $friend, Course $course, Lesson $lesson, Friendship $friendship, Course $empty): void
{
    $service = app(BattleService::class);
    $reader = app(BattleReader::class);
    $janitor = app(BattleJanitor::class);
    $now = static fn (): CarbonImmutable => CarbonImmutable::now();

    foreach (PracticeMode::cases() as $i => $mode) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(BattleKit::T0)->addMinutes($i * 10));
        $reader->options($me, $friendship->id, $course->slug, $lesson->id);
        $battle = BattleKit::playing($me, $friend, $friendship, $mode, $course, $lesson);
        $reader->view($reader->find($friend, $battle->id), $friend, $now());

        for ($round = 1; $round <= 5; $round++) {
            $element = BattleKit::element($battle, $round);
            $reader->round($reader->find($me, $battle->id), $me, $round, $now());
            if ($round === 3) {
                // I answer, the friend lets the time run out while both keep polling (a client polls every 2 s): the sweeper
                // resolves the round with a time-out for the friend.
                BattleKit::answer($battle, $me, 3, BattleKit::correct($element));
                $deadline = BattleKit::fresh($battle)->round_deadline_at ?? throw new RuntimeException('no deadline');
                for ($at = CarbonImmutable::now()->addSeconds(15); $at->lt($deadline); $at = $at->addSeconds(15)) {
                    BattleKit::bothPoll($battle, $me, $friend, $at->toDateTimeString());
                }
                BattleKit::bothPoll($battle, $me, $friend, $deadline->subSecond()->toDateTimeString());
                CarbonImmutable::setTestNow($deadline->addSeconds(2));
                $janitor->sweep();
            } else {
                $mine = $round === 2 ? ($mode === PracticeMode::Pairs ? BattleKit::wrong($element) : ['skip' => true]) : BattleKit::correct($element);
                BattleKit::answer($battle, $me, $round, $mine);
                BattleKit::answer($battle, $friend, $round, $round % 2 === 0 ? BattleKit::correct($element) : BattleKit::wrong($element));
            }
            $reader->round($reader->find($friend, $battle->id), $friend, $round, $now());
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
            $reader->poll($reader->find($me, $battle->id), $me, $now()); // the next round, or the end
        }
        expect(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Finished);
        $reader->view($reader->find($friend, $battle->id), $friend, $now());
    }

    // Every other way a battle can end.
    $declined = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $service->decline($friend, $declined->id);
    $withdrawn = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    $service->cancel($me, $withdrawn->id);
    BattleKit::invite($friend, $friendship, PracticeMode::Collocation, $course);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
    $janitor->sweep();
    $left = BattleKit::room($me, $friend, $friendship, PracticeMode::Sentence, $course);
    $service->cancel($friend, $left->id);
    $surrendered = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $service->surrender($me, $surrendered->id);
    $absent = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
    $reader->poll($reader->find($me, $absent->id), $me, $now());

    // A dictation: a clip reported while it plays (the match goes on), then one voided at "ready" (a clip is gone).
    $dictation = BattleKit::playing($me, $friend, $friendship, PracticeMode::Dictation, $course);
    $service->audioFailed($me, $dictation->id, (string) Str::uuid(), BattleKit::element($dictation, 1)['id']);
    $service->surrender($friend, $dictation->id);
    $voided = BattleKit::room($me, $friend, $friendship, PracticeMode::Dictation, $course);
    Storage::disk('practice_audio')->delete(BattleKit::element($voided, 4)['audio_path']);
    $service->ready($me, $voided->id);

    // A rematch of the surrendered match, accepted and then left; an invitation refused for lack of material.
    $rematch = $service->invite($friend, null, PracticeMode::Pairs, 'ignored', null, $surrendered->id);
    $service->accept($me, $rematch->id);
    $service->cancel($me, $rematch->id);
    try {
        $service->invite($me, $friendship->id, PracticeMode::Pairs, $empty->slug, null, null);
    } catch (BattleException $e) {
        expect($e->errorCode)->toBe('battle_material_unavailable');
    }

    // The switch: a match that is on is voided by the sweeper.
    $live = BattleKit::playing($me, $friend, $friendship, PracticeMode::Pairs, $course);
    config(['practice.access' => 'off']);
    $janitor->sweep();
    config(['practice.access' => 'all']);
    expect(BattleKit::fresh($live)->meta['voided_reason'] ?? null)->toBe('practice_off');

    $reader->list($me, $now());
    $reader->list($friend, $now());
    expect(Battle::query()->whereIn('status', BattleStatus::nonTerminalValues())->count())->toBe(0);
}

it('leaves the learning of both players exactly as it was after battles of every mode and every way a battle can end', function (): void {
    [$me, $course, $lesson] = isolationWorld();
    $friend = Learner::factory()->create(['interface_locale' => 'uz', 'streak_current' => 2, 'streak_best' => 3, 'streak_last_date' => '2026-10-29', 'daily_goal' => 20]);
    PracticeKit::words($lesson, 2, $friend, state: 'review');
    LearnerDailyStats::factory()->create(['learner_id' => $friend->id, 'reviews_count' => 5, 'new_words_count' => 1, 'correct_count' => 4, 'time_spent_ms' => 60_000]);
    $friendship = Friendship::factory()->between($me, $friend)->create();
    BattleKit::items($lesson, BattleKit::words($lesson, 24), 6);
    [$empty] = PracticeKit::course('empty-course');
    $before = isolationChecksums();

    $queries = QueryLog::during(fn () => isolationBattleEverything($me, $friend, $course, $lesson, $friendship, $empty));

    // The rows are the same, and nothing was written anywhere but the tables of the battle, the events and the marker of the DM
    // of each invitation (the queue is `sync` here: the DM goes out at once, one per invitation, nothing else in `notifications_log`).
    expect(isolationChecksums())->toBe($before)
        ->and(isolationWrittenTables($queries))->toBe(ISOLATION_BATTLE_WRITTEN)
        ->and(Battle::query()->count())->toBeGreaterThan(12)
        ->and(NotificationLog::query()->distinct()->pluck('type')->all())->toBe(['battle_dm'])
        ->and(NotificationLog::query()->count())->toBe(Battle::query()->count());

    // Only the events of docs/16 §3.4, with the props the document lists; the actor of a battle event is its challenger.
    $names = ProductEvent::query()->distinct()->pluck('name')->all();
    sort($names);
    expect(array_diff($names, ISOLATION_EVENT_NAMES))->toBe([])
        ->and($names)->toBe(['battle.accepted', 'battle.closed', 'battle.finished', 'battle.invited', 'battle.started', 'practice.audio_failed', 'practice.not_enough_material']);
    foreach (ProductEvent::query()->get() as $event) {
        $keys = array_keys($event->props ?? []);
        $expected = ISOLATION_BATTLE_EVENT_PROPS[$event->name];
        sort($keys);
        sort($expected);
        expect($keys)->toBe($expected, "props of {$event->name}");
        if (str_starts_with($event->name, 'battle.')) {
            expect($event->learner_id)->toBe(Battle::query()->findOrFail((int) ($event->props['battle_id'] ?? 0))->challenger_id, "actor of {$event->name}");
        }
    }

    // One invitation and exactly one end per battle; a `finished` battle has its `battle.finished`, a `closed` one its `battle.closed`.
    $battles = Battle::query()->count();
    expect(ProductEvent::query()->where('name', 'battle.invited')->count())->toBe($battles)
        ->and(ProductEvent::query()->where('name', 'battle.finished')->count())->toBe(Battle::query()->where('status', 'finished')->count())
        ->and(ProductEvent::query()->where('name', 'battle.closed')->count())->toBe(Battle::query()->where('status', 'closed')->count())
        ->and(ProductEvent::query()->where('name', 'battle.started')->count())->toBe(Battle::query()->whereNotNull('starts_at')->count());
});

it('notices a change in any of the protected tables: the check is not blind', function (): void {
    [$learner] = isolationWorld();
    $before = isolationChecksums();

    foreach (ISOLATION_TABLES as $table) {
        DB::table($table)->delete();
        $after = isolationChecksums();

        expect($after[$table])->not->toBe($before[$table], "The hash of {$table} did not move.");
        $before = $after;
    }

    DB::table('learners')->where('id', $learner->id)->update(['streak_current' => 5]);
    expect(isolationChecksums()['learners'])->not->toBe($before['learners']);

    $before = isolationChecksums();
    DB::table('learners')->where('id', $learner->id)->update(['daily_goal' => 50]);
    expect(isolationChecksums()['learners'])->not->toBe($before['learners']);

    // The same for the net of the statements: a write is listed, a read (a lock included) is not.
    $queries = QueryLog::during(function (): void {
        DB::table('achievements')->where('id', 0)->update(['shared_at' => null]);
        DB::table('word_repetitions')->where('id', 0)->delete();
        DB::table('challenges')->where('id', 0)->lockForUpdate()->get();
        DB::table('learners')->where('id', 0)->get();
    });
    expect(isolationWrittenTables($queries))->toBe(['achievements', 'word_repetitions']);
});

it('imports none of the services and models that write the learning of a learner anywhere in the code of the practice', function (): void {
    $forbidden = [
        // the schedule of the repetitions, the training, the exams
        'App\Domain\Learning\Services\SpacedRepetitionEngine', 'App\Domain\Learning\Services\ReviewSubmissionService',
        'App\Domain\Learning\Services\TrainingSessionService', 'App\Domain\Learning\Services\TrainingFlowService',
        'App\Domain\Learning\Services\CardPicker', 'App\Domain\Learning\Services\ExamAnswerService',
        'App\Domain\Learning\Services\ExamFlowService', 'App\Domain\Learning\Services\ExamSessionService',
        'App\Domain\Learning\Services\MockExamService', 'App\Domain\Learning\Services\LearningCache',
        // the statistics of the day, the streak, the leagues, the achievements, the challenges
        'App\Domain\Learners\Services\StreakService', 'App\Domain\Learners\Services\DailyStatsService',
        'App\Domain\Engagement\Services\StreakFreezeService', 'App\Domain\Engagement\Services\LeagueService',
        'App\Domain\Engagement\Services\AchievementService', 'App\Domain\Engagement\Services\ChallengeService',
        'App\Domain\Engagement\Services\AchievementSender', 'App\Domain\Engagement\Services\LeagueResultSender',
        // the models of the protected tables
        'App\Models\WordRepetition', 'App\Models\TrainingSession', 'App\Models\TrainingReview', 'App\Models\LearnerDailyStats',
        'App\Models\LeagueGroup', 'App\Models\LeagueMember', 'App\Models\LeagueWeek', 'App\Models\Assignment',
        'App\Models\AssignmentResult', 'App\Models\Achievement', 'App\Models\Challenge',
    ];

    $files = [
        ...array_map(static fn ($file): string => $file->getPathname(), File::allFiles(app_path('Domain/Practice'))),
        ...glob(app_path('Http/Controllers/Api/Twa/Practice*.php')) ?: [],
        ...glob(app_path('Http/Requests/Twa/Practice*.php')) ?: [],
        ...glob(app_path('Http/Resources/Twa/Practice*.php')) ?: [],
        app_path('Console/Commands/ClosePracticeSessions.php'),
        app_path('Console/Commands/SweepBattles.php'),
        app_path('Jobs/SendBattleDmJob.php'),
        app_path('Domain/Telegram/Services/BattleMessenger.php'),
    ];
    expect(count($files))->toBeGreaterThan(40);

    $offences = [];
    foreach ($files as $file) {
        preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+\w+)?;/m', (string) file_get_contents($file), $imports);
        foreach (array_intersect($imports[1], $forbidden) as $class) {
            $offences[] = basename($file).' imports '.$class;
        }
    }

    expect($offences)->toBe([]);
});
