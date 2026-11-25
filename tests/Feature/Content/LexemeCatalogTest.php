<?php

declare(strict_types=1);

/*
 * The catalog of lexemes (docs/32 §2.1, S1): the words of platform and organisation courses create their lexeme, the words of a
 * learner's deck only match one; the copies of one spelling in different courses share it; a renamed word moves to the lexeme of the
 * new spelling; `lexemes:backfill` links the words written before the catalog, in batches, idempotently.
 */

use App\Domain\Content\DTO\DeckRow;
use App\Domain\Content\Services\LearnerDeckService;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\LexemeCatalog;
use App\Domain\Content\Services\VocabularyImporter;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Lexeme;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/** @param  list<string>  $words */
function lexImportJson(array $words, ?string $course = null, int $lesson = 1): string
{
    return json_encode(array_filter([
        'course' => $course,
        'stage' => ['number' => 1, 'title' => 'Beginner'],
        'lesson' => ['number' => $lesson, 'title' => "L{$lesson}"],
        'words' => array_map(fn (string $w): array => ['word' => $w, 'translation' => "т-{$w}"], $words),
    ], fn (mixed $v): bool => $v !== null), JSON_THROW_ON_ERROR);
}

function lexWord(string $text, ?Course $course = null): Word
{
    $course ??= Course::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->for($course))->create();

    return Word::factory()->for($lesson)->create(['word' => $text]);
}

function lexemeOf(Word $word): ?string
{
    $id = Word::query()->whereKey($word->id)->value('lexeme_id');

    return $id === null ? null : Lexeme::query()->whereKey($id)->value('key');
}

it('creates the lexemes of an imported platform course and shares them with another course', function (): void {
    $importer = app(VocabularyImporter::class);
    expect($importer->importAsSystem(lexImportJson(['Apple', 'the Internet', 'to be']))->ok())->toBeTrue();

    $org = Course::factory()->create(['slug' => 'org-course', 'owner_type' => 'organization', 'owner_id' => 1]);
    expect($importer->importAsSystem(lexImportJson(['apple.', 'be'], 'org-course'))->ok())->toBeTrue();

    expect(Lexeme::query()->orderBy('key')->pluck('key')->all())->toBe(['apple', 'be', 'internet', 'to be'])
        ->and(Lexeme::query()->pluck('language')->unique()->all())->toBe(['en'])
        ->and(Word::query()->whereNull('lexeme_id')->count())->toBe(0);

    $apples = Word::query()->whereIn('word', ['Apple', 'apple.'])->pluck('lexeme_id')->unique();
    expect($apples)->toHaveCount(1)
        ->and(Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $org->id))->count())->toBe(2);
});

it('links on a re-import a word written before the catalog without reporting it as updated', function (): void {
    $importer = app(VocabularyImporter::class);
    $importer->importAsSystem(lexImportJson(['hello', 'world']));
    Word::query()->update(['lexeme_id' => null]);

    $report = $importer->importAsSystem(lexImportJson(['hello', 'world']));

    expect($report->skipped)->toBe(2)
        ->and($report->updated)->toBe(0)
        ->and(Word::query()->whereNull('lexeme_id')->count())->toBe(0);
});

it('resolves the lexemes of a file with two statements and none when nothing changed', function (): void {
    $importer = app(VocabularyImporter::class);
    $importer->importAsSystem(lexImportJson(['one', 'two', 'three']));

    $queries = QueryLog::during(fn () => $importer->importAsSystem(lexImportJson(['one', 'two', 'three', 'four'])));

    expect(QueryLog::matching($queries, '/^insert .*into ["`]lexemes["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]lexemes["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^update ["`]?words["`]? set ["`]?lexeme_id/i'))->toHaveCount(0);
});

it('only matches the catalog for the words of a learner deck', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    lexWord('apple');
    app(LexemeCatalog::class)->backfill();

    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $decks->addWords($learner, $deck, [new DeckRow('Apple', 'яблоко'), new DeckRow('zyxword', 'нечто')]);

    expect(Lexeme::query()->count())->toBe(1)
        ->and(lexemeOf(Word::query()->where('word', 'Apple')->sole()))->toBe('apple')
        ->and(Word::query()->where('word', 'zyxword')->value('lexeme_id'))->toBeNull();
});

it('matches a revived deck word to the catalog that grew meanwhile', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $decks->addWords($learner, $deck, [new DeckRow('pear', 'груша')]);
    $pear = Word::query()->where('word', 'pear')->sole();
    $decks->removeWord($learner, $deck, $pear->id);
    expect($pear->fresh()->lexeme_id)->toBeNull();

    // The catalog learns the word (a course word, linked on its own); the removed deck word is still unlinked.
    app(LexemeCatalog::class)->ensureFor(lexWord('pear'));
    expect($pear->fresh()->lexeme_id)->toBeNull();

    $decks->addWords($learner, $deck, [new DeckRow('pear', 'груша')]);

    expect(lexemeOf($pear))->toBe('pear')
        ->and($pear->fresh()->is_active)->toBeTrue();
});

it('keeps the deck import within one more statement for the catalog', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $decks = app(LearnerDeckService::class);
    $deck = $decks->create($learner, 'Мои слова');
    $rows = array_map(fn (int $i): DeckRow => new DeckRow("w{$i}x", "слово{$i}"), range(1, 40));

    $queries = QueryLog::during(fn () => $decks->addWords($learner, $deck->fresh(), $rows));

    expect(QueryLog::matching($queries, '/from ["`]lexemes["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/into ["`]lexemes["`]/'))->toHaveCount(0);
});

it('links a word added in the admin panel and moves it when its spelling is edited', function (): void {
    $refresher = app(LessonContentRefresher::class);
    $word = lexWord('Cat');
    expect($word->lexeme_id)->toBeNull();

    $refresher->wordCreated($word);
    expect(lexemeOf($word))->toBe('cat');

    $word->update(['word' => 'Dog']);
    $refresher->wordEdited($word);
    expect(lexemeOf($word))->toBe('dog')
        ->and(Lexeme::query()->pluck('key')->sort()->values()->all())->toBe(['cat', 'dog']);

    // Another field: the lexeme stays, nothing is looked up.
    $word->update(['translation' => 'собака']);
    $queries = QueryLog::during(fn () => $refresher->wordEdited($word));
    expect(QueryLog::matching($queries, '/lexeme/'))->toBe([])
        ->and(lexemeOf($word))->toBe('dog');
});

it('only matches an admin edit of a deck word', function (): void {
    $learner = Learner::factory()->create();
    $deckWord = lexWord('rare', Course::factory()->deckOf($learner->id)->create());

    app(LessonContentRefresher::class)->wordCreated($deckWord);
    expect($deckWord->fresh()->lexeme_id)->toBeNull()
        ->and(Lexeme::query()->count())->toBe(0);

    lexWord('rare');
    app(LexemeCatalog::class)->backfill();
    expect(lexemeOf($deckWord))->toBe('rare');

    // Renamed to a spelling the catalog does not know: the old link is dropped.
    $deckWord->update(['word' => 'rarest']);
    app(LessonContentRefresher::class)->wordEdited($deckWord);
    expect($deckWord->fresh()->lexeme_id)->toBeNull();
});

it('does not move updated_at of a word it links', function (): void {
    $word = lexWord('owl');
    Word::query()->whereKey($word->id)->update(['updated_at' => '2026-01-01 00:00:00']);

    app(LexemeCatalog::class)->syncFor($word->fresh());

    expect((string) Word::query()->toBase()->where('id', $word->id)->value('updated_at'))->toStartWith('2026-01-01')
        ->and(lexemeOf($word))->toBe('owl');
});

it('backfills in batches: courses create, decks match, a second run changes nothing', function (): void {
    $learner = Learner::factory()->create();
    $deck = Course::factory()->deckOf($learner->id)->create();
    $org = Course::factory()->create(['owner_type' => 'organization', 'owner_id' => 7]);

    // The deck word has a LOWER id than the course word it matches: the second pass must still find it.
    $deckWord = lexWord('Sun', $deck);
    $unknown = lexWord('qwertyish', $deck);
    $words = [lexWord('the sun'), lexWord('moon'), lexWord('star', $org), lexWord('moon', $org)];

    $report = app(LexemeCatalog::class)->backfill(batch: 2);

    expect($report->catalogWordsLinked)->toBe(4)
        ->and($report->lexemesCreated)->toBe(3)
        ->and($report->deckWordsLinked)->toBe(1)
        ->and($report->unmatched)->toBe(1)
        ->and(lexemeOf($deckWord))->toBe('sun')
        ->and(lexemeOf($unknown))->toBeNull()
        ->and(array_map('lexemeOf', $words))->toBe(['sun', 'moon', 'star', 'moon']);

    $again = app(LexemeCatalog::class)->backfill(batch: 2);
    expect($again->catalogWordsLinked + $again->deckWordsLinked + $again->lexemesCreated)->toBe(0)
        ->and($again->unmatched)->toBe(1)
        ->and(Lexeme::query()->count())->toBe(3);
});

it('never overwrites a link a writer made meanwhile', function (): void {
    $word = lexWord('river');
    $other = Lexeme::factory()->create(['key' => 'bank']);

    expect(app(LexemeCatalog::class)->link([$word->id => $other->id], onlyUnlinked: true))->toBe(1)
        ->and(app(LexemeCatalog::class)->link([$word->id => null], onlyUnlinked: true))->toBe(0)
        ->and($word->fresh()->lexeme_id)->toBe($other->id);
});

it('links only the deck words with --decks', function (): void {
    $learner = Learner::factory()->create();
    $deckWord = lexWord('tree', Course::factory()->deckOf($learner->id)->create());
    $courseWord = lexWord('tree');
    Lexeme::factory()->create(['key' => 'tree']);

    $this->artisan('lexemes:backfill', ['--decks' => true])
        ->expectsOutputToContain('deck words linked: 1')
        ->assertSuccessful();

    expect(lexemeOf($deckWord))->toBe('tree')
        ->and($courseWord->fresh()->lexeme_id)->toBeNull();

    $this->artisan('lexemes:backfill')->expectsOutputToContain('Course words linked: 1, lexemes created: 0')->assertSuccessful();
    expect(lexemeOf($courseWord))->toBe('tree');
});

it('refuses a batch size below one', function (): void {
    $this->artisan('lexemes:backfill', ['--batch' => 0])->assertFailed();
});

it('drops the link when the lexeme is deleted by hand', function (): void {
    $word = lexWord('lamp');
    app(LexemeCatalog::class)->syncFor($word);

    DB::table('lexemes')->delete();

    expect($word->fresh()->lexeme_id)->toBeNull();
});
