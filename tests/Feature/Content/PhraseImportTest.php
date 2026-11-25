<?php

declare(strict_types=1);

/*
 * F1 docs/35 §3.3 a, §3.4: the import of a phrase pack - `part_of_speech = phrase`, the block `phrase` checked and written to
 * `words.meta.phrase`, every rule of the key word `gap` a readable error with the entry's position, phrases only in a course with
 * `exam_tag = phrases` and nothing else there, a second run unchanged, no statement more than a file of words of the same shape;
 * the catalog lists a phrase course in the section `phrases` and onboarding never recommends one.
 */

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Content\Support\LexemeKey;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Lexeme;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PhraseKit;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/** A phrase course (or a course of words) to import single files into. */
function phiCourse(string $slug = 'phrases-a', ?string $examTag = 'phrases'): Course
{
    return Course::factory()->published()->create(['slug' => $slug, 'exam_tag' => $examTag, 'owner_type' => 'platform']);
}

/**
 * One lesson file with the given entries; an entry is a phrase with a valid block unless it overrides something.
 *
 * @param  list<array<string, mixed>>  $entries
 */
function phiFile(array $entries, string $course = 'phrases-a'): string
{
    return json_encode([
        'course' => $course,
        'stage' => ['number' => 1, 'title' => 'Согласие'],
        'lesson' => ['number' => 1, 'title' => 'Да и нет'],
        'words' => $entries,
    ], JSON_THROW_ON_ERROR);
}

/** @param  array<string, mixed>  $phrase  merged into the block; a key set to null removes it */
function phiEntry(string $text = 'easy come, easy go', array $phrase = [], array $entry = []): array
{
    $block = array_filter([...['kind' => 'proverb', 'register' => 'informal', 'gap' => 'go'], ...$phrase], fn (mixed $v): bool => $v !== null);

    return [...['word' => $text, 'part_of_speech' => 'phrase', 'translations' => ['ru' => "смысл: {$text}", 'uz' => "ma'no: {$text}"], 'phrase' => $block], ...$entry];
}

it('imports the phrase pack: the block in the meta, the lexemes, the course in the section of phrases', function (): void {
    [$course, $lesson, $words] = PhraseKit::import();

    expect($course->exam_tag)->toBe('phrases')
        ->and($words)->toHaveCount(12)
        ->and(collect($words)->pluck('part_of_speech')->unique()->all())->toBe(['phrase']);

    $first = collect($words)->firstWhere('word', 'easy come, easy go');
    expect($first->meta['phrase'])->toBe([
        'kind' => 'proverb', 'register' => 'informal', 'variant' => 'both', 'gap' => 'go',
        'literal' => ['ru' => 'легко пришло, легко ушло'], 'cefr' => 'B1', 'frequency' => 2, 'synonyms' => ['win some, lose some'],
    ]);
    expect(collect($words)->firstWhere('word', 'see you later')->meta['phrase']['variant'])->toBe('uk');

    // Each phrase is a lexeme of its own spelling (docs/32 §2.1): "a piece of cake" is the lexeme "piece of cake".
    $cake = collect($words)->firstWhere('word', 'a piece of cake');
    expect(Lexeme::query()->whereKey($cake->lexeme_id)->value('key'))->toBe('piece of cake')
        ->and(collect($words)->pluck('lexeme_id')->filter()->unique())->toHaveCount(12);

    // A second run of the same pack changes nothing.
    $before = Word::query()->orderBy('id')->get(['id', 'meta', 'updated_at'])->toArray();
    $again = app(CoursePackImporter::class)->import(base_path(PhraseKit::DIR), create: true);
    expect($again->ok())->toBeTrue()
        ->and($again->files['m01-l1.json']->skipped)->toBe(12)
        ->and($again->files['m01-l1.json']->updated)->toBe(0)
        ->and(Word::query()->orderBy('id')->get(['id', 'meta', 'updated_at'])->toArray())->toBe($before);
});

it('refuses every broken key word with the position of the entry and a sentence in Russian', function (string $text, array $phrase, string $cause): void {
    phiCourse();
    $report = app(VocabularyImporter::class)->importAsSystem(phiFile([phiEntry('no worries', ['gap' => 'worries']), phiEntry($text, $phrase)]));

    expect($report->aborted)->toBeTrue()
        ->and(Word::query()->count())->toBe(0);
    $issue = collect($report->issues)->firstWhere('code', ImportIssue::INVALID_PHRASE);
    expect($issue)->not->toBeNull()
        ->and($issue->context['cause'])->toBe($cause)
        ->and($issue->context['position'])->toBe(2)
        ->and($issue->context['word'])->toBe($text);

    $russian = AdminErrors::importIssue($issue);
    expect($russian)->toContain('№2')->toContain($text)
        ->and($russian)->not->toBe(__('admin.common.errors.unknown'));
})->with([
    'not in the phrase' => ['break the ice', ['gap' => 'water'], ImportIssue::CAUSE_PHRASE_GAP_MISSING],
    'a part of a word only' => ['break the ice', ['gap' => 'brea'], ImportIssue::CAUSE_PHRASE_GAP_MISSING],
    'twice' => ['easy come, easy go', ['gap' => 'easy'], ImportIssue::CAUSE_PHRASE_GAP_REPEATED],
    'empty' => ['break the ice', ['gap' => '  '], ImportIssue::CAUSE_PHRASE_GAP_EMPTY],
    'absent' => ['break the ice', ['gap' => null], ImportIssue::CAUSE_PHRASE_GAP_EMPTY],
    'the whole phrase' => ['Cheers!', ['gap' => 'cheers'], ImportIssue::CAUSE_PHRASE_GAP_WHOLE],
    'two words' => ['break the ice', ['gap' => 'the ice'], ImportIssue::CAUSE_PHRASE_GAP_NOT_WORD],
    'an apostrophe' => ["don't worry", ['gap' => "don't"], ImportIssue::CAUSE_PHRASE_GAP_NOT_WORD],
    'inside a contraction' => ["How's it going?", ['gap' => 'how'], ImportIssue::CAUSE_PHRASE_GAP_MISSING],
    'a kind outside the list' => ['break the ice', ['gap' => 'ice', 'kind' => 'saying'], ImportIssue::CAUSE_PHRASE_FIELD],
    'no register' => ['break the ice', ['gap' => 'ice', 'register' => null], ImportIssue::CAUSE_PHRASE_FIELD],
    'a variant outside the list' => ['break the ice', ['gap' => 'ice', 'variant' => 'au'], ImportIssue::CAUSE_PHRASE_FIELD],
    'a frequency outside 1-3' => ['break the ice', ['gap' => 'ice', 'frequency' => 4], ImportIssue::CAUSE_PHRASE_FIELD],
    'a level outside A1-C2' => ['break the ice', ['gap' => 'ice', 'cefr' => 'D1'], ImportIssue::CAUSE_PHRASE_FIELD],
    'synonyms not a list' => ['break the ice', ['gap' => 'ice', 'synonyms' => 'melt it'], ImportIssue::CAUSE_PHRASE_FIELD],
    'a typo in a field' => ['break the ice', ['gap' => 'ice', 'regster' => 'neutral'], ImportIssue::CAUSE_PHRASE_UNKNOWN_FIELD],
]);

it('refuses a phrase without its block, a block on a word and a literal in an unsupported language', function (): void {
    phiCourse();
    phiCourse('words-a', 'general');
    $importer = app(VocabularyImporter::class);

    $missing = $importer->importAsSystem(phiFile([['word' => 'break the ice', 'part_of_speech' => 'phrase', 'translations' => ['ru' => 'разрядить обстановку']]]));
    $onWord = $importer->importAsSystem(phiFile([['word' => 'ice', 'part_of_speech' => 'noun', 'translation' => 'лёд', 'phrase' => ['kind' => 'idiom', 'register' => 'neutral', 'gap' => 'ice']]], 'words-a'));
    $literal = $importer->importAsSystem(phiFile([phiEntry('break the ice', ['gap' => 'ice', 'literal' => ['ru' => 'сломать лёд', 'de' => 'das Eis brechen']])]));

    expect($missing->issues[0]->context['cause'] ?? null)->toBe(ImportIssue::CAUSE_PHRASE_MISSING)
        ->and($onWord->issues[0]->context['cause'] ?? null)->toBe(ImportIssue::CAUSE_PHRASE_NOT_PHRASE)
        ->and($literal->issues[0]->code ?? null)->toBe(ImportIssue::UNSUPPORTED_LOCALE)
        ->and($literal->issues[0]->context['field'] ?? null)->toBe('phrase.literal')
        ->and(Word::query()->count())->toBe(0);
});

it('keeps phrases to the phrase courses and the phrase courses to phrases', function (): void {
    phiCourse();
    phiCourse('words-a', 'general');
    $importer = app(VocabularyImporter::class);

    $phraseInWords = $importer->importAsSystem(phiFile([phiEntry()], 'words-a'));
    $wordInPhrases = $importer->importAsSystem(phiFile([phiEntry(), ['word' => 'ice', 'part_of_speech' => 'noun', 'translation' => 'лёд']]));
    $untagged = $importer->importAsSystem(phiFile([['word' => 'ice', 'translation' => 'лёд']]));

    expect($phraseInWords->issues[0]->context)->toMatchArray(['cause' => ImportIssue::CAUSE_PHRASE_NOT_PHRASE_COURSE, 'position' => 1, 'slug' => 'words-a'])
        ->and($wordInPhrases->issues[0]->context)->toMatchArray(['cause' => ImportIssue::CAUSE_PHRASE_COURSE_WORD, 'position' => 2, 'word' => 'ice'])
        ->and($untagged->issues[0]->context['cause'] ?? null)->toBe(ImportIssue::CAUSE_PHRASE_COURSE_WORD)
        ->and(AdminErrors::importIssue($phraseInWords->issues[0]))->toContain('words-a')
        ->and(Word::query()->count())->toBe(0);
});

it('rewrites the block of a changed phrase and drops it when the entry stops being a phrase', function (): void {
    phiCourse();
    $importer = app(VocabularyImporter::class);
    $importer->importAsSystem(phiFile([phiEntry('break the ice', ['gap' => 'ice', 'kind' => 'idiom', 'register' => 'neutral', 'cefr' => 'B1'])]));
    $word = Word::query()->sole();
    expect($word->meta['phrase'])->toBe(['kind' => 'idiom', 'register' => 'neutral', 'variant' => 'both', 'gap' => 'ice', 'cefr' => 'B1']);

    // The key word changes: the block is rewritten whole (cefr gone with it), the import reports an update.
    $report = $importer->importAsSystem(phiFile([phiEntry('break the ice', ['gap' => 'break', 'kind' => 'idiom', 'register' => 'neutral'])]));
    expect($report->updated)->toBe(1)
        ->and($word->refresh()->meta['phrase'])->toBe(['kind' => 'idiom', 'register' => 'neutral', 'variant' => 'both', 'gap' => 'break']);

    // The course becomes a course of words in the panel: the same entry as a noun loses the block, the rest of the meta stays.
    Course::query()->where('slug', 'phrases-a')->update(['exam_tag' => 'general']);
    Word::query()->whereKey($word->id)->update(['meta' => json_encode(['phrase' => $word->meta['phrase'], 'confusable_with' => ['melt']])]);
    $importer->importAsSystem(phiFile([['word' => 'break the ice', 'part_of_speech' => 'verb', 'translation' => 'разрядить обстановку']]));
    expect($word->refresh()->meta)->toBe(['confusable_with' => ['melt']])
        ->and($word->part_of_speech)->toBe('verb');
});

it('costs no statement more than a file of words of the same shape: the phrase checks are PHP, the block rides in the insert', function (): void {
    phiCourse();
    phiCourse('words-a', 'general');
    $file = PhraseKit::lessonFile();
    $phrases = $file['words'];
    $words = array_map(static function (array $entry): array {
        unset($entry['phrase']);

        return ['part_of_speech' => 'noun'] + $entry;
    }, $phrases);
    $importer = app(VocabularyImporter::class);

    $phraseQueries = QueryLog::during(fn () => $importer->importAsSystem(phiFile($phrases)));
    $wordQueries = QueryLog::during(fn () => $importer->importAsSystem(phiFile($words, 'words-a')));

    expect(Word::query()->count())->toBe(24)
        ->and(count($phraseQueries))->toBe(count($wordQueries))
        // The lexemes of the file: one read and one insert for all its entries, as for words (LexemeCatalogTest).
        ->and(QueryLog::matching($phraseQueries, '/from ["`]lexemes["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($phraseQueries, '/^insert .*into ["`]lexemes["`]/'))->toHaveCount(1);

    // A second run of the phrase file: as cheap as a second run of the word file.
    $phraseAgain = QueryLog::during(fn () => $importer->importAsSystem(phiFile($phrases)));
    $wordAgain = QueryLog::during(fn () => $importer->importAsSystem(phiFile($words, 'words-a')));
    expect(count($phraseAgain))->toBe(count($wordAgain));
});

it('lists a phrase course in the section of phrases and never recommends it as the main course', function (): void {
    [$phrases] = PhraseKit::import();
    $general = phiCourse('english-general', 'general');
    Word::factory()->for(Lesson::factory()->for(Stage::factory()->for($general)))->create(['word' => 'apple']);
    app(CoursePublisher::class)->recount($general);

    $catalog = app(CourseCatalogService::class);
    expect($catalog->recommend('phrases', 'B1'))->toBeNull()
        ->and($catalog->recommend('general', 'B1')?->slug)->toBe('english-general');

    $learner = Learner::factory()->onboarded()->create();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $courses = collect($this->withHeaders($headers)->getJson('/api/twa/courses')->assertOk()->json('courses'))->keyBy('slug');
    expect($courses[PhraseKit::SLUG]['section'])->toBe('phrases')
        ->and($courses[PhraseKit::SLUG]['exam_tag'])->toBe('phrases')
        ->and($courses['english-general']['section'])->toBe('courses');

    $this->withHeaders($headers)->getJson('/api/twa/courses/'.PhraseKit::SLUG)->assertOk()->assertJsonPath('section', 'phrases');

    // The lexeme of a phrase is its own: no single word of the phrase shares it.
    expect(LexemeKey::of('a piece of cake'))->toBe(Lexeme::query()->whereKey(Word::query()->where('word', 'a piece of cake')->value('lexeme_id'))->value('key'));
});
