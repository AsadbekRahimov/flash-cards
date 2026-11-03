<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\TaskGrade;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Services\Tasks\TaskHandler;
use App\Domain\Game\Services\Tasks\WordTaskHandler;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Models\GameRun;
use App\Models\GameWallet;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    for ($round = 0; $round < 15 && count($seen) < count(CONTRACT_ALL_KINDS); $round++) {
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
                $started->assertExactJsonStructure(gameContractRun($kind));
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

    for ($round = 0; $round < 15 && count($seen) < count($reveals); $round++) {
        $id = test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'replace_active' => true])->assertCreated()->json('id');
        for ($guard = 0; $guard < 40; $guard++) {
            $run = GameRun::query()->findOrFail($id);
            if (! $run->isActive()) {
                break;
            }
            $element = GameKit::current($run);
            // A wrong answer shows the same review as a right one, and costs a flask.
            $wrong = $element['kind'] === 'pairs_round' ? GameKit::rightAnswers($element)[0] : GameKit::wrongAnswer($element);
            $answer = gameContractAnswer($learner, $id, $element['id'], $wrong)->assertOk();
            if (isset($reveals[$element['kind']])) {
                $seen[$element['kind']] = true;
                $answer->assertJsonStructure(['reveal' => $reveals[$element['kind']]]);
                expect(collect($answer->json('reveal'))->keys()->sort()->values()->all())->toBe(collect($reveals[$element['kind']])->sort()->values()->all())
                    ->and($answer->json('verdict'))->toBe('wrong')->and($answer->json('flask_lost'))->toBeTrue();
            }
            Cache::flush();
            if (! GameRun::query()->findOrFail($id)->isActive() || isset($seen[$element['kind']])) {
                break;
            }
        }
    }

    expect(array_keys($seen))->toHaveCount(count($reveals));
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
