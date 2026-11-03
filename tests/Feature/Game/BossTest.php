<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Services\GameCardSender;
use App\Domain\Game\Services\Runs\BossService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Game\Support\BossWordPicker;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\SendAchievementJob;
use App\Jobs\SendGameCardJob;
use App\Models\Achievement;
use App\Models\Course;
use App\Models\GameCityProgress;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\GameWordMiss;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §2, §12.3.1, §12.3.4, §12.5.3, §12.8; docs/13 §7.4: the boss of a city - the 12 hardest words of the learner in the city
 * (the order of the picker), the readiness (the one rule of the map), the fight without flasks, the win at 80 % of the answers correct
 * (a typo does not count), the reward of the first and of a repeated win, the achievement `game_first_boss` and the card "I got to ..."
 * exactly once, the event, `summary.boss`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    Storage::fake('public');
    config(['telegram.bot_username' => 'LexiFlowBot']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A city (module 1) of three stops with `$sizes` words each, all introduced to a new learner (words with a long text, so that a
 * misspelling is a typo), the stops cleared (>= 1 star) when `$cleared`.
 *
 * @param  list<int>  $sizes
 * @return array{0: Learner, 1: Course, 2: Stage, 3: list<Lesson>, 4: list<Word>}
 */
function bossWorld(array $sizes = [5, 5, 4], bool $cleared = true): array
{
    [$course, $first] = PracticeKit::course();
    $stage = $first->stage ?? throw new RuntimeException('No stage.');
    $lessons = [$first];
    for ($n = 2; $n <= count($sizes); $n++) {
        $lessons[] = Lesson::factory()->for($stage)->create(['number' => $n, 'title' => "Урок {$n}"]);
    }

    $learner = Learner::factory()->create(['timezone' => 'UTC']);
    $words = [];
    foreach ($lessons as $l => $lesson) {
        for ($i = 1; $i <= $sizes[$l]; $i++) {
            $words[] = GameKit::word($lesson, $learner, 'caravanserai'.($l + 1).'x'.$i, 'перевод '.($l + 1).'.'.$i);
        }
        if ($cleared) {
            GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lesson->id]);
        }
    }

    return [$learner, $course, $stage, $lessons, $words];
}

function bossPost(Learner $learner, Course $course, int $stageId, array $extra = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'boss', 'course' => $course->slug, 'stage_id' => $stageId, ...$extra]);
}

function bossMap(Learner $learner, Course $course): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/map?course={$course->slug}");
}

/** The boss run in the database, started through the API. */
function bossStart(Learner $learner, Course $course, Stage $stage): GameRun
{
    return GameRun::query()->findOrFail(bossPost($learner, $course, $stage->id)->assertCreated()->json('id'));
}

/**
 * Plays the fight to its end: every element right, except the positions in `$wrong` (a wrong answer) and `$typos` (the first
 * `$typos` spelling or gap elements, answered with the last letter missing).
 *
 * @param  list<int>  $wrong
 */
function bossPlay(GameRun $run, Learner $learner, array $wrong = [], int $typos = 0): GameRun
{
    $runs = app(RunService::class);
    for ($guard = 0; $guard < 40; $guard++) {
        $run->refresh();
        if (! $run->isActive()) {
            return $run;
        }
        $index = (int) $run->state['current'];
        $element = $run->snapshot['elements'][$index];
        $answer = GameKit::rightAnswers($element)[0];
        if (in_array($index, $wrong, true)) {
            $answer = GameKit::wrongAnswer($element);
        } elseif ($typos > 0 && $element['kind'] === 'spell') {
            $answer = ['text' => substr((string) $element['expected'], 0, -1)];
            $typos--;
        }
        $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
    }

    throw new RuntimeException('The fight did not end.');
}

function bossEvents(string $name): int
{
    return ProductEvent::query()->where('name', $name)->count();
}

// ---------------------------------------------------------------------------------------------------------------------------
// the picker
// ---------------------------------------------------------------------------------------------------------------------------

it('picks the 12 hardest words of the city in the order of docs/18 section 12.3.4, and only the learner\'s own active words of that city', function (): void {
    [$learner, , $stage, $lessons, $w] = bossWorld([5, 5, 4]);

    $set = static fn (Word $word, array $attributes) => WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $word->id)->update($attributes);
    $miss = static fn (Word $word, int $n) => GameWordMiss::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'misses' => $n]);

    // the factory's rows are random: a plain baseline first
    WordRepetition::query()->where('learner_id', $learner->id)->update(['is_hard' => false, 'lapses' => 0, 'easiness_factor' => 2.50]);
    $set($w[0], ['is_hard' => true, 'lapses' => 0]);
    $set($w[1], ['is_hard' => true, 'lapses' => 3]);
    $set($w[2], ['lapses' => 5]);
    $set($w[3], ['lapses' => 2]);
    $miss($w[3], 4);
    $set($w[4], ['lapses' => 2]);
    $miss($w[4], 1);
    $set($w[5], ['lapses' => 2, 'easiness_factor' => 1.30]);
    $set($w[6], ['lapses' => 2, 'easiness_factor' => 2.00]);
    $miss($w[7], 3);
    $set($w[8], ['easiness_factor' => 1.50]);
    $set($w[9], ['easiness_factor' => 1.80]);
    $set($w[10], ['easiness_factor' => 2.20]);

    // Noise that must not get in: an inactive word, the hard word of another learner, a hard word of another city.
    $inactive = GameKit::word($lessons[0], $learner, 'inactiveword', 'неактивное');
    $inactive->update(['is_active' => false]);
    $set($inactive, ['is_hard' => true, 'lapses' => 9]);
    $other = Learner::factory()->create();
    $foreign = GameKit::word($lessons[0], $other, 'foreignword', 'чужое');
    WordRepetition::query()->where('learner_id', $other->id)->update(['is_hard' => true, 'lapses' => 9]);
    $city2 = Lesson::factory()->for(Stage::factory()->create(['course_id' => $stage->course_id, 'number' => 2]))->create(['number' => 1]);
    $elsewhere = GameKit::word($city2, $learner, 'otherCityWord', 'другой город');
    $set($elsewhere, ['is_hard' => true, 'lapses' => 9]);

    $expected = array_map(static fn (Word $word): int => $word->id, [$w[1], $w[0], $w[2], $w[3], $w[4], $w[5], $w[6], $w[7], $w[8], $w[9], $w[10], $w[11]]);

    expect(app(BossWordPicker::class)->pick($learner->id, $stage->id, 12))->toBe($expected)
        ->and(app(BossWordPicker::class)->pick($learner->id, $stage->id, 3))->toBe(array_slice($expected, 0, 3))
        ->and(app(BossWordPicker::class)->pick($learner->id, $stage->id, 0))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------------
// the start
// ---------------------------------------------------------------------------------------------------------------------------

it('starts the fight: 12 tasks of the hardest words, no flasks, no pairs, authored tasks or events, the set fixed in the snapshot', function (): void {
    [$learner, $course, $stage, , $words] = bossWorld([5, 5, 4]);
    WordRepetition::query()->where('word_id', $words[13]->id)->update(['is_hard' => true, 'lapses' => 4]);
    config(['game.events.chance' => 1.0]); // a stop would get an event; a boss never does

    $response = bossPost($learner, $course, $stage->id)->assertCreated()
        ->assertJsonPath('kind', 'boss')->assertJsonPath('status', 'active')->assertJsonPath('total', 12)
        ->assertJsonPath('flasks', 0)->assertJsonPath('flasks_max', 0)->assertJsonPath('stage_number', 1)->assertJsonPath('summary', null);
    $run = GameRun::query()->findOrFail($response->json('id'));

    $wordIds = array_map(static fn (array $e): int => (int) $e['word_id'], $run->snapshot['elements']);
    $kinds = array_unique(array_column($run->snapshot['elements'], 'kind'));
    expect($run->kind)->toBe(RunKind::Boss)
        ->and($run->stage_id)->toBe($stage->id)->and($run->lesson_id)->toBeNull()->and($run->flasks_max)->toBe(0)
        ->and($run->snapshot['events'])->toBe([])
        ->and($wordIds)->toContain($words[13]->id)
        ->and(count(array_unique($wordIds)))->toBe(12)
        ->and($kinds)->not->toContain('pairs_round', 'collocation', 'sentence', 'dictation');

    // The set is the snapshot's: what the learner does in the cards afterwards changes nothing.
    WordRepetition::query()->where('learner_id', $learner->id)->update(['is_hard' => false, 'lapses' => 0]);
    Word::query()->whereIn('id', $wordIds)->update(['is_active' => false]);
    expect(array_map(static fn (array $e): int => (int) $e['word_id'], $run->refresh()->snapshot['elements']))->toBe($wordIds);
    test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$run->id)->assertOk()->assertJsonPath('total', 12);
});

it('hands back the fight that is on when the same boss is asked for again, and refuses another run with 409', function (): void {
    [$learner, $course, $stage] = bossWorld();
    $id = bossPost($learner, $course, $stage->id)->assertCreated()->json('id');

    bossPost($learner, $course, $stage->id)->assertCreated()->assertJsonPath('id', $id);
    expect(GameRun::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('refuses a boss that is not ready with 409 boss_not_ready and the numbers the map shows', function (): void {
    [$learner, $course, $stage, $lessons] = bossWorld([3, 3, 3], cleared: false);

    // 9 words introduced (>= 8) but no stop cleared
    $error = bossPost($learner, $course, $stage->id)->assertConflict()->assertJsonPath('error.code', 'boss_not_ready');
    expect($error->json('error.details'))->toBe(['introduced' => 9, 'required' => 8, 'cleared_stops' => 0, 'required_stops' => 3]);
    $map = bossMap($learner, $course)->json('cities.0.boss');
    expect($map)->toMatchArray(['state' => 'not_ready', ...$error->json('error.details')]);

    // two stops cleared of three: still not
    foreach ([0, 1] as $i) {
        GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[$i]->id]);
    }
    bossPost($learner, $course, $stage->id)->assertConflict()->assertJsonPath('error.details.cleared_stops', 2);
    expect(bossMap($learner, $course)->json('cities.0.boss.state'))->toBe('not_ready');

    // the third: ready, on the map and for the start, by the one rule
    GameStopProgress::factory()->cleared(2)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[2]->id]);
    expect(bossMap($learner, $course)->json('cities.0.boss.state'))->toBe('ready');
    bossPost($learner, $course, $stage->id)->assertCreated();
    expect(GameRun::query()->count())->toBe(1);
});

it('is not ready on too few words even when every stop is cleared, and asks only for the stops the city has', function (): void {
    [$learner, $course, $stage] = bossWorld([3, 3]); // 6 words, two stops cleared (the rule asks min(3, 2))

    $error = bossPost($learner, $course, $stage->id)->assertConflict()->assertJsonPath('error.code', 'boss_not_ready');
    expect($error->json('error.details'))->toBe(['introduced' => 6, 'required' => 8, 'cleared_stops' => 2, 'required_stops' => 2])
        ->and(bossMap($learner, $course)->json('cities.0.boss'))->toMatchArray(['state' => 'not_ready', 'introduced' => 6, 'required_stops' => 2]);

    expect(GameRun::query()->count())->toBe(0);
});

it('fights a boss with fewer than 12 words when 8 or more are introduced', function (): void {
    [$learner, $course, $stage] = bossWorld([3, 3, 3]); // 9 words

    $run = bossStart($learner, $course, $stage);

    expect($run->total)->toBe(9)->and($run->snapshot['elements'])->toHaveCount(9);
});

it('answers 404 for a missing course or stage, 422 for a stage of another course and for a body without stage_id, 403 when the game is closed', function (): void {
    [$learner, $course, $stage] = bossWorld();
    [$otherCourse, $otherLesson] = PracticeKit::course('other-course');

    bossPost($learner, $course, 999999)->assertNotFound()->assertJsonPath('error.code', 'stage_not_found');
    bossPost($learner, $course, $otherLesson->stage_id)->assertStatus(422)->assertJsonPath('error.code', 'stage_not_in_course');
    expect(bossPost($learner, $otherCourse, $stage->id)->assertStatus(422)->json('error.code'))->toBe('stage_not_in_course');
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'boss', 'course' => 'nope', 'stage_id' => $stage->id])
        ->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'boss', 'course' => $course->slug])->assertStatus(422);
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'class_boss', 'course' => $course->slug, 'stage_id' => $stage->id])->assertStatus(422);

    config(['game.access' => 'off']);
    bossPost($learner, $course, $stage->id)->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
});

it('needs a boss whose words were deactivated to have 8 left even after it was beaten, and answers boss_not_ready', function (): void {
    [$learner, $course, $stage, , $words] = bossWorld([3, 3, 3]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $stage->id]);
    Word::query()->whereIn('id', array_map(static fn (Word $w): int => $w->id, array_slice($words, 0, 3)))->update(['is_active' => false]);

    bossPost($learner, $course, $stage->id)->assertConflict()->assertJsonPath('error.code', 'boss_not_ready');
});

// ---------------------------------------------------------------------------------------------------------------------------
// the result
// ---------------------------------------------------------------------------------------------------------------------------

it('wins at 80 percent: the first win pays 50, marks the city, unlocks game_first_boss, writes the event and says so in the summary', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld();
    $run = bossStart($learner, $course, $stage);

    $run = bossPlay($run, $learner, wrong: [3, 7]); // 10 of 12 = 83 %

    $summary = test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$run->id)->assertOk()->assertJsonPath('status', 'finished')->json('summary');
    expect($run->finish_reason->value)->toBe('completed')
        ->and($run->correct)->toBe(10)->and($run->wrong)->toBe(2)->and($run->stars)->toBe(3)->and($run->coins_awarded)->toBe(50)
        ->and($summary['boss'])->toBe(['accuracy' => 83, 'won' => true, 'first' => true])
        ->and($summary['stars'])->toBe(3)->and($summary['coins'])->toBe(50);

    $city = GameCityProgress::query()->where('learner_id', $learner->id)->where('stage_id', $stage->id)->firstOrFail();
    expect($city->boss_attempts)->toBe(1)->and($city->boss_wins)->toBe(1)->and($city->boss_best_accuracy)->toBe(83)->and($city->boss_defeated_at)->not->toBeNull()
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(50)
        ->and(GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->value('kind'))->toBe(LedgerKind::BossReward)
        ->and(DB_cities_cleared($learner))->toBe(1)
        ->and(Achievement::query()->where('learner_id', $learner->id)->where('key', 'game_first_boss')->count())->toBe(1);

    $defeated = ProductEvent::query()->where('name', ProductEventName::GameBossDefeated->value)->get();
    expect($defeated)->toHaveCount(1)
        ->and($defeated[0]->props)->toEqualCanonicalizing(['run_id' => $run->id, 'stage_id' => $stage->id, 'accuracy' => 83, 'first' => true]);

    // The map: the city fell.
    expect(bossMap($learner, $course)->json('cities.0.boss.state'))->toBe('defeated');
    // One card for the city; the achievement has no DM of its own.
    Queue::assertPushed(SendGameCardJob::class, 1);
    Queue::assertPushed(SendGameCardJob::class, fn (SendGameCardJob $job): bool => $job->learnerId === $learner->id && $job->stageId === $stage->id);
    Queue::assertNotPushed(SendAchievementJob::class);
});

function DB_cities_cleared(Learner $learner): int
{
    return (int) DB::table('game_profiles')->where('learner_id', $learner->id)->value('cities_cleared');
}

it('does not count a typo: 9 right and 2 typos of 12 is a defeat', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld();
    $run = bossStart($learner, $course, $stage);
    expect(count(array_filter($run->snapshot['elements'], static fn (array $e): bool => $e['kind'] === 'spell')))->toBeGreaterThanOrEqual(2);

    $run = bossPlay($run, $learner, wrong: [0], typos: 2); // 9 correct + 2 typos + 1 wrong

    expect($run->correct)->toBe(9)->and($run->typos)->toBe(2)->and($run->wrong)->toBe(1)
        ->and($run->stars)->toBe(0)->and($run->coins_awarded)->toBe(0);
    $summary = test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$run->id)->json('summary');
    expect($summary['boss'])->toBe(['accuracy' => 75, 'won' => false, 'first' => false]);
});

it('loses below 80 percent: no reward, no defeat, no achievement, no card, no boss_defeated; the attempt is counted and the boss can be tried again', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld();
    $run = bossPlay(bossStart($learner, $course, $stage), $learner, wrong: [1, 4, 8]); // 9 of 12 = 75 %

    expect($run->finish_reason->value)->toBe('completed')->and($run->correct)->toBe(9)->and($run->stars)->toBe(0)->and($run->coins_awarded)->toBe(0);
    $city = GameCityProgress::query()->where('learner_id', $learner->id)->firstOrFail();
    expect($city->boss_attempts)->toBe(1)->and($city->boss_wins)->toBe(0)->and($city->boss_best_accuracy)->toBe(75)->and($city->boss_defeated_at)->toBeNull()
        ->and(GameCoinLedgerEntry::query()->count())->toBe(0)
        ->and(Achievement::query()->count())->toBe(0)
        ->and(bossEvents(ProductEventName::GameBossDefeated->value))->toBe(0)
        ->and(bossMap($learner, $course)->json('cities.0.boss.state'))->toBe('ready');
    Queue::assertNotPushed(SendGameCardJob::class);

    // again, and the best accuracy only grows
    $again = bossPlay(bossStart($learner, $course, $stage), $learner, wrong: [2, 5]);
    expect($again->correct)->toBe(10);
    $city->refresh();
    expect($city->boss_attempts)->toBe(2)->and($city->boss_wins)->toBe(1)->and($city->boss_best_accuracy)->toBe(83)->and($city->boss_defeated_at)->not->toBeNull();
});

it('pays a repeated win 5, and neither the achievement nor the card nor first=true is repeated', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld();
    $first = bossPlay(bossStart($learner, $course, $stage), $learner);
    $second = bossPlay(bossStart($learner, $course, $stage), $learner, wrong: [6]);

    expect($first->coins_awarded)->toBe(50)->and($second->coins_awarded)->toBe(5)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(55)
        ->and(GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->count())->toBe(2)
        ->and(GameCityProgress::query()->where('learner_id', $learner->id)->value('boss_wins'))->toBe(2)
        ->and(DB_cities_cleared($learner))->toBe(1)
        ->and(Achievement::query()->where('key', 'game_first_boss')->count())->toBe(1)
        ->and(bossEvents(ProductEventName::GameBossDefeated->value))->toBe(2);

    $events = ProductEvent::query()->where('name', ProductEventName::GameBossDefeated->value)->orderBy('id')->get();
    expect($events[0]->props['first'])->toBeTrue()->and($events[1]->props['first'])->toBeFalse()->and($events[1]->props['accuracy'])->toBe(92);
    Queue::assertPushed(SendGameCardJob::class, 1);

    $summary = test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$second->id)->json('summary');
    expect($summary['boss'])->toBe(['accuracy' => 92, 'won' => true, 'first' => false]);
    // the first fight still remembers it was the first
    expect(test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$first->id)->json('summary.boss.first'))->toBeTrue();
});

it('fights a beaten boss again without the readiness check', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld([4, 4, 4]);
    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $stage->id]);
    GameStopProgress::query()->where('learner_id', $learner->id)->update(['stars' => 0]);

    $run = bossPlay(bossStart($learner, $course, $stage), $learner);

    expect($run->coins_awarded)->toBe(5);
    Queue::assertNotPushed(SendGameCardJob::class); // the city had fallen before
    expect(Achievement::query()->count())->toBe(0);
});

it('never calls an abandoned fight won, even with enough answers right', function (): void {
    [$learner, $course, $stage] = bossWorld();
    $run = bossStart($learner, $course, $stage);
    $runs = app(RunService::class);
    for ($i = 0; $i < 11; $i++) {
        $element = GameKit::current($run);
        $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0]);
    }

    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs/'.$run->id.'/abandon', ['confirm' => true])->assertOk()
        ->assertJsonPath('summary.boss', ['accuracy' => 92, 'won' => false, 'first' => false]);
    expect(GameCityProgress::query()->count())->toBe(0)->and(GameCoinLedgerEntry::query()->count())->toBe(0);
});

it('gives other kinds of runs no boss summary', function (): void {
    [$learner, $course, , $lessons] = bossWorld([8, 4, 4]);
    $runs = app(RunService::class);
    $run = GameKit::play($runs, $runs->start($learner, $course->slug, $lessons[0]->id), $learner);

    expect(test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/runs/'.$run->id)->json('summary.boss'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------------
// the card
// ---------------------------------------------------------------------------------------------------------------------------

it('sends the card "I got to {city}" once for the first win: the picture, the caption in the language, the share button', function (): void {
    $tg = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $tg);
    [$learner, $course, $stage] = bossWorld();
    $learner->update(['interface_locale' => 'ru']);
    Stage::query()->whereKey($stage->id)->update(['number' => 3]);

    bossPlay(bossStart($learner, $course, $stage), $learner); // the queue is sync: the job runs after the commit

    expect($tg->photos)->toHaveCount(1);
    $photo = $tg->photos[0];
    $link = 'https://t.me/LexiFlowBot?start=ref_'.$learner->fresh()?->referral_code;
    expect($photo['chat_id'])->toBe($learner->telegram_user_id)
        ->and($photo['size'])->toBeGreaterThan(8_000)
        ->and(basename($photo['path']))->toStartWith('boss_3-ru-')
        ->and($photo['caption'])->toContain('Я дошёл до Самарканда')
        ->and($photo['markup']['inline_keyboard'][0][0]['text'])->toBe('Поделиться')
        ->and($photo['markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/share/url?url='.rawurlencode($link).'&text='.rawurlencode('Я дошёл до Самарканда в игре «Караван» — LexiFlow! Учим английский в Telegram.'))
        ->and(NotificationLog::query()->where('type', GameCardSender::NOTIFICATION_TYPE)->where('payload->key', 'boss:'.$stage->id)->count())->toBe(1);

    // a second win over the same boss: no second card
    bossPlay(bossStart($learner, $course, $stage), $learner);
    expect($tg->photos)->toHaveCount(1);

    // and the job itself, run again (a retry, a duplicate): skipped
    expect(app(GameCardSender::class)->sendBossCard($learner->id, $stage->id))->toBe('skipped')->and($tg->photos)->toHaveCount(1);
});

it('speaks Uzbek on the card and names the city as it is', function (): void {
    $tg = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $tg);
    [$learner, , $stage] = bossWorld();
    $learner->update(['interface_locale' => 'uz']);

    expect(app(GameCardSender::class)->sendBossCard($learner->id, $stage->id))->toBe('sent');

    expect($tg->photos[0]['caption'])->toContain('Men yetib keldim: Toshkent')
        ->and($tg->photos[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('Ulashish')
        ->and(basename($tg->photos[0]['path']))->toStartWith('boss_1-uz-');
});

it('skips a blocked learner and one whose DMs are closed, and takes back the mark on a Telegram 403', function (): void {
    $tg = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $tg);
    [$learner, , $stage] = bossWorld();

    $learner->forceFill(['blocked_at' => now()])->save();
    expect(app(GameCardSender::class)->sendBossCard($learner->id, $stage->id))->toBe('skipped');
    $learner->forceFill(['blocked_at' => null, 'dm_blocked_at' => now()])->save();
    expect(app(GameCardSender::class)->sendBossCard($learner->id, $stage->id))->toBe('skipped')
        ->and(app(GameCardSender::class)->sendBossCard($learner->id, 999999))->toBe('skipped')
        ->and($tg->photos)->toBe([])->and(NotificationLog::query()->count())->toBe(0);

    $learner->forceFill(['dm_blocked_at' => null])->save();
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendPhoto')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    app()->instance(TelegramClient::class, $telegram);
    expect(app(GameCardSender::class)->sendBossCard($learner->id, $stage->id))->toBe('forbidden')
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and($learner->fresh()?->dm_blocked_at)->not->toBeNull();
});

it('does not fail the finish when the card cannot be queued or sent: the win is paid and recorded anyway', function (): void {
    Queue::fake();
    [$learner, $course, $stage] = bossWorld();
    app()->instance(AchievementService::class, new class(app(ProductEventRecorder::class), app(LearnerStatsService::class), app(ReferralService::class)) extends AchievementService
    {
        public function onGameBossDefeated(Learner $learner, int $stageId, CarbonImmutable $now): ?Achievement
        {
            throw new RuntimeException('boom');
        }
    });

    $run = bossPlay(bossStart($learner, $course, $stage), $learner);

    expect($run->coins_awarded)->toBe(50)->and($run->isActive())->toBeFalse()
        ->and(GameCityProgress::query()->value('boss_defeated_at'))->not->toBeNull();
    Queue::assertPushed(SendGameCardJob::class, 1); // the next hook is independent of the failed one
});

it('is the same fight for the service as for the route', function (): void {
    [$learner, $course, $stage] = bossWorld();

    $run = app(BossService::class)->start($learner, $course->slug, $stage->id);

    expect($run->kind)->toBe(RunKind::Boss)->and($run->total)->toBe(12);
});
