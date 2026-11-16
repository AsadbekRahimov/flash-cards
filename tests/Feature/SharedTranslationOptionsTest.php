<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Content\Support\TranslationParts;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Two words with one translation in the learner's language are both right answers to the same question (docs/06 §12.2):
 * he / she / it are all "u" in uz, it / this are "bu", watch / look are "смотреть". The four-option exercises never put the
 * second one among the options, whatever the seed, and a learner who picks it anyway (a card built before the rule) is
 * not failed for it. When the sense check leaves fewer than three distractors the card falls back to flash, as always.
 */

const ST_SEEDS = 40;

/** @return array<string, string> uz translation by English word, from the delivered lesson file */
function stUzLesson(string $file): array
{
    /** @var array{words: list<array{word: string, translation: string, translations: array<string, string>, example: string}>} $data */
    $data = json_decode((string) file_get_contents(base_path("database/data/courses/english-a1-a2/{$file}.json")), true, 512, JSON_THROW_ON_ERROR);
    $uz = [];
    foreach ($data['words'] as $w) {
        $uz[$w['word']] = $w['translations']['uz'];
    }

    return $uz;
}

/**
 * A published public course with lessons of the delivered A1 course (real ru and uz translations), keyed by file name.
 *
 * @param  list<string>  $files
 * @return array{course: Course, lessons: array<string, Lesson>, words: array<string, array<string, Word>>}
 */
function stCourse(array $files): array
{
    $course = Course::factory()->published()->create(['visibility' => 'public']);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lessons = [];
    $words = [];
    foreach ($files as $n => $file) {
        /** @var array{words: list<array{word: string, translation: string, translations: array<string, string>, example: string}>} $data */
        $data = json_decode((string) file_get_contents(base_path("database/data/courses/english-a1-a2/{$file}.json")), true, 512, JSON_THROW_ON_ERROR);
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $n + 1]);
        $lessons[$file] = $lesson;
        foreach ($data['words'] as $w) {
            $word = Word::factory()->for($lesson)->create(['word' => $w['word'], 'translation' => $w['translation'], 'example' => $w['example']]);
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => $w['translations']['uz']]);
            $words[$file][$w['word']] = $word;
        }
    }

    return ['course' => $course, 'lessons' => $lessons, 'words' => $words];
}

/**
 * Factory words of one lesson: `english => [ru, uz]`.
 *
 * @param  array<string, array{0: string, 1: string}>  $spec
 * @return array{course: Course, lesson: Lesson, words: array<string, Word>}
 */
function stLesson(array $spec, ?Course $course = null, int $number = 1): array
{
    $course ??= Course::factory()->published()->create(['visibility' => 'public']);
    $stage = Stage::query()->where('course_id', $course->id)->first() ?? Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $number]);
    $words = [];
    foreach ($spec as $english => [$ru, $uz]) {
        $word = Word::factory()->for($lesson)->create(['word' => $english, 'translation' => $ru, 'example' => "I say {$english} now."]);
        WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => $uz]);
        $words[$english] = $word;
    }

    return ['course' => $course, 'lesson' => $lesson, 'words' => $words];
}

/** The options a card of the word gets with this seed, or null when the builder fell back to flash. @return list<string>|null */
function stOptions(ExerciseType $type, Word $word, string $locale, int $seed): ?array
{
    $builder = app(ExercisePromptBuilder::class);
    $translation = app(WordTranslationResolver::class)->resolve($word, $locale);
    $prompt = $builder->build($type, $word, $translation, $locale, null, $seed);

    expect($builder->effectiveType($type, $word, $locale, $seed))->toBe($prompt->type);

    return $prompt->type === $type ? $prompt->options : null;
}

/** @return array<string, string> option text (lower) => its English word, for the words given */
function stByText(array $words, ExerciseType $type, string $locale): array
{
    $map = [];
    foreach ($words as $english => $word) {
        $text = $type === ExerciseType::Reverse ? $english : app(WordTranslationResolver::class)->resolve($word, $locale)->text;
        $map[mb_strtolower(trim($text))] = $english;
    }

    return $map;
}

function stHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** A due review card of the learner on the form rung (its list reverse, audio, flash: after a flash the reverse comes next), served in a daily session. */
function stDue(Learner $learner, Word $word, string $lastExercise = 'flash'): WordRepetition
{
    return WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value,
        'repetitions' => 3, 'interval_days' => 2, 'next_review_at' => now()->subHour(), 'introduced_mode' => 'daily_review',
        'is_hard' => false, 'lapses' => 0, 'meta' => [LadderPolicy::LAST_EXERCISE_META => $lastExercise], 'served_ahead_token' => null,
        'created_at' => now()->subDays(3),
    ]);
}

// --- the delivered data: lesson 2 of A1 in uz -------------------------------------------------------

it('never offers he, she or it against each other in uz: no reverse card of lesson 2 has two right options, whatever the seed', function (): void {
    ['words' => $all] = stCourse(['m01-l2']);
    $words = $all['m01-l2'];
    $uz = stUzLesson('m01-l2');
    $byText = stByText($words, ExerciseType::Reverse, 'uz');

    $built = 0;
    foreach ($words as $english => $word) {
        $senses = TranslationParts::keys($uz[$english]);
        for ($seed = 0; $seed < ST_SEEDS; $seed++) {
            $options = stOptions(ExerciseType::Reverse, $word, 'uz', $seed);
            if ($options === null) {
                continue;
            }
            $built++;
            expect($options)->toHaveCount(4)->toContain($english);
            foreach ($options as $option) {
                $other = $byText[mb_strtolower($option)] ?? null;
                expect($other)->not->toBeNull("{$option} is not a word of the lesson");
                if ($other === $english) {
                    continue;
                }
                expect(array_intersect($senses, TranslationParts::keys($uz[$other])))->toBe([], "{$english} ({$uz[$english]}) with seed {$seed} offers {$other} ({$uz[$other]})");
            }
        }
    }
    // The lesson has ten words: every card could be built (at least seven candidates remain after the sense check).
    expect($built)->toBe(count($words) * ST_SEEDS);

    // The pair in question: never she or it against he, never he / she / this against it.
    for ($seed = 0; $seed < ST_SEEDS; $seed++) {
        expect(stOptions(ExerciseType::Reverse, $words['he'], 'uz', $seed))->not->toContain('she')->not->toContain('it')
            ->and(stOptions(ExerciseType::Reverse, $words['it'], 'uz', $seed))->not->toContain('he')->not->toContain('she')->not->toContain('this');
    }
});

it('offers "it" (u, bu) its meaning once in uz: neither "u" of he / she nor "bu" of this among the four, and no two options with one sense', function (): void {
    ['words' => $all] = stCourse(['m01-l2']);
    $words = $all['m01-l2'];

    for ($seed = 0; $seed < ST_SEEDS; $seed++) {
        foreach ([ExerciseType::Meaning, ExerciseType::Audio] as $type) {
            $options = stOptions($type, $words['it'], 'uz', $seed);
            expect($options)->toHaveCount(4)->toContain('u, bu');

            $seen = [];
            foreach ($options as $option) {
                foreach (TranslationParts::keys($option) as $sense) {
                    expect($seen)->not->toHaveKey($sense, "seed {$seed}: the sense '{$sense}' twice among ".implode(' | ', $options));
                    $seen[$sense] = true;
                }
            }
        }
    }
});

it('serves a uz learner reverse cards of he / she / it / this with one right option each, over HTTP', function (): void {
    ['words' => $all] = stCourse(['m01-l2']);
    $words = $all['m01-l2'];
    $uz = stUzLesson('m01-l2');
    $learner = Learner::factory()->create(['interface_locale' => 'uz', 'active_course_id' => null, 'timezone' => 'Asia/Tashkent']);
    foreach (['he', 'she', 'it', 'this'] as $english) {
        stDue($learner, $words[$english]);
    }
    $headers = stHeaders($learner);

    $response = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $sessionId = (int) $response['session_id'];
    $served = [];
    for ($i = 0; $i < 4 && $response['card'] !== null; $i++) {
        $card = $response['card'];
        $english = array_search((int) $card['word_id'], array_map(static fn (Word $w): int => (int) $w->id, $words), true);
        expect($card['prompt']['type'])->toBe('reverse')
            ->and($card['prompt']['front'])->toBe($uz[$english])
            ->and($card['prompt']['options'])->toHaveCount(4)->toContain($english);
        foreach ($card['prompt']['options'] as $option) {
            if ($option !== $english) {
                expect(array_intersect(TranslationParts::keys($uz[$english]), TranslationParts::keys($uz[$option])))->toBe([]);
            }
        }
        $served[] = $english;
        $response = $this->withHeaders($headers)
            ->postJson("/api/twa/training/sessions/{$sessionId}/review", ['word_id' => $card['word_id'], 'answer' => $english, 'exercise' => 'reverse', 'time_spent_ms' => 500, 'serve_token' => $card['serve_token']])
            ->assertOk()->assertJsonPath('graded.correct', true)->json();
    }
    expect(array_values(array_unique($served)))->toHaveCount(4);
});

// --- ru pairs and factory data -------------------------------------------------------------------------

it('keeps watch and look (both "смотреть") apart in ru: neither as the other\'s reverse option, nor "смотреть, выглядеть" as a meaning option of watch', function (): void {
    ['words' => $words] = stLesson([
        'watch' => ['смотреть, наблюдать', 'qaramoq'], 'look' => ['смотреть, выглядеть', "ko'rinmoq"],
        'answer' => ['отвечать', 'javob bermoq'], 'reply' => ['отвечать; ответ', 'javob'],
        'home' => ['дом', 'uy'], 'house' => ['дом, здание', 'uy'], 'cat' => ['кошка', 'mushuk'], 'dog' => ['собака', 'it'],
        'milk' => ['молоко', 'sut'], 'bread' => ['хлеб', 'non'],
    ]);

    for ($seed = 0; $seed < ST_SEEDS; $seed++) {
        expect(stOptions(ExerciseType::Reverse, $words['watch'], 'ru', $seed))->toHaveCount(4)->not->toContain('look')
            ->and(stOptions(ExerciseType::Reverse, $words['answer'], 'ru', $seed))->toHaveCount(4)->not->toContain('reply')
            ->and(stOptions(ExerciseType::Reverse, $words['house'], 'ru', $seed))->toHaveCount(4)->not->toContain('home')
            ->and(stOptions(ExerciseType::Meaning, $words['watch'], 'ru', $seed))->toHaveCount(4)->not->toContain('смотреть, выглядеть')
            ->and(stOptions(ExerciseType::Meaning, $words['home'], 'ru', $seed))->toHaveCount(4)->not->toContain('дом, здание');
        // In uz the same words are not a pair (qaramoq / ko'rinmoq): `look` is a fair reverse option there.
        $uz = [];
        for ($s = 0; $s < ST_SEEDS; $s++) {
            $uz = [...$uz, ...(stOptions(ExerciseType::Reverse, $words['watch'], 'uz', $s) ?? [])];
        }
        expect($uz)->toContain('look');
    }
});

it('falls back to flash for a deck whose words all share one translation, in the builder and on the card', function (): void {
    $spec = [];
    foreach (['home', 'house', 'building', 'flat', 'cottage', 'hut', 'villa', 'palace'] as $english) {
        $spec[$english] = ['дом', 'uy'];
    }
    ['words' => $words] = stLesson($spec);
    $builder = app(ExercisePromptBuilder::class);

    foreach (['ru', 'uz'] as $locale) {
        foreach ([ExerciseType::Reverse, ExerciseType::Meaning, ExerciseType::Audio, ExerciseType::Pretest] as $type) {
            expect(stOptions($type, $words['home'], $locale, 7))->toBeNull()
                ->and($builder->effectiveType($type, $words['home'], $locale, 7))->toBe(ExerciseType::Flash);
        }
    }

    // Over HTTP: the card comes as flash and takes the learner's own grade.
    $learner = Learner::factory()->create(['active_course_id' => null]);
    stDue($learner, $words['home']);
    $headers = stHeaders($learner);
    $response = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    expect($response['card']['prompt']['type'])->toBe('flash')->and($response['card']['prompt']['options'])->toBeNull();
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $words['home']->id, 'quality' => 4, 'exercise' => 'flash', 'time_spent_ms' => 500])
        ->assertOk();
});

it('still finds three distractors from the rest of the course and the platform when the lesson\'s other words all share the sense', function (): void {
    ['course' => $course, 'words' => $words] = stLesson([
        'home' => ['дом', 'uy'], 'house' => ['дом, здание', 'uy'], 'building' => ['здание, дом', 'bino, uy'], 'flat' => ['квартира, дом', 'kvartira, uy'],
    ]);
    stLesson(['cat' => ['кошка', 'mushuk'], 'dog' => ['собака', 'it'], 'milk' => ['молоко', 'sut']], $course, 2);
    stLesson(['bread' => ['хлеб', 'non'], 'tea' => ['чай', 'choy']], Course::factory()->published()->create(['visibility' => 'public']));

    for ($seed = 0; $seed < 10; $seed++) {
        $options = stOptions(ExerciseType::Reverse, $words['home'], 'uz', $seed);
        expect($options)->toHaveCount(4)->toContain('home')->not->toContain('house')->not->toContain('building')->not->toContain('flat');
    }
});

// --- grading a pick by its sense (an old serve) --------------------------------------------------------

it('grades the pick of a word with the same sense as right on reverse, even when it was not among the options', function (): void {
    ['course' => $course, 'words' => $all] = stCourse(['m01-l1', 'm01-l2']);
    $words = $all['m01-l2'];
    $learner = Learner::factory()->create(['interface_locale' => 'uz', 'active_course_id' => null]);
    stDue($learner, $words['he']);
    $headers = stHeaders($learner);

    $response = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $response['card'];
    expect($card['prompt']['type'])->toBe('reverse')->and($card['prompt']['options'])->not->toContain('she');

    // "she" is "u" as well: right, graded 4 like the word itself; the pick is kept as sent.
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $words['he']->id, 'answer' => 'She', 'exercise' => 'reverse', 'time_spent_ms' => 500])
        ->assertOk()->assertJsonPath('graded.correct', true)->assertJsonPath('graded.quality', 4);

    // A word of ANOTHER lesson of the course with the same sense counts too (one query); an unrelated word is wrong.
    $excuse = $all['m01-l1']['excuse'];
    $forgive = stLesson(['forgive' => ['прощать', 'kechirmoq'], 'thank' => ['благодарить', 'rahmat aytmoq']], $course, 3)['words']['forgive'];
    stDue($learner, $excuse);
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $excuse->id, 'answer' => 'forgive', 'exercise' => 'reverse', 'time_spent_ms' => 500])
        ->assertOk()->assertJsonPath('graded.correct', true);
    stDue($learner, $words['you']);
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $words['you']->id, 'answer' => 'he', 'exercise' => 'reverse', 'time_spent_ms' => 500])
        ->assertOk()->assertJsonPath('graded.correct', false)->assertJsonPath('graded.quality', 2);
    expect($forgive->exists)->toBeTrue();
});

it('grades a translation sharing a sense with the right one as right on meaning and audio, a stranger as wrong', function (): void {
    ['words' => $all] = stCourse(['m01-l2']);
    $words = $all['m01-l2'];
    $learner = Learner::factory()->create(['interface_locale' => 'uz', 'active_course_id' => null]);
    // Two passes: the recognition rung, whose list starts with the meaning.
    stDue($learner, $words['it'], 'reverse');
    WordRepetition::query()->where('learner_id', $learner->id)->update(['repetitions' => 2]);
    $headers = stHeaders($learner);

    $response = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    expect($response['card']['prompt']['type'])->toBe('meaning')->and($response['card']['prompt']['options'])->toContain('u, bu');

    // "u" alone (what he / she say) names a sense of "u, bu": right.
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $words['it']->id, 'answer' => 'u', 'exercise' => 'meaning', 'time_spent_ms' => 500])
        ->assertOk()->assertJsonPath('graded.correct', true)->assertJsonPath('graded.quality', 4);

    stDue($learner, $words['you'], 'reverse');
    WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $words['you']->id)->update(['repetitions' => 2]);
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$response['session_id']}/review", ['word_id' => $words['you']->id, 'answer' => 'u, bu', 'exercise' => 'meaning', 'time_spent_ms' => 500])
        ->assertOk()->assertJsonPath('graded.correct', false);
});

it('reads the senses of a translation as the author wrote them: brackets cut, split by comma / semicolon / slash, ё = е, case and spaces ignored', function (): void {
    expect(TranslationParts::keys('Дом (здание), Жильё; кров / очаг'))->toBe(['дом', 'жилье', 'кров', 'очаг'])
        ->and(TranslationParts::keys('u,  bu.'))->toBe(['u', 'bu'])
        ->and(TranslationParts::keys('(разг.)'))->toBe([])
        ->and(TranslationParts::keys(''))->toBe([])
        ->and(TranslationParts::share(['u'], 'U, bu'))->toBeTrue()
        ->and(TranslationParts::share(['u'], 'uy'))->toBeFalse()
        ->and(TranslationParts::share([], 'u'))->toBeFalse();
});
