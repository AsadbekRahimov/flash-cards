<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\TaskGrade;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\Tasks\TaskHandler;
use App\Domain\Game\Services\Tasks\WordTaskHandler;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Database\Seeders\GameItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\ClassKit;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.8, docs/04 A.18: the shape of every answer of the routes of a run - exact keys, at every kind of element - and the keys of
 * the snapshot that must not appear anywhere in a run (the right answers, the ids of the database, the audio path, the seed). The review
 * of an ANSWER (`reveal`) has the right answer by right and is not looked at for them.
 */

uses(RefreshDatabase::class);

/** The keys that live in the snapshot only: not in a run at any depth (docs/18 §12.3.2). */
const CONTRACT_FORBIDDEN_GAME_KEYS = [
    'correct_option_id', 'expected', 'pairs', 'correct_ids', 'accepted', 'accepted_display', 'audio_path', 'audio_disk', 'word_id',
    'version_id', 'item_id', 'target_word_ids', 'word', 'correct_text', 'full_text', 'display', 'seed', 'pool',
];

const CONTRACT_ALL_KINDS = ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap', 'pairs_round', 'collocation', 'sentence', 'dictation'];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Every string key at any depth. @return list<string> */
function gameContractKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...gameContractKeys($item)];
    }

    return $keys;
}

/** @return list<string> the forbidden keys found in the value */
function gameContractLeaks(mixed $value): array
{
    return array_values(array_intersect(gameContractKeys($value), CONTRACT_FORBIDDEN_GAME_KEYS));
}

/** The structure of the current element by its kind (what the client may read). @return list<mixed> */
function gameContractElement(string $kind): array
{
    $options = ['*' => ['id', 'text']];

    return match ($kind) {
        'translate_fwd' => ['id', 'kind', 'prompt', 'audio', 'options' => $options],
        'translate_back' => ['id', 'kind', 'prompt', 'options' => $options],
        'listen' => ['id', 'kind', 'audio', 'options' => $options, 'audio_url'],
        'spell' => ['id', 'kind', 'prompt', 'answer_length'],
        'gap' => ['id', 'kind', 'masked', 'translation'],
        'collocation' => ['id', 'kind', 'text', 'options' => $options],
        'sentence' => ['id', 'kind', 'prompt', 'fragments' => $options],
        'pairs_round' => ['id', 'kind', 'round', 'rounds', 'left' => $options, 'right' => $options, 'matched', 'mismatches'],
        'dictation' => ['id', 'kind', 'source', 'audio_url', 'words', 'replacements_left'],
    };
}

/** The structure of a run; `$kind` = the kind of its current element, null = it has none (the run is over). @return list<mixed> */
function gameContractRun(?string $kind, bool $summary = false): array
{
    // A key that is null is listed as a plain name: an empty structure for a null would be compared with the parent.
    $run = [
        'id', 'kind', 'status', 'finish_reason', 'city', 'stage_number', 'lesson_number', 'lesson_title', 'scope_widened', 'total', 'position',
        'flasks', 'flasks_max', 'combo', 'score', 'boosters' => ['available', 'used'], 'hint' => ['price', 'available'], 'server_now',
    ];
    $kind === null ? $run[] = 'current' : $run['current'] = ['element' => gameContractElement($kind), 'event'];
    $summary ? $run['summary'] = ['stars', 'score', 'coins', 'league_points', 'srs_pulled', 'mistakes' => ['*' => ['element_id', 'kind', 'label', 'your_answer', 'verdict']], 'boss'] : $run[] = 'summary';

    return $run;
}

/** The block `limits` (the map, the start): the one of `/me.game.limits`; a limit that does not apply is a plain `null`. @return list<mixed> */
function gameContractLimits(bool $limited = false): array
{
    $block = ['limit', 'used', 'remaining', 'resets_at'];

    return $limited ? ['runs' => $block, 'tickets' => ['balance', 'max'], 'boosters' => $block, 'boss_stages'] : ['runs', 'tickets' => ['balance', 'max'], 'boosters', 'boss_stages'];
}

function gameContractGet(Learner $learner, int $id): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/runs/{$id}");
}

/** @param array<string, mixed> $answer */
function gameContractAnswer(Learner $learner, int $id, string $elementId, array $answer): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$id}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer,
    ]);
}

it('keeps the exact shape of a run and of an answer for each of the nine kinds of element, and no key of the snapshot in a run', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $headers = PracticeKit::headers($learner);
    $seen = [];

    for ($round = 0; $round < 30 && count($seen) < count(CONTRACT_ALL_KINDS); $round++) {
        $started = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated();
        $id = $started->json('id');

        for ($guard = 0; $guard < 40; $guard++) {
            $run = GameRun::query()->findOrFail($id);
            if (! $run->isActive()) {
                break;
            }
            $element = GameKit::current($run);
            $kind = $element['kind'];
            $seen[$kind] = true;

            // The read of the run: the exact shape with this kind of element in it; the start answered the same shape for the first one.
            $read = gameContractGet($learner, $id)->assertOk()->assertExactJsonStructure(gameContractRun($kind));
            if ($guard === 0) {
                $started->assertExactJsonStructure([...gameContractRun($kind), 'limits' => gameContractLimits()]);
            }
            expect(gameContractLeaks($read->json()))->toBe([])
                ->and($read->json('current.element.kind'))->toBe($kind)
                ->and($read->json('server_now'))->toBe('2026-11-05T10:00:00.000Z');

            // The answer: the body of the attempt and the run of now; a round of pairs says which pair and whether the round is over.
            foreach (GameKit::rightAnswers($element) as $position => $answer) {
                $response = gameContractAnswer($learner, $id, $element['id'], $answer)->assertOk();
                $isLast = $position === count(GameKit::rightAnswers($element)) - 1;
                $nextKind = $isLast ? (GameKit::current($run->refresh())['kind'] ?? null) : $kind;
                $finished = $isLast && ! $run->refresh()->isActive();
                $structure = ['verdict', 'points', 'reveal', 'flask_lost', 'combo', 'event_applied', 'run' => gameContractRun($finished ? null : $nextKind, summary: $finished)];
                if ($kind === 'pairs_round') {
                    $structure = ['verdict', 'points', 'reveal', 'flask_lost', 'combo', 'event_applied', 'pair', 'round_done', 'run' => $structure['run']];
                }
                $response->assertExactJsonStructure($structure);
                if ($kind === 'pairs_round' && ! $isLast) {
                    // The round is not over: the run still shows the same element, with the pair in `matched`.
                    expect($response->json('run.current.element.id'))->toBe($element['id']);
                }
                expect(gameContractLeaks($response->json('run')))->toBe([])
                    ->and($response->json('verdict'))->toBe('correct');
            }
            Cache::flush(); // `twa-api` is 60 requests a minute, and the clock of the test is frozen
        }
    }

    expect(array_values(array_diff(CONTRACT_ALL_KINDS, array_keys($seen))))->toBe([]);
});

it('keeps the exact shape of the review of an answer for the five kinds made of words, the right answer in it', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $reveals = [
        'translate_fwd' => ['correct_id', 'correct_text', 'word', 'translation'],
        'translate_back' => ['correct_id', 'correct_text', 'word', 'translation'],
        'listen' => ['correct_id', 'correct_text', 'word', 'translation'],
        'spell' => ['correct_text', 'translation'],
        'gap' => ['correct_text', 'full_text', 'translation'],
    ];
    $seen = [];
    $wrongSeen = [];

    for ($round = 0; $round < 40 && (count($seen) < count($reveals) || count($wrongSeen) < count($reveals)); $round++) {
        $id = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated()->json('id');
        $wrongs = 0;
        for ($guard = 0; $guard < 40; $guard++) {
            $run = GameRun::query()->findOrFail($id);
            if (! $run->isActive()) {
                break;
            }
            $element = GameKit::current($run);
            $kind = $element['kind'];
            if (isset($reveals[$kind]) && ! isset($wrongSeen[$kind]) && $wrongs < 2) {
                // A wrong answer shows the same review as a right one, and costs a flask (two a run at most: the run stays on).
                $wrongs++;
                $wrongSeen[$kind] = true;
                $answer = gameContractAnswer($learner, $id, $element['id'], GameKit::wrongAnswer($element))->assertOk();
                expect($answer->json('verdict'))->toBe('wrong')->and($answer->json('flask_lost'))->toBeTrue();
            } else {
                $answer = gameContractAnswer($learner, $id, $element['id'], GameKit::rightAnswers($element)[0])->assertOk();
            }
            if (isset($reveals[$kind])) {
                $seen[$kind] = true;
                expect(collect($answer->json('reveal'))->keys()->sort()->values()->all())->toBe(collect($reveals[$kind])->sort()->values()->all());
            } elseif ($kind === 'pairs_round') {
                // The rest of the round, so that the next element comes.
                foreach (array_slice(GameKit::rightAnswers($element), 1) as $match) {
                    gameContractAnswer($learner, $id, $element['id'], $match)->assertOk();
                }
            }
            Cache::flush();
        }
    }

    expect(array_keys($seen))->toHaveCount(count($reveals))->and(array_keys($wrongSeen))->toHaveCount(count($reveals));
});

it('keeps the exact shape of the hint, of the finished run with its summary, of the abandoned run and of the errors with their details', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameWallet::factory()->withBalance(9)->create(['learner_id' => $learner->id]);
    $headers = PracticeKit::headers($learner);
    $id = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');
    $run = GameRun::query()->findOrFail($id);
    $first = GameKit::current($run);
    $kind = $first['kind'];

    // The hint: the hint itself (the options to remove, or the letters), the coins left, the run with the hint in its element.
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 0, 'type' => RunEvent::Bazaar->value]]]])->save();
    $hint = test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/hint", ['request_id' => (string) Str::uuid(), 'element_id' => $first['id']])->assertOk();
    $runWithHint = gameContractRun($kind);
    $runWithHint['current'] = ['element' => [...gameContractElement($kind), 'hint'], 'event'];
    $hint->assertExactJsonStructure(['hint', 'coins' => ['balance'], 'run' => $runWithHint]);
    expect(array_keys($hint->json('hint')))->toBe(in_array($kind, ['spell', 'gap'], true) ? ['prefix'] : ['options_removed'])
        ->and($hint->json('coins'))->toBe(['balance' => 4])
        ->and($hint->json('run.current.event'))->toBe('bazaar')
        ->and(gameContractLeaks($hint->json('run')))->toBe([]);

    // The errors: the envelope, and `details` exactly where the document puts them.
    $elements = $run->snapshot['elements'];
    gameContractAnswer($learner, $id, $elements[2]['id'], ['skip' => true])->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['current']]]);
    gameContractGet(Learner::factory()->create(), $id)->assertStatus(404)->assertExactJsonStructure(['error' => ['code', 'message']]);
    test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id + 99])->assertStatus(404)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);

    // A run lost on three mistakes: finished, no current element, the summary with the mistakes - and no key of the snapshot in it.
    $last = null;
    for ($guard = 0; $guard < 30 && GameRun::query()->findOrFail($id)->isActive(); $guard++) {
        $element = GameKit::current(GameRun::query()->findOrFail($id));
        $last = gameContractAnswer($learner, $id, $element['id'], GameKit::wrongAnswer($element))->assertOk();
        Cache::flush();
    }
    $last?->assertExactJsonStructure(['verdict', 'points', 'reveal', 'flask_lost', 'combo', 'event_applied', 'run' => gameContractRun(null, summary: true)]);
    $done = gameContractGet($learner, $id)->assertOk()->assertExactJsonStructure(gameContractRun(null, summary: true));
    expect($done->json('status'))->toBe('finished')->and($done->json('finish_reason'))->toBe('failed')
        ->and(gameContractLeaks($done->json()))->toBe([])
        ->and($done->json('summary.mistakes'))->not->toBe([]);

    // gone: 410 with the reason; abandoned: the run with its summary and no element.
    gameContractAnswer($learner, $id, $first['id'], ['skip' => true])->assertStatus(410)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['finish_reason']]]);
    $again = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$again}/abandon", ['confirm' => true])->assertOk()
        ->assertExactJsonStructure(gameContractRun(null, summary: true))->assertJsonPath('finish_reason', 'abandoned');
});

it('cleans a key of the snapshot out of the current element even when a handler lets it through', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');

    $leaky = new class(app(WordTaskHandler::class)) implements TaskHandler
    {
        public function __construct(private readonly WordTaskHandler $inner) {}

        public function kinds(): array
        {
            return $this->inner->kinds();
        }

        public function project(SnapshotElement $element, string $locale, RunState $state): array
        {
            return [...$this->inner->project($element, $locale, $state), 'correct_option_id' => 'o1', 'word_id' => 5, 'nested' => ['expected' => 'x', 'audio_path' => 'p', 'keep' => 1]];
        }

        public function grade(SnapshotElement $element, array $answer, RunState $state): TaskGrade
        {
            return $this->inner->grade($element, $answer, $state);
        }

        public function reveal(SnapshotElement $element, string $locale, RunState $state): array
        {
            return $this->inner->reveal($element, $locale, $state);
        }

        public function mistakeLabel(SnapshotElement $element, RunState $state, string $locale): array
        {
            return $this->inner->mistakeLabel($element, $state, $locale);
        }

        public function hint(SnapshotElement $element, RunState $state): ?array
        {
            return $this->inner->hint($element, $state);
        }

        public function wordIds(SnapshotElement $element): array
        {
            return $this->inner->wordIds($element);
        }
    };
    // The first element of a run is a warm-up made of a word.
    expect(GameKit::current(GameRun::query()->findOrFail($id))['kind'])->toBeIn(['translate_fwd', 'translate_back', 'listen', 'spell', 'gap']);
    app()->instance(WordTaskHandler::class, $leaky);

    $read = gameContractGet($learner, $id)->assertOk();

    expect(gameContractLeaks($read->json()))->toBe([])
        ->and($read->json('current.element.nested'))->toBe(['keep' => 1]);
});

it('keeps the exact shape of the wallet, the shop, the profile, the purchase and the equip, with their errors, and no id of the database in them (G2.5b)', function (): void {
    (new GameItemSeeder)->run();
    $learner = Learner::factory()->create();
    DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 300, LedgerKind::RunReward, "run:{$learner->id}:1", 'game_run', 1));
    $headers = PracticeKit::headers($learner);
    $wallet = ['balance', 'earned_total', 'spent_total', 'tickets' => ['balance', 'max'], 'ledger' => ['*' => ['amount', 'kind', 'created_at', 'title']]];
    $profile = ['equipped', 'stops_cleared', 'cities_cleared', 'coins', 'tickets'];
    $leaks = ['id', 'item_id', 'learner_id', 'achievement_key', 'season_id', 'status', 'sort', ...CONTRACT_FORBIDDEN_GAME_KEYS];

    $shop = test()->withHeaders($headers)->getJson('/api/twa/game/shop')->assertOk()
        ->assertExactJsonStructure(['items' => ['*' => ['key', 'kind', 'slot', 'effect', 'price', 'availability', 'owned', 'qty', 'equipped', 'locked_reason', 'title', 'description', 'asset']], 'balance']);
    $read = test()->withHeaders($headers)->getJson('/api/twa/game/wallet')->assertOk()->assertExactJsonStructure($wallet);
    $bought = test()->withHeaders($headers)->postJson('/api/twa/game/shop/camel_bactrian/buy', ['request_id' => (string) Str::uuid()])->assertOk()->assertExactJsonStructure($wallet);
    $worn = test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'camel', 'item_key' => 'camel_bactrian'])->assertOk()
        ->assertExactJsonStructure(['equipped' => ['camel' => ['key', 'asset']], 'stops_cleared', 'cities_cleared', 'coins', 'tickets']);
    $empty = test()->withHeaders($headers)->getJson('/api/twa/game/profile')->assertOk();
    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'camel', 'item_key' => null])->assertOk()->assertExactJsonStructure($profile);

    foreach ([$shop, $read, $bought, $worn, $empty] as $response) {
        expect(array_values(array_intersect(gameContractKeys($response->json()), $leaks)))->toBe([]);
    }
    // the kinds of the values the client relies on
    expect($shop->json('items.0.price'))->toBeInt()->and($shop->json('items.0.owned'))->toBeBool()->and($shop->json('items.0.locked_reason'))->toBeNull()
        ->and($read->json('ledger.0.created_at'))->toBe(CarbonImmutable::now()->utc()->toIso8601ZuluString('millisecond'));

    // the errors: the envelope, and `details` exactly where the document puts them
    test()->withHeaders($headers)->postJson('/api/twa/game/shop/camel_golden/buy', ['request_id' => (string) Str::uuid()])->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['reason']]]);
    test()->withHeaders($headers)->postJson('/api/twa/game/shop/camel_bactrian/buy', ['request_id' => (string) Str::uuid()])->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
    test()->withHeaders($headers)->postJson('/api/twa/game/shop/carpet_bukhara/buy', ['request_id' => (string) Str::uuid()])->assertOk();
    test()->withHeaders($headers)->postJson('/api/twa/game/shop/tent_silk/buy', ['request_id' => (string) Str::uuid()])->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['balance', 'price']]]);
    test()->withHeaders($headers)->postJson('/api/twa/game/shop/nope/buy', ['request_id' => (string) Str::uuid()])->assertStatus(404)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'tent', 'item_key' => 'tent_silk'])->assertStatus(404)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
});

it('keeps the exact shape of the caravans of the class, of their card on the map and on /today, and of their errors; no id of a classmate, no secret of the boss (G2.9b)', function (): void {
    $world = ClassKit::world(2);
    [$me, $mate] = $world['learners'];
    $mate->forceFill(['username' => 'mate_nick'])->save();
    $caravan = ClassCaravan::factory()->create(['telegram_group_id' => $world['group']->id, 'course_id' => $world['course']->id, 'boss_snapshot_words' => [101, 102, 103], 'boss_unlocked_at' => now()]);
    $finished = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $world['group']->id, 'course_id' => $world['course']->id, 'boss_snapshot_words' => [101, 102, 103]]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $me->id, 'stars' => 3, 'runs' => 1]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $mate->id, 'stars' => 2, 'runs' => 1]);
    $headers = PracticeKit::headers($me);
    $row = ['id', 'group_title', 'course' => ['slug', 'title'], 'status', 'starts_at', 'ends_at', 'stars_total', 'target_stars', 'runs_total', 'boss' => ['unlocked', 'defeated', 'my_attempt']];
    $card = ['id', 'stars_total', 'target_stars', 'ends_at'];
    $leaks = ['learner_id', 'telegram_user_id', 'username', 'boss_snapshot_words', 'group_id', 'telegram_group_id', 'organization_id', 'course_id', 'meta', 'started_by_user_id', ...CONTRACT_FORBIDDEN_GAME_KEYS];

    $list = test()->withHeaders($headers)->getJson('/api/twa/game/class')->assertOk()->assertExactJsonStructure(['caravans' => ['*' => $row]]);
    $active = test()->withHeaders($headers)->getJson("/api/twa/game/class/{$caravan->id}")->assertOk()->assertExactJsonStructure([
        ...$row,
        'members' => ['*' => ['name', 'stars', 'runs', 'best_score', 'boss_won', 'is_me']],
        'my' => ['stars', 'runs', 'best_score', 'boss_won'],
        'summary',
    ]);
    $over = test()->withHeaders($headers)->getJson("/api/twa/game/class/{$finished->id}")->assertOk()->assertExactJsonStructure([
        ...$row,
        'members',
        'my',
        'summary' => ['target_reached', 'boss_defeated', 'finished_at'],
    ]);
    PracticeKit::course('silk');
    $mapJson = test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk()->assertJsonStructure(['class_caravan' => $card]);
    $today = test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk()->assertJsonStructure(['game' => ['daily_available', 'active_run', 'class_caravan' => $card]]);

    foreach ([$list, $active, $over, $mapJson, $today] as $response) {
        expect(array_values(array_intersect(gameContractKeys($response->json()), $leaks)))->toBe([]);
    }
    expect($mapJson->json('class_caravan'))->toHaveCount(4)->and($today->json('game.class_caravan'))->toHaveCount(4)
        ->and($active->json('boss.unlocked'))->toBeTrue()->and($active->json('members.0.stars'))->toBeInt()
        ->and($active->json('starts_at'))->toBe('2026-11-05T10:00:00.000Z')
        ->and(json_encode($active->json()))->not->toContain('mate_nick');

    test()->withHeaders(PracticeKit::headers(Learner::factory()->create()))->getJson("/api/twa/game/class/{$caravan->id}")->assertNotFound()
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
    config(['game.access' => 'off']);
    test()->withHeaders($headers)->getJson('/api/twa/game/class')->assertForbidden()->assertExactJsonStructure(['error' => ['code', 'message']]);
});

it('keeps the exact shape of the booster, of the boosters block of a run, and of the limits of the map and of the start (G2.5b part 2)', function (): void {
    GameKit::limits(2, 1, 1);
    (new GameItemSeeder)->run();
    [$learner, $course, $lesson] = GameKit::richStop();
    foreach (['booster_hint', 'booster_extra_flask'] as $key) {
        GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => GameItem::query()->where('key', $key)->value('id'), 'qty' => 2]);
    }
    $headers = PracticeKit::headers($learner);

    // The start: the run and, beside it, the limits after this start (one run of two used).
    $started = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated();
    $id = $started->json('id');
    $available = [['key' => 'booster_hint', 'effect' => 'hint', 'qty' => 2], ['key' => 'booster_extra_flask', 'effect' => 'extra_flask', 'qty' => 2]];
    expect(array_keys($started->json('limits')))->toBe(['runs', 'tickets', 'boosters', 'boss_stages'])
        ->and(array_keys($started->json('limits.runs')))->toBe(['limit', 'used', 'remaining', 'resets_at'])
        ->and(array_keys($started->json('limits.boosters')))->toBe(['limit', 'used', 'remaining', 'resets_at'])
        ->and(array_keys($started->json('limits.tickets')))->toBe(['balance', 'max'])
        ->and($started->json('limits.runs.used'))->toBe(1)->and($started->json('limits.runs.remaining'))->toBe(1)
        ->and($started->json('limits.boosters.used'))->toBe(0)->and($started->json('limits.boss_stages'))->toBe(1)
        ->and($started->json('boosters'))->toBe(['available' => $available, 'used' => []])
        ->and(gameContractLeaks($started->json()))->toBe([]);
    $runKeys = array_values(array_diff(array_keys($started->json()), ['limits']));

    // A read of the run has no `limits` (it is not a creation).
    expect(array_keys(gameContractGet($learner, $id)->assertOk()->json()))->toBe($runKeys);

    // The booster answers the run itself (the keys of a run, `limits` not among them).
    Cache::flush();
    $answer = test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => 'booster_extra_flask'])->assertOk();
    expect(array_keys($answer->json()))->toBe($runKeys)
        ->and($answer->json('boosters'))->toBe([
            'available' => [['key' => 'booster_hint', 'effect' => 'hint', 'qty' => 2], ['key' => 'booster_extra_flask', 'effect' => 'extra_flask', 'qty' => 1]],
            'used' => [['key' => 'booster_extra_flask', 'effect' => 'extra_flask', 'element_id' => null]],
        ])
        ->and(gameContractLeaks($answer->json()))->toBe([]);

    // The hint is inside the current element; the weekly limit (1) is used up now.
    $run = GameRun::query()->findOrFail($id);
    $state = $run->state;
    $state['current'] = (int) array_search('translate_fwd', array_column($run->snapshot['elements'], 'kind'), true);
    $run->forceFill(['state' => $state])->save();
    Cache::flush();
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => 'booster_hint'])->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['limit', 'used', 'resets_at']]]);
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => 'booster_nothing'])->assertStatus(404)
        ->assertExactJsonStructure(['error' => ['code', 'message']]);

    // The map carries the same block, with the numbers of now: the booster of the week is used.
    Cache::flush();
    $map = test()->withHeaders($headers)->getJson("/api/twa/game/map?course={$course->slug}")->assertOk();
    expect($map->json('limits.runs'))->toBe($started->json('limits.runs'))
        ->and($map->json('limits.boosters.used'))->toBe(1)->and($map->json('limits.boosters.remaining'))->toBe(0)
        ->and($map->json('limits.tickets'))->toBe($started->json('limits.tickets'));
});

it('puts the hint of a booster inside the current element and nowhere else', function (): void {
    GameKit::limits(null, null, null);
    (new GameItemSeeder)->run();
    [$learner, $course, $lesson] = GameKit::richStop();
    GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => GameItem::query()->where('key', 'booster_hint')->value('id'), 'qty' => 1]);
    $headers = PracticeKit::headers($learner);
    $id = test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json('id');
    $run = GameRun::query()->findOrFail($id);
    $state = $run->state;
    $state['current'] = (int) array_search('translate_fwd', array_column($run->snapshot['elements'], 'kind'), true);
    $run->forceFill(['state' => $state])->save();

    $hinted = test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/booster", ['request_id' => (string) Str::uuid(), 'item_key' => 'booster_hint'])->assertOk();

    expect($hinted->json('current.element.hint.options_removed'))->toHaveCount(2)
        ->and($hinted->json('boosters.used.0.element_id'))->toBe($hinted->json('current.element.id'))
        ->and(gameContractLeaks($hinted->json()))->toBe([]);
});

it('keeps the shape of the start of a boss, of its run without flasks and of the summary.boss of a won, a lost and an abandoned fight; and of its errors', function (): void {
    [$course, $first] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $lessons = [$first, Lesson::factory()->for($first->stage)->create(['number' => 2]), Lesson::factory()->for($first->stage)->create(['number' => 3])];
    foreach ($lessons as $l => $lesson) {
        for ($i = 1; $i <= 4; $i++) {
            GameKit::word($lesson, $learner, 'bosscontract'.$l.'x'.$i, "перевод {$l}.{$i}");
        }
    }
    $headers = PracticeKit::headers($learner);
    $body = ['kind' => 'boss', 'course' => $course->slug, 'stage_id' => $first->stage_id];

    // not ready: the envelope with its four numbers
    test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertStatus(409)
        ->assertExactJsonStructure(['error' => ['code', 'message', 'details' => ['introduced', 'required', 'cleared_stops', 'required_stops']]]);
    foreach ($lessons as $lesson) {
        GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lesson->id]);
    }
    test()->withHeaders($headers)->postJson('/api/twa/game/runs', [...$body, 'stage_id' => 999999])->assertStatus(404)->assertExactJsonStructure(['error' => ['code', 'message']]);

    // the start: a run as any other, with no flasks, and no summary yet
    $started = test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated();
    $kind = GameKit::current(GameRun::query()->findOrFail($started->json('id')))['kind'];
    $started->assertExactJsonStructure([...gameContractRun($kind), 'limits' => gameContractLimits()]);
    expect($started->json('kind'))->toBe('boss')->and($started->json('flasks_max'))->toBe(0)->and($started->json('total'))->toBe(12)
        ->and(gameContractLeaks($started->json()))->toBe([]);
    $id = $started->json('id');

    // played to a win: the summary with the boss
    for ($guard = 0; $guard < 30 && GameRun::query()->findOrFail($id)->isActive(); $guard++) {
        $element = GameKit::current(GameRun::query()->findOrFail($id));
        $last = gameContractAnswer($learner, $id, $element['id'], GameKit::rightAnswers($element)[0])->assertOk();
        Cache::flush();
    }
    $summary = ['stars', 'score', 'coins', 'league_points', 'srs_pulled', 'mistakes', 'boss' => ['accuracy', 'won', 'first']];
    $last->assertExactJsonStructure(['verdict', 'points', 'reveal', 'flask_lost', 'combo', 'event_applied', 'run' => [...array_slice(gameContractRun(null), 0, -1), 'summary' => $summary]]);
    $done = gameContractGet($learner, $id)->assertOk();
    expect($done->json('summary.boss'))->toBe(['accuracy' => 100, 'won' => true, 'first' => true])
        ->and($done->json('summary.stars'))->toBe(3)->and($done->json('summary.coins'))->toBe(50)->and($done->json('flasks'))->toBe(0);

    // abandoned: the same shape, never won
    $again = test()->withHeaders($headers)->postJson('/api/twa/game/runs', $body)->assertCreated()->json('id');
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$again}/abandon", ['confirm' => true])->assertOk()
        ->assertJsonPath('summary.boss', ['accuracy' => 0, 'won' => false, 'first' => false]);
});
