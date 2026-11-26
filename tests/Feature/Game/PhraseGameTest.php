<?php

declare(strict_types=1);

/*
 * F4 docs/35 §3.3 e: the caravan on phrases - no `spell` for a phrase (typing it whole is not asked), `gap` is the phrase itself with
 * its key word blank and that word alone expected, `translate_*` and `listen` as for a word with phrases for options; the composition
 * is a function of the pool and the seed; a repeated request credits nothing; the start costs no statement more than for words.
 */

use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\DTO\RunPool;
use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Game\Services\Runs\RunSnapshotBuilder;
use App\Domain\Game\Services\Tasks\WordTaskHandler;
use App\Domain\Game\Support\RunComposer;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\ScoreCredit;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\PhraseKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A phrase of a pool (or a word, with `$phrase` false): an example with the text, a translation of its own. */
function pgPoolWord(int $id, string $text, ?string $gap, bool $phrase = true, int $tier = RunPoolWord::TIER_POOL): RunPoolWord
{
    $material = new MaterialWord($id, 1, $text, "смысл {$id}", null, ['ru' => "смысл {$id}"], "Example: {$text} indeed.", null, $phrase, $gap);
    $gaps = new GapFillBuilder;

    return new RunPoolWord($material, null, $phrase ? $gaps->inPhrase($text, $gap) : $gaps->fromText($text, $material->example), PairsMode::candidate($material, LocaleSet::of('ru')), $tier);
}

/** @return list<RunPoolWord> */
function pgPhrases(int $count): array
{
    $list = [];
    for ($i = 1; $i <= $count; $i++) {
        $gap = 'key'.strtr((string) $i, '0123456789', 'abcdefghij');
        $list[] = pgPoolWord($i, "say the {$gap} now", $gap);
    }

    return $list;
}

it('gives a phrase no spell, masks its key word in itself and expects that word alone', function (): void {
    $composition = RunComposer::compose(new RunPool(LocaleSet::of('ru'), pgPhrases(10)), 'phrase-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', RunLimits::for(RunKind::Stop));

    $kinds = array_map(static fn ($e): string => $e->kind, $composition->elements);
    expect($kinds)->not->toContain('spell')
        ->and($kinds)->toContain('gap')
        ->and($kinds)->toContain('translate_fwd');
    foreach ($composition->elements as $element) {
        if ($element->kind === 'gap') {
            $id = (int) $element->get('word_id');
            $gap = 'key'.strtr((string) $id, '0123456789', 'abcdefghij');
            expect($element->get('masked'))->toBe('say the ____ now')
                ->and($element->get('expected'))->toBe([$gap])
                ->and($element->get('full_text'))->toBe("say the {$gap} now");
        }
    }
});

it('takes the distractors of a phrase among phrases and those of a word among words', function (): void {
    // Two phrases and plenty of words in the pool, a few phrases in the wider tier.
    $words = [];
    for ($i = 1; $i <= 8; $i++) {
        $words[] = pgPoolWord(100 + $i, "wordnum{$i}", null, phrase: false);
    }
    $pool = new RunPool(LocaleSet::of('ru'), [...array_slice(pgPhrases(2), 0, 2), ...$words], [], [
        pgPoolWord(201, 'say the far one now', 'far', tier: RunPoolWord::TIER_PUBLIC),
        pgPoolWord(202, 'say the near one now', 'near', tier: RunPoolWord::TIER_PUBLIC),
    ]);

    foreach (['a', 'b', 'c', 'd'] as $seed) {
        $phraseOptions = RunComposer::distractors($pool->words[0], $pool, "seed-{$seed}");
        $wordOptions = RunComposer::distractors($words[0], $pool, "seed-{$seed}");
        expect(array_map(static fn (RunPoolWord $w): bool => $w->word->phrase, $phraseOptions ?? []))->toBe([true, true, true])
            ->and(array_map(static fn (RunPoolWord $w): bool => $w->word->phrase, $wordOptions ?? []))->toBe([false, false, false]);
    }

    // One phrase alone among words has no three phrases to stand with: no task with options, its gap still stands.
    $lonely = new RunPool(LocaleSet::of('ru'), [pgPhrases(1)[0], ...$words]);
    expect(RunComposer::distractors($lonely->words[0], $lonely, 'seed-a'))->toBeNull();
});

it('starts a run of a stop of the phrase pack: the same seed gives the same run, every task of a phrase is graded as it should', function (): void {
    [$course, $lesson, $phrases] = PhraseKit::import();
    $learner = Learner::factory()->create();
    foreach ($phrases as $phrase) {
        PracticeKit::introduce($learner, $phrase, 'review');
    }
    $builder = app(RunSnapshotBuilder::class);
    $shape = static fn ($snapshot): array => array_map(static fn ($e): array => [
        $e->kind, $e->get('word_id'), $e->get('masked'), is_array($e->get('options')) ? array_map(static fn (array $o): mixed => $o['text'], $e->get('options')) : null,
    ], $snapshot->elements);

    $a = $builder->forStop($learner, $course, $lesson, LocaleSet::of('ru'), 'stable-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $b = $builder->forStop($learner, $course, $lesson, LocaleSet::of('ru'), 'stable-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    expect($shape($a))->toBe($shape($b))
        ->and(array_column($shape($a), 0))->not->toContain('spell');

    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $byId = collect($phrases)->keyBy('id');
    $phraseTexts = collect($phrases)->pluck('word')->all();
    $seenGap = false;
    foreach ($run->snapshot['elements'] as $element) {
        expect($element['kind'])->not->toBe('spell');
        if ($element['kind'] === 'gap') {
            $word = $byId[$element['word_id']];
            expect($element['expected'])->toBe([$word->meta['phrase']['gap']])
                ->and(str_replace('____', $element['correct_text'], $element['masked']))->toBe($word->word);
            $seenGap = true;
        }
        if ($element['kind'] === 'translate_back' || $element['kind'] === 'listen') {
            foreach ($element['options'] as $option) {
                expect($option['text'])->toBeIn($phraseTexts);
            }
        }
    }
    expect($seenGap)->toBeTrue();

    // A gap of a phrase: a typo of the key word is a typo, an inflected form is wrong (no forms of a single word).
    $handler = app(WordTaskHandler::class);
    $gapElement = collect($run->snapshot['elements'])->firstWhere('kind', 'gap');
    $element = SnapshotElement::fromArray($gapElement);
    $state = RunState::fromArray($run->state);
    $expected = $gapElement['expected'][0];
    expect($handler->grade($element, ['text' => $expected], $state)->verdict)->toBe(Verdict::Correct)
        ->and($handler->grade($element, ['text' => $expected.'ed'], $state)->verdict)->not->toBe(Verdict::Correct);
});

it('credits a phrase of a run once, like a word, and a repeated request credits nothing', function (): void {
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.game_enabled' => true,
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
    ]);
    [$course, $lesson, $phrases] = PhraseKit::import();
    $learner = Learner::factory()->create();
    foreach ($phrases as $phrase) {
        PracticeKit::introduce($learner, $phrase, 'review');
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $phrase->lexeme_id, 'confirmed_on' => '2026-10-20']);
    }
    DB::table('word_repetitions')->where('learner_id', $learner->id)->update([
        'state' => 'review', 'repetitions' => 3, 'interval_days' => 8, 'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(),
    ]);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);

    for ($guard = 0; $guard < 20 && ! TaskKind::from(GameKit::current($run)['kind'])->isWordTask(); $guard++) {
        foreach (GameKit::rightAnswers(GameKit::current($run)) as $answer) {
            app(RunService::class)->answer($run, $learner, (string) Str::uuid(), GameKit::current($run)['id'], $answer);
        }
    }
    $element = GameKit::current($run);
    $requestId = (string) Str::uuid();
    $body = app(RunService::class)->answer($run, $learner, $requestId, $element['id'], GameKit::rightAnswers($element)[0])->body;
    $again = app(RunService::class)->answer($run, $learner, $requestId, $element['id'], GameKit::rightAnswers($element)[0])->body;

    expect($element['lexeme_id'])->toBe(Word::query()->whereKey($element['word_id'])->value('lexeme_id'))
        ->and($body['score'] ?? null)->toMatchArray(['kind' => 'long', 'reason' => 'credited', 'week' => 5])
        ->and($again)->toBe($body)
        ->and(ScoreCredit::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('starts a run of phrases with no statement more than a run of words of the same size', function (): void {
    $measure = function (Learner $learner, string $slug, int $lessonId): int {
        $headers = PracticeKit::headers($learner);
        test()->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
        Cache::flush();
        $queries = QueryLog::during(fn () => test()->withHeaders($headers)->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $slug, 'lesson_id' => $lessonId])->assertCreated());

        return count($queries);
    };

    [$wordLearner, $wordCourse, $wordLesson] = GameKit::stop(12);
    $words = $measure($wordLearner, $wordCourse->slug, $wordLesson->id);

    [$course, $lesson, $phrases] = PhraseKit::import();
    $learner = Learner::factory()->create();
    foreach ($phrases as $phrase) {
        PracticeKit::introduce($learner, $phrase, 'review');
    }
    $phraseCount = $measure($learner, $course->slug, $lesson->id);

    expect($phraseCount)->toBe($words)
        ->and($phraseCount)->toBeLessThanOrEqual(24) // BUDGET_GAME_START of GameQueryBudgetTest
        ->and(GameRun::query()->count())->toBe(2);
});

it('marks every task of a phrase with `phrase: true` in its projection, and no task of a word or of an older run', function (): void {
    $handler = app(WordTaskHandler::class);
    $state = RunState::fromArray([]);
    $phrases = RunComposer::compose(new RunPool(LocaleSet::of('ru'), pgPhrases(10)), 'phrase-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', RunLimits::for(RunKind::Stop));
    $words = [];
    for ($i = 1; $i <= 10; $i++) {
        $words[] = pgPoolWord(100 + $i, 'wordnum'.strtr((string) $i, '0123456789', 'abcdefghij'), null, phrase: false);
    }
    $plain = RunComposer::compose(new RunPool(LocaleSet::of('ru'), $words), 'word-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', RunLimits::for(RunKind::Stop));

    expect($phrases->elements)->not->toBe([])->and($plain->elements)->not->toBe([]);
    foreach ($phrases->elements as $element) {
        $projection = $handler->project($element, 'ru', $state);
        // The mark alone: the answer stays where it was (no word, no expected text, no right option).
        expect($element->get('phrase'))->toBeTrue()
            ->and($projection['phrase'] ?? null)->toBeTrue()
            ->and(array_intersect(array_keys($projection), ['word', 'expected', 'correct_text', 'full_text', 'correct_option_id', 'word_id']))->toBe([]);
    }
    foreach ($plain->elements as $element) {
        expect($element->get('phrase'))->toBeNull()
            ->and($handler->project($element, 'ru', $state))->not->toHaveKey('phrase');
    }

    // A task of a phrase of a run started before the mark: no key, read as a word.
    $old = $phrases->elements[0]->toArray();
    unset($old['phrase']);
    expect($handler->project(SnapshotElement::fromArray($old), 'ru', $state))->not->toHaveKey('phrase');
});

it('sends `phrase: true` with the current task of a run of phrases over HTTP', function (): void {
    [$course, $lesson, $phrases] = PhraseKit::import();
    $learner = Learner::factory()->create();
    foreach ($phrases as $phrase) {
        PracticeKit::introduce($learner, $phrase, 'review');
    }

    $started = test()->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated();

    expect($started->json('current.element.phrase'))->toBeTrue();
});

it('grades the gap of a phrase as the training does: the key word, or the whole phrase standing for it (docs/06 §16)', function (Closure $answer, string $verdict): void {
    $composition = RunComposer::compose(new RunPool(LocaleSet::of('ru'), pgPhrases(10)), 'phrase-seed-aaaaaaaaaaaaaaaaaaaaaaaaaaaa', RunLimits::for(RunKind::Stop));
    $gap = collect($composition->elements)->first(static fn (SnapshotElement $e): bool => $e->kind === 'gap') ?? throw new RuntimeException('No gap.');
    $key = (string) $gap->get('expected')[0];
    expect($gap->get('masked'))->toBe('say the ____ now');

    $grade = app(WordTaskHandler::class)->grade($gap, ['text' => $answer($key)], RunState::initial(0));

    expect($grade->verdict->value)->toBe($verdict);
})->with([
    'the key word' => [fn (string $k): string => $k, 'correct'],
    'spaces and case' => [fn (string $k): string => '  '.strtoupper($k).' ', 'correct'],
    'the whole phrase' => [fn (string $k): string => "say the {$k} now", 'correct'],
    'the whole phrase, spaces, case, punctuation' => [fn (string $k): string => 'Say  the '.strtoupper($k).' now!', 'correct'],
    'the whole phrase, another key word' => [fn (string $k): string => 'say the zzzz now', 'wrong'],
    'another context' => [fn (string $k): string => "say a {$k} now", 'wrong'],
]);
